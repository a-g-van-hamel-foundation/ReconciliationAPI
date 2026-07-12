<?php

/**
 * The work horse for building SMW queries
 * Build functions: run(), getResultForQuery()
 * Some class setters (setOptions, etc) available for 
 * additional leverage.
 * To be called with ReconServices::getInstance()->getSMWQueryBuilder()
 * 
 * @link https://www.semantic-mediawiki.org/wiki/Help:Full-text_search
 * @link https://github.com/SemanticMediaWiki/SemanticMediaWiki/blob/master/includes/query/SMW_QueryProcessor.php
 */

namespace Recon\SMW;

use MediaWiki\Title\Title;
use MediaWiki\MediaWikiServices;
use SMW\Query\QueryResult;
use Recon\SMW\SMWUtils;
use Recon\SMW\SMWQuerySyntaxConverters;
use Recon\SMW\SMWResultFormatter;
//use Recon\SMW\SMWQueryHelperForFTS;
use Recon\Config\ReconConfig;
use Recon\Services\ReconServices;

class SMWQueryBuilder {

	//Classes
	private $smwQueryHelper;
	private $smwStore;

	// Settings	
	private $maxAutocompleteValues;
	private $substring;
	// private $substringUTF8;
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
	private $smwgDefaultStore;
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
	private $profileID;
	private $profilePage;
	private $queryProfile;
	private $resultOffset;
	private $resultLimit;
	private $resultSort;
	private $resultOrder;
	private $resultbatchcount;
	private $hasFurtherResults;
	private $comment = [];
	private $hideNamespacePrefix = true;
	// Whether the query should run (a) only if it receives 
	// a non-empty string as "prefix", or (b) "always"
	private $wgReconAPIQueryTrigger;

	public function __construct(
		$smwStore = null,
		$smwQueryHelper = null,
		$mainConfig = null,
		$smwConfig = null
	) {
		// Classes and fallbacks
		$this->smwStore = $smwStore ?? SMWUtils::getSMWStore();
		if ( !$this->smwStore ) {
			// @todo wrong place?
			return;
		}
		$config = $mainConfig ?? MediaWikiServices::getInstance()->getMainConfig();
		if ( $smwConfig == null ) {
			global $smwgDefaultStore, $smwgEnabledFulltextSearch, $smwgFulltextSearchMinTokenSize;
			$this->smwgDefaultStore = $smwgDefaultStore;
			$this->smwgEnabledFulltextSearch = $smwgEnabledFulltextSearch;
			$this->smwgFulltextSearchMinTokenSize = $smwgFulltextSearchMinTokenSize;
		} else {
			$this->smwgDefaultStore = $smwConfig->get( "DefaultStore" );
			$this->smwgEnabledFulltextSearch = $smwConfig->get( "EnabledFulltextSearch" );
			$this->smwgFulltextSearchMinTokenSize = $smwConfig->get( "FulltextSearchMinTokenSize" );
		}
		if ( $smwQueryHelper == null ) {
			$this->smwQueryHelper = ReconServices::getInstance()->getSMWQueryHelper();
		} else {
			$this->smwQueryHelper = $smwQueryHelper;
		}

		// ...
		$this->maxAutocompleteValues = 25; // @todo configure
		// SMW config - makeConfig( ... ) does not work here

		$this->setOptions( 0, 25 );

		// Get default properties from config.		
		$this->wgReconAPILabelProp = $config->get( "ReconAPILabelProp" );
		$this->wgReconAPISearchableLabelProp = $config->get( "ReconAPISearchableLabelProp" );
		$this->wgReconAPIDescriptionProp = $config->get( "ReconAPIDescriptionProp" );
		$this->wgReconAPIThumbnailProp = $config->get( "ReconAPIThumbnailProp" );
		$this->wgReconAPIQueryTrigger = $config->get( "ReconAPIQueryTrigger" );

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
		$hideNamespacePrefix = null,
		$resultSort = null
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
		if ( $resultSort !== null ) {
			$this->resultSort = $resultSort;
		}
	}

