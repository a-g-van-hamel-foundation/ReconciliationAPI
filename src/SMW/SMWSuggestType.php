<?php

/**
 * Service for the recon-suggest-type module using Semantic MediaWiki.
 * Suggest a 'type', where 'type' represents a page, maybe string.
 * 
 * @todo The label property by which we can search does not need to
 * be the same as the property used for showing a label
 * @todo Profiles
 * 
 * @link https://www.w3.org/community/reports/reconciliation/CG-FINAL-specs-0.2-20230410/#suggest-types-response-json-schema
 * @link https://reconciliation-api.github.io/specs/0.2/schemas/suggest-types-response.json
 */

namespace Recon\SMW;

use MediaWiki\MediaWikiServices;
use Recon\MW\MWUtils;
use Recon\SMW\SMWUtils;
use Recon\SMW\SMWQueryBuilder;
use Recon\SMW\SMWSuggestPropertyValue;

class SMWSuggestType {

	private $store;
	private $propertyName = "Class";
	// maybe set from config
	private $prioritisedProperties;
	private $substring = "";
	private $substringProcessed;
	private $substringPattern = "stringprefix";
	private $resultOffset = 0;
	private $resultLimit = 25;
	private $smwClassProp;
	private $labelProperty;
	private $descriptionProperty;
	private $smwBroaderClassProp;
	private $smwBroaderConceptProp;
	private $smwQueryForCategories;
	private $smwQueryForConcepts;
	private $smwQueryForClasses;
	private $meta = [
		"service" => "Suggest types",
		"source" => "smw"
	];
	private $localCategoryName;
	private $localConceptName;

	public function __construct() {
		$this->store = SMWUtils::getSMWStore();

		$config = MediaWikiServices::getInstance()->getMainConfig();
		$this->smwClassProp = $this->meta["ReconAPIClassProp"] = $config->get( "ReconAPIClassProp" );
		$this->labelProperty = $this->meta["ReconAPILabelProp"] = $config->get( "ReconAPILabelProp" );
		$this->descriptionProperty = $config->get( "ReconAPIDescriptionProp" );
		$this->smwBroaderClassProp = $this->meta["ReconAPIBroaderClassProp"] = $config->get( "ReconAPIBroaderClassProp" );
		$this->smwBroaderConceptProp = $this->meta["ReconAPIBroaderConceptProp"] = $config->get( "ReconAPIBroaderConceptProp" );
		//
		$this->smwQueryForCategories = $this->meta["ReconAPISMWQueryForCategories"] = $config->get( "ReconAPISMWQueryForCategories" );
		$this->smwQueryForConcepts = $this->meta["ReconAPISMWQueryForConcepts"] = $config->get( "ReconAPISMWQueryForConcepts" );
		$this->smwQueryForClasses = $this->meta["ReconAPISMWQueryForClasses"] = $config->get( "ReconAPISMWQueryForClasses" );

		$this->localCategoryName = MWUtils::getNamespaceNameFromIndex( NS_CATEGORY );
		$this->localConceptName = MWUtils::getNamespaceNameFromIndex( SMW_NS_CONCEPT );
	}

