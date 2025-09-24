<?php

/**
 * The work horse for building SMW queries
 * Build functions: run(), getResultForQuery()
 * Some class setters (setOptions, etc) available for 
 * additional leverage.
 * 
 * @link https://www.semantic-mediawiki.org/wiki/Help:Full-text_search
 * @link https://github.com/SemanticMediaWiki/SemanticMediaWiki/blob/master/includes/query/SMW_QueryProcessor.php
 */

namespace Recon\SMW;

use \Title;
use \MediaWiki\MediaWikiServices;

use \SMWQueryProcessor;
use \SMWRequestOptions;
use \SMW\Query\QueryResult;
use Recon\StringModification\StringModifier;
use Recon\MW\MWUtils;
use Recon\SMW\SMWUtils;
use Recon\SMW\SMWQuerySyntaxConverters;
use Recon\SMW\SMWResultFormatter;
use Recon\SMW\SMWQueryHelperForFTS;
use Recon\Config\ReconConfig;

class SMWQueryBuilder {

	private $smwStore;
	private $maxAutocompleteValues;
	private $substring;
	private $substringUTF8;
	private $substringProcessed;
	private $substringPattern;
	// Unused; 'exactpagename' = simple single page restriction
	private $possibleSubstringPatterns = [ "stringprefix", "tokenprefix", "allchars", "doublequotes", "exact", "exactpagename" ];
	// Bool. Support 6.4: "supplying an entity identifier as prefix should return this entity in the suggest response":
	private $queryEntityIdentifier = true;
	private $concept;
	private $rawQuery;
	// @todo maybe make placeholder configurable
	private $smwqueryPlaceholder = "@@@";
	private $smwDefaultStore;
	private $smwgEnabledFulltextSearch;
	private $smwgFulltextSearchMinTokenSize;
	private $wgReconAPILabelProp = null;
	private $wgReconAPISearchableLabelProp = null;
	private $wgReconAPIDescriptionProp = null;
	private $wgReconAPIThumbnailProp = null;
	private $useDisplayTitle = null;
	private $printoutProperties = [];
	private $classProperty = [];
	private $labelProperty = null;
	private $searchableLabelProperty = null;
	private $descriptionProperty = null;
	private $imageProperty = null;
	// 
	private $profileID;
	private $profilePage;
	private $resultOffset;
	private $resultLimit;
	private $resultbatchcount;
	private $hasFurtherResults;
	private $comment = [];
	private $hideNamespacePrefix = true;

	public function __construct() {
		$this->smwStore = SMWUtils::getSMWStore();
		// wrong place
		if ( !$this->smwStore ) {
			return;
		}
		$this->maxAutocompleteValues = 25; // @todo configure

		$config = MediaWikiServices::getInstance()->getMainConfig();

		// SMW config - makeConfig( ... ) does not work here
		global $smwgEnabledFulltextSearch;
		// smwgEnabledFulltextSearch
		$this->smwgEnabledFulltextSearch = $smwgEnabledFulltextSearch;
		global $smwgFulltextSearchMinTokenSize;
		$this->smwgFulltextSearchMinTokenSize = $smwgFulltextSearchMinTokenSize;
		global $smwgDefaultStore;
		$this->smwDefaultStore = $smwgDefaultStore;
		$this->setOptions( 0, 25 );

		// Get default properties from config.		
		$this->wgReconAPILabelProp = $config->get( "ReconAPILabelProp" );
		$this->wgReconAPISearchableLabelProp = $config->get( "ReconAPISearchableLabelProp" );
		$this->wgReconAPIDescriptionProp = $config->get( "ReconAPIDescriptionProp" );
		$this->wgReconAPIThumbnailProp = $config->get( "ReconAPIThumbnailProp" );

		// Initialise with default properties from config. 
		// May get overruled, ultimately.
		$this->classProperty = $config->get( "ReconAPIClassProp" ) ?? null;
		// displaytitle parameter may be used to override wgReconAPILabelProp later on
		$this->labelProperty = $this->wgReconAPILabelProp;
		$this->searchableLabelProperty = $this->wgReconAPISearchableLabelProp;
		$this->descriptionProperty = $this->wgReconAPIDescriptionProp;
		$this->imageProperty = $this->wgReconAPIThumbnailProp;
	}

	// Some setters before getting to run(), etc.