	public function setQueryProfile( $queryProfile ) {
		$this->queryProfile = $queryProfile;
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
			$smwMethod = "SMW query by JSON profile (page)";
			$rawQuery = $this->setProfileAndGetRawQuery( $profileID, "entity" );
		} elseif( isset( $this->queryProfile ) ) {
			$smwMethod = "SMW query by JSON profile, added to the URL string directly";
			$rawQuery = $this->getRawQueryFromQueryProfile( $this->queryProfile );
		} elseif ( isset( $this->concept ) ) {
			// @deprecated ?
			$smwMethod = $this->useDisplayTitle ? "SMW query on concept by display title" : "SMW query on concept";
			$rawQuery = $this->getRawQueryForConcept( $this->concept );
		} elseif( isset( $rawQuery ) ) {
			// @todo Not implemented yet
			$smwMethod = "SMW query in URL string";
			//$this->smqQueryHelper->prepareSubstring( $substring );
			$this->checkAndMaybeAlterSubstringPattern( false, false );
			$this->substringProcessed = $this->smwQueryHelper->getReplacementString(
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
			//print_r( $queryProp);
			if ( trim($rawTypeQuery . $rawPropValQuery ) === "" ) {
				// @todo should we really restrict to existing pages only?
				$fromQuery = "[[Modification date::+]]";
			} else {
				$fromQuery = $rawTypeQuery . $rawPropValQuery;
			}
			// A fallback though less than ideal.
			$isSinglePageRestriction = ( $queryProp === false || $useDisplayTitle === false ) ? true : false;
			$this->checkAndMaybeAlterSubstringPattern( false, $isSinglePageRestriction );
			$this->substringProcessed = $this->smwQueryHelper->getReplacementString(
				$this->substring,
				$this->substringPattern,
				false,
				$isSinglePageRestriction
			);

			$this->hideNamespacePrefix = true;
			if ( $queryProp === false ) {
				$smwMethod = "SMW query by single page restriction";
				$rawQuery = "{$fromQuery} [[~{$this->substringProcessed}]]";
				//$rawQuery = "{$fromQuery} [[~*{$this->substringProcessed}]]";
			} else {
				$smwMethod = "SMW query by property '$queryProp'";
				// Subtle difference
				$rawQuery = $this->substringProcessed !== "+"
					? "{$fromQuery} [[{$queryProp}::~{$this->substringProcessed}]]"
					: "{$fromQuery} [[{$queryProp}::+]]";
				//print_r($rawQuery);
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
			"smwgDefaultStore" => $this->smwgDefaultStore,
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
			"resultSort" => $this->resultSort,
			"nextOffset" => $this->hasFurtherResults
				? ( $this->resultOffset + $this->resultLimit )
				// @todo ?
				: 0
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
	public function formatQueryResult( ?QueryResult $queryRes ): array {
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
		if ( isset( $outputPropertyInfo["sort"] ) ) {
			$this->resultSort = $outputPropertyInfo["sort"];
		}
		if ( isset( $outputPropertyInfo["order"] ) ) {
			$this->resultOrder = $outputPropertyInfo["order"];
		}

		$rawQuery = $this->smwQueryHelper->constructQueryFromConfigProfile( $q, $this->substring, $this->substringPattern );
		// $outputPropertyInfo - formatting options @todo
		return $rawQuery;
	}

	/**
	 * Get raw query from already provided profile (array)
	 */
	private function getRawQueryFromQueryProfile( array $queryProfile ) {
		// @todo - using this pattern for now
		$this->substringPattern = "allchars";
		$q = $queryProfile["smwquery"]["statement"];
		$rawQuery = $this->smwQueryHelper->constructQueryFromConfigProfile( $q, $this->substring, $this->substringPattern );
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
		if ( $this->resultSort !== null ) {
			$rawQueryArr[] = "sort={$this->resultSort}";
		}
		if ( $this->resultOrder !== null ) {
			$rawQueryArr[] = "order={$this->resultOrder}";
		}

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
		// Get array of SMW\DIWikiPage objects
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
		// @todo - should have been handled in a previous step?
		if ( $this->useDisplayTitle ) {
			$labelProp = "Display title of";
		} else {
			$labelProp = $this->wgReconAPISearchableLabelProp ?? $this->wgReconAPILabelProp ?? null;
		}
		if ( $this->substring === "" && $this->wgReconAPIQueryTrigger === "always" ) {
			$rawQueries[] = "[[Concept:{$this->concept}]]";
		} elseif ( $labelProp !== null ) {
			// @todo
			$this->checkAndMaybeAlterSubstringPattern( false, false );
			$replacement = $this->smwQueryHelper->getReplacementString(
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
	 * @deprecated Moved to SMWQueryHelper
	 * @return string
	 */
	public function getReplacementString(
		string $substring,
		string $substringPattern = "prefix",
		bool $usesLikeSyntax = false,
		bool $isSinglePageRestriction = false
	) {
		// @todo Previously $this->substringPattern could be set to "tokenprefix", not now.
		return $this->smwQueryHelper->getReplacementString( $substring, $substringPattern, $usesLikeSyntax, $isSinglePageRestriction );
	}

	/**
	 * If FTS is enabled, check that syntax uses property
	 * with tilde (not like). If so, enforce 'tokenprefix'.
	 * Does NOT consider if substring is non-token material.
	 * Previously part of getReplacementString()
	 * 
	 * @param bool $usesLikeSyntax
	 * @param bool $isSinglePageRestriction
	 * @return void
	 */
	private function checkAndMaybeAlterSubstringPattern(
		bool $usesLikeSyntax,
		bool $isSinglePageRestriction
	) {
		if ( $this->smwgEnabledFulltextSearch && !$usesLikeSyntax && !$isSinglePageRestriction ) {
			$this->substringPattern = "tokenprefix";
		}
	}

	/**
	 * Get the property for matching on names/labels
	 * 
	 * @param mixed $useDisplayTitle
	 * @return mixed (incl. bool|string)
	 */
	public function getQueryProperty( mixed $useDisplayTitle ) {
		if ( $useDisplayTitle === false ) {
			// Explicit
			return false;
		} elseif ( $useDisplayTitle ) {
			return "Display title of";
		} elseif ( $this->wgReconAPISearchableLabelProp !== null && $this->wgReconAPISearchableLabelProp !== false ) {
			return $this->wgReconAPISearchableLabelProp;
		} elseif ( $this->wgReconAPILabelProp !== null && $this->wgReconAPILabelProp !== false ) {
			return $this->wgReconAPILabelProp;
		}
		return false;
	}

}