	/**
	 * Test: assuming we want categories, concepts and page classes
	 * @return array|null
	 */
	public function run(
		string $substring,
		mixed $typeGroup = null,
		mixed $profileID = null,
		$offset = 0,
		$limit = 25
	) {
		if ( !$this->store ) {
			return [];
		}

		// Offset and limit
		$this->resultOffset = $offset;
		$this->resultLimit = $limit;
		$smwQueryBuilder = new SMWQueryBuilder();
		$smwQueryBuilder->setOptions( $offset, $limit );

		// @todo Work with profiles! 
		// $this->setProfile( $profileID )

		// Substring
		$this->substring = $substring;
		// not currently configurable
		$this->substringPattern = "stringprefix";
		$this->substringProcessed = $smwQueryBuilder->getReplacementString(
			$this->substring,
			$this->substringPattern,
			false, // ?
			false // @todo
		);

		// What types?
		if ( $typeGroup == null ) {
			// Not using namespaces
			// For "class", see 'page class' in docs
			$typeGroups = [ "category", "concept", "class" ];
		} else {
			$typeGroups = explode( ",", $typeGroup );
		}

		$rawQueries = $printoutProperties = $namespaces = [];		
		foreach ( $typeGroups as $typeGroup ) {
			switch( $typeGroup ) {
				case "namespace":
				case "contentnamespace":
					$namespaces = $this->getNamespaces( $substring, $typeGroup );
					$meta["typegroups"][] = $typeGroup;
				break;
				case "category":
					if ( $this->smwQueryForCategories !== null ) {
						// Does anyone use Display titles for categories?
						$rawQueries[] = "{$this->smwQueryForCategories} [[~*{$this->substring}*]]";
						$printoutProperties[] = "Subcategory of";
						$meta["typegroups"][] = $typeGroup;
					}
				break;
				case "concept":
					if ( $this->smwQueryForConcepts !== null ) {
						$rawQueries[] = "{$this->smwQueryForConcepts} [[~*{$this->substring}*]] OR {$this->smwQueryForConcepts} [[{$this->labelProperty}::~{$this->substringProcessed}]]";
						$printoutProperties[] = $this->smwBroaderConceptProp;
						$meta["typegroups"][] = $typeGroup;
					}
				break;
				case "class":
					if ( $this->smwQueryForClasses !== null ) {
						// Provided that the class property is of data type Page
						$rawQueries[] = "{$this->smwQueryForClasses} [[~*{$this->substring}*]] OR {$this->smwQueryForClasses} [[{$this->labelProperty}::~{$this->substringProcessed}]]";
						$printoutProperties[] = $this->smwBroaderClassProp;
						$meta["typegroups"][] = $typeGroup;
					}
				break;
			}
		}

		// Create query statement
		$rawQuery = implode( " OR ", $rawQueries );

		// Add properties that point to 'broader' types
		$smwQueryBuilder->addPrintoutProperties( $printoutProperties );

		// Run the query
		$queryRes = $smwQueryBuilder->getResultForQuery( $rawQuery );
	
		// Prepare formatter
		$smwResultFormatter = new SMWResultFormatter( $queryRes, $this->substring );
		$smwResultFormatter->setPrintoutProperties(
			$this->labelProperty,
			$this->descriptionProperty,
			false,
			true,
			$this->smwClassProp
		);
		$smwResultFormatter->addPrintoutProperties( $printoutProperties );
		$smwResultFormatter->smwBroaderConceptProp = $this->smwBroaderConceptProp;
		$smwResultFormatter->smwBroaderClassProp = $this->smwBroaderClassProp;
		$smwResultFormatter->setOutputFormat( "type" );

		// Format
		$pages = $smwResultFormatter->doFormat();

		$this->meta["substring"] = $this->substring;
		$this->meta["substringPattern"] = $this->substringPattern;
		$this->meta["smwQuery"] = $rawQuery;
		$this->meta["resultBatchCount"] = count( $pages ) + count( $namespaces );
		$this->meta["resultOffset"] = $this->resultOffset;
		$this->meta["resultLimit"] = $this->resultLimit;

		$res = [
			"result" => array_merge( $namespaces, $pages ),
			"meta" => $this->meta
		];
		return $res;
	}

	public function getNamespaces( $substr = "", $typeGroup = "namespace" ) {
		$language = MediaWikiServices::getInstance()->getContentLanguage();
		if ( $typeGroup == "namespace" ) {
			$namespaces = $language->getFormattedNamespaces();
			//$namespaces = array_values( $namespaces );
		} elseif( $typeGroup == "contentnamespace" ) {
			$contentNamespaces = MediaWikiServices::getInstance()->getMainConfig()->get( 'ContentNamespaces' );
			$namespaces = [];
			foreach( $contentNamespaces as $v ) {
				$namespaces[] = $language->getFormattedNsText( $v );
			}
		}

		$items = [];
		foreach( $namespaces as $k => $v ) {
			if ( $substr == "" ) {
				$items[] = [
					"id" => $v,
					"name" => $v !== "" ? $v : "Main",
					"broader" => [],
					"typegroup" => "namespace"
				];
			} elseif( str_contains( strtolower( $v ), strtolower( $substr ) ) ) {
				$items[] = [
					"id" => $v,
					"name" => $v !== "" ? $v : "Main",
					"broader" => [],
					"typegroup" => "namespace"
				];
			}
		}
		return $items;
	}

	/**
	 * @deprecated
	 * @param mixed $substr
	 * @param mixed $propertyName
	 * @return mixed
	 */
	public function getType( $substr = "", $propertyName = null ) {
		$this->substring = $substr;
		$choice = 'concept';

		$res = [];
		if ( $choice == 'class' ) {
			$res = $this->getClassPage( $propertyName = null );
		} elseif( $choice == 'concept' ) {
			$res = $this->getConcept();
		}
		return $res;
	}

	/**
	 * @deprecated
	 * @param mixed $propertyName
	 * @return mixed
	 */
	public function getClassPage( $propertyName = null ) {
		// ? Why did I use 'Is related to' property??

		$this->propertyName = $propertyName !== null
			? $propertyName
			: $this->smwClassProp;

		$res = [];
		$propertyType = SMWUtils::getDataTypeOfProperty( $this->propertyName );
		// Properties of type Text and Page are treated differently.
		if ( $propertyType == "_txt" ) {
			$suggester = new SMWSuggestPropertyValue();
			$res = $suggester->run( $this->propertyName, $this->substring );
		} elseif( $propertyType == "_wpg" ) {
			$smwQueryBuilder = new SMWQueryBuilder();
			$smwQueryBuilder->setOptions( $this->resultOffset, $this->resultLimit );

			$res = $smwQueryBuilder->run(
				$this->substring,
				"tokenstring",
				null,
				true,
				null,
				"[[-{$this->propertyName}::+]] [[~*{$this->substring}*]] OR [[-{$this->propertyName}::+]] [[{$this->labelProperty}::~*{$this->substring}*]] "
			);
		}

		// broader
		$results = $res["result"];
		$newResults = [];
		foreach ( $results as $result ) {
			$result["broader"] = $this->getBroaderClassForPage( $result["id"] );
			$newResults[] = $result;
		}
		$res["result"] = $newResults;

		$res["meta"]["service"] = "Suggest types";
		$res["meta"]["ReconAPIClassProp"] = $this->smwClassProp;
		$res["meta"]["ReconAPIBroaderClassProp"] = $this->smwBroaderClassProp;
		$res["meta"]["ReconAPIBroaderConceptProp"] = $this->smwBroaderConceptProp;

		return $res;
	}