	public function setOptions(
		$offset = 0,
		$limit = 25,
		$substring = null,
		$substringPattern = null,
		$useDisplayTitle = null,
		$hideNamespacePrefix = null
	) {
		$this->resultOffset = $offset;
		$this->resultLimit = $limit;
		// $this->profileType = "entity";
		if ( $substring !== null ) {
			$this->substring = $substring;
		}
		if ( $substringPattern !== null ) {
			$this->substringPattern = $substringPattern;
		}
		if ( $useDisplayTitle !== null ) {
			$this->useDisplayTitle = $useDisplayTitle;
		}
		if ( $hideNamespacePrefix !== null ) {
			$this->hideNamespacePrefix = $hideNamespacePrefix;
		}		
	}

	/**
	 * Esp. for external use
	 * @param mixed $labelProperty
	 * @param mixed $descriptionProperty
	 * @param mixed $imageProperty
	 * @return void
	 */
	public function setPrintoutProperties(
		$labelProperty = null,
		$descriptionProperty = null,
		$imageProperty = null
	) {
		if ( $labelProperty !== null || $labelProperty === false ) {
			// if label = false, fall back on pagename
			$this->printoutProperties[] = $this->labelProperty = $labelProperty;
		}
		if ( $descriptionProperty !== null ) {
			$this->printoutProperties[] = $this->descriptionProperty = $descriptionProperty;
		}
		if ( $imageProperty !== null ) {
			$this->printoutProperties[] = $this->imageProperty = $imageProperty;
		}
	}

	public function addPrintoutProperties( array $props ) {
		foreach( $props as $prop ) {
			$this->printoutProperties[] = $prop;
		}
	}

	public function getPrintoutProperties() {
		return [ $this->labelProperty, $this->descriptionProperty, $this->imageProperty ];
	}

