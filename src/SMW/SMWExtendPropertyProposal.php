<?php

/**
 * SMW implementation of the Data Extension Property Proposal service.
 * Returns properties for a given 'type' identifier.
 * @link https://www.w3.org/community/reports/reconciliation/CG-FINAL-specs-0.2-20230410/#data-extension-property-proposals
 */

namespace Recon\SMW;

use MediaWiki\MediaWikiServices;
use Recon\ReconUtils;
use Recon\MW\MWUtils;
use Recon\SMW\SMWQueryBuilder;
use \SMWQueryProcessor;

class SMWExtendPropertyProposal {

	private $wgReconAPISMWClassPropertiesSchema;
	// The property used to record the properties that may be used with a class:
	private $classPropertiesProp;
	// If class properties are set on a wiki page external to the class page, 
	// a property may be used to record the intended class
	// ('target class'):
	private $targetClassProp = false;
	private $resultLimit = false;	

	public function __construct() {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$this->wgReconAPISMWClassPropertiesSchema = $config->get( "ReconAPISMWClassPropertiesSchema" );
		$this->classPropertiesProp = $this->wgReconAPISMWClassPropertiesSchema["propertiesProp"];
		$this->targetClassProp = $this->wgReconAPISMWClassPropertiesSchema["targetClassProp"];
	}

	public function run( $class, $resultLimit = false ) {
		if ( $resultLimit ) {
			$this->resultLimit = intval( $resultLimit );
		}

		if ( $this->targetClassProp ) {
			$rawAskQuery = "[[{$this->targetClassProp}::{$class}]] [[{$this->classPropertiesProp}::+]]";
		} else {
			// Category pages are handled differently
			$rawAskQuery = MWUtils::isCategory( $class )
				? "[[:{$class}]] [[{$this->classPropertiesProp}::+]]"
				: "[[{$class}]] [[{$this->classPropertiesProp}::+]]";
		}

		// Set up query builder and create SMWQuery object
		$smwQueryBuilder = new SMWQueryBuilder();
		$smwQueryBuilder->addPrintoutProperties( [ $this->classPropertiesProp ] );
		// assuming default offset and limit for now
		$queryObj = $this->createSMWQueryObj( $rawAskQuery, $this->classPropertiesProp, false );

		// Get SMWQuery result
		$queryRes = $smwQueryBuilder->getResultFromQueryObject( $queryObj );

		// Format results
		$results = $this->formatResults( $queryRes );

		$res = [
			"type" => $class,
			"properties" => $results
		];
		if ( $this->resultLimit !== false ) {
			$res["limit"] = $this->resultLimit;
		}
		$res["meta"] = [
			"description" => "A Data Extension Property Proposal service: returns properties for a given type identifier",
			"classPropertiesProp" => $this->classPropertiesProp,
			"targetClassProp" => $this->targetClassProp,
			"resultLimit" => $this->resultLimit,
			"resultBatchCount" => count( $results )
		];
		return $res;
	}

	/**
	 * Returns SMWQuery object
	 * @param mixed $rawQuery
	 * @param mixed $printoutProperty
	 * @param mixed $useShowMode
	 */
	public function createSMWQueryObj( $rawQuery, $printoutProperty, $useShowMode = false ) {
		$rawQueryArr = [
			$rawQuery,
			"link=none",
			"offset=0",
			"searchlabel=",
			"?{$printoutProperty}",
			"+order=asc"
		];
		if( $this->resultLimit ) {
			$rawQueryArr[] = "+limit={$this->resultLimit}";
		}

		$queryObj = SMWUtils::createSMWQueryObjFromRawQuery( $rawQueryArr, $useShowMode );
		return $queryObj;
	}

	/**
	 * Format results
	 * @param \SMW\Query\QueryResult $queryResult
	 * @return array
	 */
	private function formatResults( $queryResult ) {
		if ( $queryResult->getErrors() !== [] ) {
			return [];
		}
		$props = [];
		$queryResultArr = $queryResult->toArray();

		foreach( $queryResultArr["results"] as $subjectName => $subject ) {
			$page = $subject["fulltext"];
			$printouts = $subject["printouts"] ?? [];
			foreach ( $printouts as $k => $printout ) {
				if ( $k == $this->classPropertiesProp ) {
					$props = $this->getPropertyNameFromPrintout( $printout );
				}
			}
		}
		return $props;
	}

	private function getPropertyNameFromPrintout( $printout ) {
		$res = [];
		foreach (  $printout as $printoutItem ) {
			if ( isset( $printoutItem["fulltext"] ) ) {
				// data type 'Page', incl. namespace prefix
				$id = $printoutItem["fulltext"];
				if ( isset( $printoutItem["displaytitle"] ) && $printoutItem["displaytitle"] !== "" ) {
					$name = $printoutItem["displaytitle"];
				} else {
					$namesSansPrefix = ReconUtils::removeNamespacePrefixFromNames( [ $printoutItem["fulltext"] ] );
					$name = $namesSansPrefix[0] ?? $printoutItem["fulltext"];
				}
				$other = [
					"source" => "smw",
					"exists" => $printoutItem["exists"]
				];	
			} else {
				// Assuming 'Text'. Because we don't know if namespace prefixes 
				// are attached, let's be flexible:
				$id = SMWUtils::handlePropertyName( $printoutItem, "addprefix", "contentlanguage" );
				$name = SMWUtils::handlePropertyName( $printoutItem, "removeprefix" );
				$other = [
					"source" => "smw"
				];
			}
			$res[] = [
				"id" => $id,
				"name" => $name,
				"other" => $other
			];
		}
		return $res;
	}

}