	/**
	 * @deprecated
	 * @param mixed $fullpagename
	 * @return mixed
	 */
	private function getBroaderClassForPage( $fullpagename ) {
		//"[[-{$this->propertyName}::+]] [[~*{$this->substring}*]] OR [[-{$this->propertyName}::+]] [[{$this->labelProperty}::~*{$this->substring}*]] "
		$smwQueryBuilder = new SMWQueryBuilder();
		$smwQueryBuilder->setOptions( 0, $this->resultLimit );
		$rawQuery = "[[-{$this->smwBroaderClassProp}::{$fullpagename}]]";
		$res = $smwQueryBuilder->getAllPagesForQuery( $rawQuery );
		return $res;
	}

	/**
	 * @deprecated
	 * @return mixed
	 */
	public function getConcept() {
		$smwQueryBuilder = new SMWQueryBuilder();
		$smwQueryBuilder->setOptions( $this->resultOffset, $this->resultLimit );
		// to do set Properties
		$rawQuery = "{$this->smwQueryForConcepts} [[~*{$this->substring}*]] OR {$this->smwQueryForConcepts} [[{$this->labelProperty}::~*{$this->substring}*]] ";

		$res = $smwQueryBuilder->run(
			$this->substring,
			"tokenstring",
			null,
			false,
			null,
			$rawQuery
		);

		$newResults = [];
		foreach ( $res["result"] as $result ) {
			$result["broader"] = $this->getBroaderConcept( $result["id"] );
			$newResults[] = $result;
		}
		$res["result"] = $newResults;

		return $res;
	}

	/**
	 * @deprecated
	 * @param mixed $fullpagename
	 * @return mixed
	 */
	private function getBroaderConcept( $fullpagename ) {
		$smwQueryBuilder = new SMWQueryBuilder();
		$smwQueryBuilder->setOptions( 0, $this->resultLimit );
		$rawQuery = "[[-{$this->smwBroaderConceptProp}::{$fullpagename}]]";
		$res = $smwQueryBuilder->getAllPagesForQuery( $rawQuery );
		return $res;
	}

	/**
	 * Formats defaultTypes
	 * May not be the ideal class to put this in
	 */
	public function getOutputForDefaultTypes( array $types ) {
		$rawQuery = "";
		foreach( $types as $k => $type ) {
			$typeGroup = $this->getTypeGroup( $type );
			if ( $typeGroup == "MWCategory" || $typeGroup == "SMWConcept" ) {
				$rawQuery .= $k == 0 ? "[[:$type]]" : " OR [[:$type]]";
			} else {
				// regular wiki page used as class
				$rawQuery .= $k == 0 ? "[[$type]]" : " OR [[$type]]";
			}
		}

		// Run query
		$smwQueryBuilder = new SMWQueryBuilder();
		$queryRes = $smwQueryBuilder->getResultForQuery( $rawQuery );

		// Format results
		$smwResultFormatter = new SMWResultFormatter( $queryRes, null, true );
		$smwResultFormatter->setPrintoutProperties(
			$this->labelProperty,
			null,
			false,
			true,
			$this->smwClassProp
		);
		$smwResultFormatter->setOutputFormat( "type" );
		$pages = $smwResultFormatter->doFormat();

		return $pages;		
	}

	/**
	 * Deduce the type group to which a type belongs
	 * from the type's name (prefix)
	 * Returns 'MWCategory', 'SMWConcept', or 'Class'
	 * cf. MWUtils::isCategory,  MWUtils::isConcept,
	 * @return string
	 */
	private function getTypeGroup( $type ) {
		$categoryNames = array_unique( [ "Category", $this->localCategoryName ] );
		foreach( $categoryNames as $cat ) {
			if( str_starts_with( $type, "{$cat}:" ) ) {
				return "MWCategory";
			}
		}
		$conceptNames = array_unique( [ "Concept", $this->localConceptName ] );
		foreach( $conceptNames as $concept ) {
			if( str_starts_with( $type, "{$concept}:" ) ) {
				return "SMWConcept";
			}
		}
		return "Class";
	}

}