	/**
	 * Run query
	 * 
	 * @param string $substring
	 * @param mixed $substringPattern
	 * @param mixed $concept - deprecated
	 * @param bool $useDisplayTitle
	 * @param mixed $profileID
	 * @param mixed $rawQuery
	 * @param array $types
	 * @param array $properties
	 * @return array
	 */
	public function run(
		string $substring,
		mixed $substringPattern = null,
		mixed $concept = null,
		mixed $useDisplayTitle = null,
		mixed $profileID = null,
		mixed $rawQuery = null,
		array $types = [],
		array $properties = []
	) {
		if ( $this->smwStore == null ) {
			return [];
		}
		$this->substring = $substring;
		$this->substringPattern = $substringPattern;
		$this->smwqueryPlaceholder = "@@@";
		$this->concept = $concept;
		if ( $useDisplayTitle !== null ) {
			$this->useDisplayTitle = $useDisplayTitle;
			if ( $useDisplayTitle ) {
				$this->labelProperty = $this->useDisplayTitle;
			}
		}

		if ( $this->smwgEnabledFulltextSearch && $this->substringPattern == "stringprefix" ) {
			$this->comment[] = "Warning. Because SQL's full-text search is enabled on the wiki, it does not support 'stringprefix' in combination with the tilde notation, meaning the use of substrings to match only on the beginning of the full string is supported only if the 'like:' notation is used.";
		}

		// Set class props and create raw query for SMW
		if ( isset( $profileID ) ) {
			$smwMethod = "SMW query by JSON profile";
			$rawQuery = $this->setProfileAndGetRawQuery( $profileID, "entity" );
		} elseif ( isset( $this->concept ) ) {
			// @deprecated ?
			$smwMethod = $this->useDisplayTitle ? "SMW query on concept by display title" : "SMW query on concept";
			$rawQuery = $this->getRawQueryForConcept( $this->concept );
		} elseif( isset( $rawQuery ) ) {
			// @todo Not implemented yet
			$smwMethod = "SMW query in URL string";
			//$this->prepareSubstring( $substring );
			$this->substringProcessed = $this->getReplacementString(
				$this->substring,
				$this->substringPattern,
				false, // ?
				false // @todo
			);
			$this->hideNamespacePrefix = true;
			// $rawQuery already set. No need to define it.
		} else {
			$rawTypeQuery = SMWQuerySyntaxConverters::translateTypesToSMWSyntax( $types );
			$rawPropValQuery = SMWQuerySyntaxConverters::translatePropValPairsToSMWSyntax( $properties );
			$queryProp = $this->getQueryProperty( $this->useDisplayTitle );
			$fromQuery = trim($rawTypeQuery . $rawPropValQuery ) == ""
				// @todo should we really restrict to existing pages only?
				? "[[Modification date::+]]"
				: $rawTypeQuery . $rawPropValQuery;
			// A fallback though less than ideal.
			$this->substringProcessed = $this->getReplacementString(
				$this->substring,
				$this->substringPattern,
				false,
				false
			);
			$this->hideNamespacePrefix = true;
			if ( $queryProp === false ) {
				$smwMethod = "SMW query by single page restriction";
				$rawQuery = "{$fromQuery} [[~{$this->substring}]]";
			} else {
				$smwMethod = "SMW query by property '$queryProp'";
				$rawQuery = "{$fromQuery} [[{$queryProp}::~{$this->substringProcessed}]]";
			}
		}

		if ( $this->queryEntityIdentifier ) {
			// @todo remove - we're handling this differently
			// $rawQuery .= " OR [[{$this->substring}]]";
		}
		$this->rawQuery = $rawQuery;
		$queryRes = $this->getResultForQuery( $this->rawQuery );

		/*
		// Formatting
		if ( $queryRes !== null ) {
			$smwResultFormatter = new SMWResultFormatter( $queryRes, $this->substring, $this->hideNamespacePrefix );
			$smwResultFormatter->setPrintoutProperties(
				$this->labelProperty,
				$this->descriptionProperty,
				$this->imageProperty,
				true,
				$this->classProperty
			);
			// @todo set from profile
			$pages = $smwResultFormatter->doFormat();
		} else {
			$pages = [];
		}
		*/
		$pages = $this->formatQueryResult( $queryRes );

		$meta = [
			"service" => "Suggest entities",
			"description" => "Suggest entities in response to a Semantic MediaWiki query.",
			"source" => "smw",
			"smwDefaultStore" => $this->smwDefaultStore,
			"smwgEnabledFulltextSearch" => $this->smwgEnabledFulltextSearch ? 1 : 0,
			"smwgFulltextSearchMinTokenSize" => $this->smwgFulltextSearchMinTokenSize ?? false,
			"smwMethod" => $smwMethod,
			"substring" => $this->substring,
			"substringProcessed" => $this->substringProcessed,
			"substringPattern" => $this->substringPattern,
			"profileID" => $this->profileID ?? false,
			"profilePage" => $this->profilePage ?? false,
			"smwConcept" => $this->concept ?? false,
			"useDisplayTitle" => $this->useDisplayTitle,
			"smwQuery" => $this->rawQuery,
			"defaultProps" => [
				"labelProp" => $this->labelProperty,
				"descriptionProp" => $this->descriptionProperty,
				"imageProp" => $this->imageProperty
			],
			"resultBatchCount" => $this->resultbatchcount,
			"resultLimit" => $this->resultLimit,
			"resultOffset" => $this->resultOffset,
			"nextOffset" => $this->hasFurtherResults ? ( 25 + $this->resultOffset ) : 0
		];

		if ( !empty( $this->comment ) ) {
			$meta["comment"] = $this->comment;
		}

		return [
			"result" => $pages,
			"meta" => $meta
		];
	}

	/**
	 * Format query results given that all the
	 * printout properties, etc., have been set
	 * @param SMW\Query\QueryResult|null $queryRes
	 * @return array
	 */
	public function formatQueryResult( $queryRes ): array {
		if ( $queryRes !== null ) {
			$smwResultFormatter = new SMWResultFormatter( $queryRes, $this->substring, $this->hideNamespacePrefix );
			$smwResultFormatter->setPrintoutProperties(
				$this->labelProperty,
				$this->descriptionProperty,
				$this->imageProperty,
				true,
				$this->classProperty
			);
			// @todo set from profile
			return $smwResultFormatter->doFormat();
		} else {
			return [];
		}
	}

	/**
	 * Set the profile's config data and create 
	 * raw query syntax
	 * @todo suggest services other than 'suggest entity'
	 */
	public function setProfileAndGetRawQuery(
		mixed $profileID,
		string $profileType = "entity"
	) {
		$this->profileID = intval( $profileID );
		$reconConfig = new ReconConfig( $this->profileID );
		$this->profilePage = $reconConfig->getFullPageName();
		$outputPropertyInfo = $reconConfig->getOutputPropertyInfo();

		$profileSchema = $reconConfig->getProfileSchema();
		if ( isset( $profileSchema["suggestEntity"]["output"]["label"]["hideNamespacePrefix"] ) ) {
			$this->hideNamespacePrefix = $profileSchema["suggestEntity"]["output"]["label"]["hideNamespacePrefix"];
		}

		// Query: get the blueprint, should be an array (could be a string?)
		$q = $reconConfig->getSMWQuery();
		// Pattern set in URL takes precedence
		// next, check if it is in our config profile
		// next, if q is array AND 
		if ( $this->substringPattern === null ) {
			$patternFromConfig = $reconConfig->getSubstringPattern();
			if ( $patternFromConfig !== null ) {
				$this->substringPattern = $patternFromConfig;
			} elseif( gettype( $q ) == "array" && !$this->smwgEnabledFulltextSearch ) {
				// not water-proof since (a) 'like:' may be used, 
				// (b) it may be used for prop value arrays
				$this->substringPattern = "allchars";
			} else {
				$this->substringPattern = "tokenprefix";
			}
		}

		// URL parameter for display title may be used to override label set in profile.
		// display title should ONLY override display title if it is set explicitly in the URL
		$labelProperty = $this->useDisplayTitle ? $this->labelProperty : $outputPropertyInfo["label"];
		$this->setPrintoutProperties(
			$labelProperty,
			$outputPropertyInfo["description"],
			$outputPropertyInfo["image"]
		);
		$rawQuery = $this->constructQueryFromConfigProfile( $q );
		// $outputPropertyInfo - formatting options @todo

		return $rawQuery;
	}

	/**
	 * Return an array of the names of pages that are the result of an SMW query.
	 * No 'printout properties' assigned.
	 * @param string
	 * @return array
	 */
	public function getAllPagesForQuery( $rawQuery ) {
		$queryRes = $this->getResultForQuery( $rawQuery );
		$pages = $this->getPagesForQueryResult( $queryRes );
		return $pages;
	}

	/**
	 * Get QueryResult object from query string.
	 * @todo take into account useDisplayTitle
	 * 
	 * @param string $rawQuery the query string like [[Category:Trees]][[age::>1000]]
	 * @return QueryResult|null
	 */
	public function getResultForQuery( $rawQuery ) {
		if ( !$this->smwStore ) {
			return null;
		}
		$rawQueryArr = [
			$rawQuery,
			"named args=true",
			"link=none",
			"offset={$this->resultOffset}",
			"limit={$this->resultLimit}",
			"searchlabel="
		];
		
		// Add printout properties
		$printoutProperties = [
			$this->labelProperty,
			$this->descriptionProperty,
			$this->imageProperty,
			// @todo config for "Is published"
			"Is published",
			// @todo localised name? cf. 'Subcategory of'
			"Category"
		];
		$this->printoutProperties = array_unique ( array_merge( $this->printoutProperties, $printoutProperties ) );
		if ( $this->classProperty !== null ) {
			$this->printoutProperties[] = $this->classProperty;
		}
		foreach ( $this->printoutProperties as $prop ) {
			$rawQueryArr[] = "?{$prop}";
			// @todo improve support for Monolingual text
			$propDataType = SMWUtils::getDataTypeOfProperty( $prop );
			if ( $propDataType == "_mlt_rec" ) {
				$rawQueryArr[] = "+index=1";
			}
		}

		$queryObj = SMWUtils::createSMWQueryObjFromRawQuery( $rawQueryArr, false );
		// Return SMWQueryResult
		// @link https://github.com/SemanticMediaWiki/SemanticMediaWiki/blob/master/src/Query/QueryResult.php
		$queryRes = $this->smwStore->getQueryResult( $queryObj );

		$this->resultbatchcount = $queryRes->getCount();
		$this->hasFurtherResults = $queryRes->hasFurtherResults();

		return $queryRes;
	}

	/**
	 * Helper function to get Query Result from a fully
	 * developed Query Object.
	 * @return QueryResult|null
	 */
	public function getResultFromQueryObject( $queryObj ) {
		return $this->smwStore->getQueryResult( $queryObj );
	}

	private function getPagesForQueryResult( QueryResult $queryRes ) {
		// Get array of SMWDIWikiPage objects
		$diWikiPages = $queryRes->getResults();
		$pageNames = [];
		foreach ( $diWikiPages as $diWikiPage ) {
			$pageNames[] = $diWikiPage->getTitle()->getFullText();
		}
		return $pageNames;
	}

	/**
	 * Deprecated?
	 * @param mixed $concept
	 * @return string
	 */
	public function getRawQueryForConcept( $concept ) {
		$concept = Title::makeTitleSafe( SMW_NS_CONCEPT, $concept );
		$this->hideNamespacePrefix = true;

		// $pages = $this->getAllPagesForConceptRemotely( $this->concept, null );

		if ( $this->substringPattern === "exactpagename" ) {
			return "[[{$concept}]] [[{$this->substring}]]";
		}

		$rawQueries = [];
		if ( $this->useDisplayTitle ) {
			$labelProp = "Display title of";
		} else {
			$labelProp = $this->wgReconAPISearchableLabelProp ?? $this->wgReconAPILabelProp ?? null;
		}
		if ( $labelProp !== null ) {
			// @todo
			$replacement = $this->getReplacementString(
				$this->substring,
				$this->substringPattern,
				false,
				false
			);
			$rawQueries[] = "[[Concept:{$this->concept}]] [[{$labelProp}::~{$replacement}]]";
		}

		// Single restriction:
		if ( true ) {
			// No property is used so different rules apply in FTS mode.
			$substrings = explode( " ", $this->substring );
			$replacements = [];
			foreach( $substrings as $str ) {
				$replacements[] = "*" . $str . "*";
			}
			$replacement = implode( " ", $replacements );
			// @todo Different rules apply
			$rawQueries[] = "[[Concept:{$this->concept}]] [[~$replacement]]";
		}

		return implode(  " OR ", $rawQueries );
	}

	/**
	 * Get sequential array of pages in SMW concept
	 * @deprecated
	 * Adapted from Page Forms' version for remote autocompletion
	 * @param string $conceptName
	 * @param string|null $substring
	 * @param string|null $mappingProperty
	 * @return array
	 */
	public function getAllPagesForConceptRemotely( 
		string $conceptName,
		mixed $mappingProperty = null
	): array {
		if ( $this->smwStore == null ) {
			return [];
		}

		// @check: do we get null for substring?

		// Build query string and prelims
		$rawQuery = "";
		$conceptTitle = Title::makeTitleSafe( SMW_NS_CONCEPT, $conceptName );
		$conceptArg = "[[{$conceptTitle}]]";
		$localSubstring = $this->substring;

		if ( $this->smwgEnabledFulltextSearch == true ) {
			$tildeArg = "~{$localSubstring}*";
			$likeArg = ( $this->substringPattern == "allchars" )
				? "like:*{$localSubstring}*"
				: "like:{$localSubstring}*";
		} elseif ( $this->substringPattern == "allchars" ) {
			$tildeArg = "~*{$localSubstring}*";
		} else {
			$tildeArg = "~{$localSubstring}*";
		}
		// ~{$prefixWildcard}{$substring}*
		// like:{$prefixWildcard}{$substring}*

		if ( $mappingProperty !== null ) {
			$rawQuery .= "{$conceptArg} [[{$mappingProperty}::$tildeArg]]";
			$rawQuery .= isset( $likeArg ) ? "OR {$conceptArg} [[{$mappingProperty}::$likeArg]]" : "";
		} elseif ( $this->useDisplayTitle ) {
			// expects that 'Display title of' is available as a property
			// @todo - may not be the case. Is a solution feasible?
			$rawQuery .= "{$conceptArg} [[Display title of::$tildeArg]]";
			$rawQuery .= isset( $likeArg ) ? "OR {$conceptArg} [[Display title of::$likeArg]]" : "";
		} else {
			$rawQuery .= "{$conceptArg} [[$tildeArg]]";
			$rawQuery .= isset( $likeArg ) ? "OR {$conceptArg} [[$likeArg]]" : "";
		}
		// Run query and get pages
		$retrievedPages = $this->getAllPagesForQuery( $rawQuery );
		return $retrievedPages;
	}

	/**
	 * Get pages from property with mapping property
	 * @todo: only experimentally supported
	 * @param string $propertyName
	 * @param string $substring
	 * @param string|null $mappingProperty
	 * @return array
	 */
	public function getAllMappedPagesForPropertyRemotely(
		string $propertyName,
		string $substring = "+",
		mixed $mappingProperty = null
	) {
		$mappingProperty = $mappingProperty ?? "Display title of";
		$rawQuery = "[[-{$propertyName}::+]] [[{$mappingProperty}::~*{$substring}*]] OR [[-{$propertyName}::+]] [[{$mappingProperty}::like:*{$substring}*]]";
		$res = $this->getAllPagesForQuery( $rawQuery );
		return $res;
	}

	/**
	 * Prepare substring for query if it must be modified or sanitised
	 * Caters for different meaning when fulltext search is enabled.
	 * Changes: Added asterisk to tokens
	 * Uses ~ not LIKE
	 * @todo there is overlap between this and constructQueryFromConfigProfile()
	 * @return string
	 */
	public function prepareSubstring( $substring ): mixed {
		if ( strlen($substring) == 0 ) {
			// No checks necessary
			return "";
		}

		if ( $this->smwgEnabledFulltextSearch ) {
			// check if the substring occurs between double quotes
			if( $substring[0] == "\"" && $substring[strlen($substring) - 1] == "\"" ) {
				return $substring;
			}
			// Add boolean operator to each token of the right length
			// @todo except single page restriction
			$substringArr = explode( " ", $substring );
			$newSubstringArr = [];
			foreach( $substringArr as $token ) {
				if ( strlen( $token ) >= $this->smwgFulltextSearchMinTokenSize ) {
					$newSubstringArr[] = "+{$token}*";
				} else {
					// Important! Guards against Runtime Exception.
					$newSubstringArr[] = $token;
				}
			}
			$substring = implode( " ", $newSubstringArr );
		} else {
			// @todo !
			// at least append an asterisk*
		}
		return $substring;
	}

	/**
	 * Construct a query statement from config data
	 * @todo sort out overlap with prepareSubstring
	 * 
	 * @param mixed $query
	 * @return array|string
	 */
	private function constructQueryFromConfigProfile( mixed $query ) {
		$rawQuery = "";
		if ( gettype( $query ) == "string" && $query !== "" ) {
			// @todo wildcards? - it depends!
			// @todo can we use this for 6.4 ?
			$usesLikeSyntax = $this->usesLikeSyntax( $query );			
			$replacement = $this->getReplacementString(
				$this->substring,
				$this->substringPattern,
				$usesLikeSyntax,
				$this->isSinglePageRestriction( $query, $usesLikeSyntax )
			);
			$rawQuery = str_replace( $this->smwqueryPlaceholder, $replacement, $query );
		} elseif( gettype( $query ) == "array" ) {
			// The preferred approach
			//$subStrings = explode( " ", $this->substring );
			$newStatements = [];
			foreach( $query as $statement ) {
				$base = $statement["from"] ?? "";
				$condition = $statement["where"] ?? "";

				// Use '6.4' instead! A departure because we are
				// replacing the 'where' condition wholesale
				// (no LIKE/MATCH)
				if ( $this->substringPattern === "exactpagename" ) {
					$newStatements[] = "$base [[{$this->substring}]]";
					continue;
				}

				// @todo Remove substringpattern once camelCase is used consistently
				$substringPattern = $statement["substringPattern"] ?? $statement["substringpattern"] ?? $this->substringPattern;
				$conditionsReplaced = [];
				$substring = $this->substring;
				if ( isset( $statement["preprocessSubstring"] ) ) {
					foreach ( $statement["preprocessSubstring"] as $v ) {
						switch( $v ) {
							case "flatten":
								$substring = StringModifier::flattenString( $substring );
								break;
						}
					}
				}

				// @todo getReplacementString should handle multiple subStrings
				$usesLikeSyntax = $this->usesLikeSyntax( $condition );
				$isSinglePageRestriction = $this->isSinglePageRestriction( $condition, $usesLikeSyntax );
				$replacement = $this->getReplacementString(
					$substring,
					$substringPattern,
					$this->usesLikeSyntax( $condition ),
					$isSinglePageRestriction
				);
				$conditionsReplaced[] = str_replace( $this->smwqueryPlaceholder, $replacement, $condition );
				/* No, let getReplacementString split the string
				foreach( $subStrings as $subString ) {
					// @todo wildcard, for now:
					$replacement = $this->getReplacementString(
						$subString,
						$this->substringPattern,
						$this->usesLikeSyntax( $condition )
					);
					$conditionsReplaced[] = str_replace( $this->smwqueryPlaceholder, $replacement, $condition );
				}
				*/
				$newStatements[] = $base . " " . implode( " ", $conditionsReplaced );
			}
			$rawQuery = implode( " OR ", $newStatements );
		}
		// @todo currently testing printout
		return $rawQuery;
	}

	/**
	 * Create replacement string consisting of substring and additional
	 * syntax sugar.
	 * 
	 * @todo Elasticsearch
	 * 
	 * @param mixed $substring
	 * @return string
	 */
	public function getReplacementString(
		string $substring,
		string $substringPattern = "prefix",
		bool $usesLikeSyntax = false,
		bool $isSinglePageRestriction = false
	) {
		$substring = trim( $substring );
		if ( strlen($substring) == 0 ) {
			// No checks necessary.
			return "+";
		}

		$useFTS = ( $this->smwgEnabledFulltextSearch && !$usesLikeSyntax ) ? true : false;
		if ( $useFTS ) {
			// FTS with tilde prefix
			// does not support alternative substring patterns. May override setting:
			$this->substringPattern = "tokenprefix";
			//$isNonToken = ( strlen( $substring ) < $this->smwgFulltextSearchMinTokenSize ) ? true : false;
			$smwQueryHelperForFTS = new SMWQueryHelperForFTS();
			$replacement = $smwQueryHelperForFTS->getReplacementStringForFTS( $substring, $isSinglePageRestriction );			
		} else {
			// regular SQL
			// $subStrings = explode( " ", $substring );
			switch( $substringPattern ) {
				case "prefix":
				case "stringprefix":
				case "tokenprefix":
					// @todo shouldn't we use prepareSubstring?
					// [[prop::~Hello worl*]] (without FTS) or [[prop::like:Hello worl*]] (with FTS)						
					$replacement = "{$substring}*";
					break;
				case "allchars":
					// LIKE only
					$replacement = "*{$substring}*";
					break;
				case "suffix":
					// currently unused
					$replacement = "*{$substring}";
					break;
				case "exact":
				case "exactpagename":
					$replacement = $substring;
					break;
				default:
					$replacement = "{$substring}*";
			}
		}
		return $replacement;
	}

	/**
	 * Helper function to check if a tilde (`~`) or `like:` was used
	 * in front of the placeholder.
	 * @todo only necessary for FTS, so maybe move to SMWQueryHelperForFTS
	 */
	private function usesLikeSyntax( $str ) {
		// Find position
		$placeholderPos = strpos( $str, $this->smwqueryPlaceholder );
		// False if no placeholder was found
		if ( $placeholderPos == false ) {
			return false;
		}
		// like:, LIKE:, etc.
		if ( strtolower( substr( $str, $placeholderPos - 5, 5 ) ) == "like:" ) {
			return true;
		}
		// todo - maybe check a space was inserted?
		return false;
	}

	/**
	 * Checks if single page restriction is used rather than a property.
	 * e.g. [[~hello*]] not [[Has text::~hello*]]
	 * @todo Not waterproof: [[ ~hello*]], [[Has text :: ~hello*]]
	 * @link https://www.semantic-mediawiki.org/wiki/Help:Single_page_restriction
	 * @param mixed $str
	 * @return
	 */
	private function isSinglePageRestriction( string $substring, bool $usesLikeSyntax ): bool {
		$operator = $usesLikeSyntax ? "like:" : "~";
		// preprocess/sanitise eg. ':: ~', '[[ ~'
		$substring = str_replace(
			[ ":: $operator", " ::$operator", "[[ " ],
			[ "::$operator", "::$operator", "[[" ],
			trim( $substring )
		);
		$placeholderPos = strpos( $substring, $this->smwqueryPlaceholder );
		if ( $usesLikeSyntax ) {
			$beforeOperator = substr( $substring, $placeholderPos - 7, 2 );
		} else {
			$beforeOperator = substr( $substring, $placeholderPos - 3, 2 );
		}
		if ( $beforeOperator == "[[" ) {
			return true;
		}
		return false;
	}

	/**
	 * Get the property for matching on names/labels
	 * 
	 * @param mixed $useDisplayTitle
	 * @return bool|mixed|string
	 */
	public function getQueryProperty( mixed $useDisplayTitle ) {
		if ( $useDisplayTitle ) {
			return "Display title of";
		} elseif ( $this->wgReconAPISearchableLabelProp !== null && $this->wgReconAPISearchableLabelProp !== false ) {
			return $this->wgReconAPISearchableLabelProp;
		} elseif ( $this->wgReconAPILabelProp !== null && $this->wgReconAPILabelProp !== false ) {
			return $this->wgReconAPILabelProp;
		}
		return false;
	}

}
