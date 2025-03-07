<?php

/**
 * SMW implementation of the Data Extension Query Request service.
 * @link https://www.w3.org/community/reports/reconciliation/CG-FINAL-specs-0.2-20230410/#data-extension-property-proposals
 * 
 * @todo Make clear how we name properties. 
 * @todo Consider other property settings (for dates, numbers, monolingual text). See
 * @link https://www.semantic-mediawiki.org/wiki/Help:Displaying_information 
 */

namespace Recon\SMW;

use MediaWiki\MediaWikiServices;
use FormatJson;
use Recon\ReconUtils;
use Recon\MW\MWUtils;
use Recon\SMW\SMWQueryBuilder;
use Recon\SMW\SMWUtils;
use \SMWQueryProcessor;

class SMWExtendQueryRequest {

	private $wgReconAPILabelProp;
	private $wgReconAPIDescriptionProp;
	private $wgReconAPIThumbnailProp;

	public function __construct() {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$this->wgReconAPILabelProp = $config->get( "ReconAPILabelProp" );
		$this->wgReconAPIDescriptionProp = $config->get( "ReconAPIDescriptionProp" );
		$this->wgReconAPIThumbnailProp = $config->get( "ReconAPIThumbnailProp" );
	}

	public function run( $queryStr ) {
		$res = FormatJson::parse( $queryStr, FormatJson::FORCE_ASSOC | FormatJson::TRY_FIXING );
		$queryRequest = $res->getValue();
		if ( !is_array( $queryRequest ) || json_last_error() !== JSON_ERROR_NONE || $queryRequest == null || empty($queryRequest) ) {
			return [];
		}

		$results = [];
		
		$ids = isset( $queryRequest["ids"] ) ? $queryRequest["ids"] : [];
		$reqProperties = isset( $queryRequest["properties"] ) ? $queryRequest["properties"] : [];

		// META: contains the properties used for data extension, as requested 
		// in the data extension query. If properties have entities as values, 
		// they MAY specify a type in the metadata.
		$metadata = $this->getMetadataForProperties( $reqProperties );

		// ROWS. The rows object contains, for each entity identifier in the 
		// data extension query, for each property identifier in the metadata, 
		// the property values of that property in that entity. 
		$rows = [];
		// For each entity (id) requested
		foreach( $ids as $id ) {
			$rawQuery = MWUtils::isCategory( $id ) ? "[[:{$id}]]": "[[{$id}]]";
			$rawQueryArr = [
				$rawQuery,
				"link=none",
				"offset=0",
				"searchlabel="
			];
			$this->addPrintoutPropertiesToRawQuery( $rawQueryArr, $reqProperties );
			$smwQueryObj = SMWUtils::createSMWQueryObjFromRawQuery( $rawQueryArr );
			$smwQueryBuilder = new SMWQueryBuilder();
			$queryRes = $smwQueryBuilder->getResultFromQueryObject( $smwQueryObj );			
			$rows[$id] = $this->formatPageResults( $queryRes, $id );
		}
		return [
			"meta" => $metadata,
			"rows" => $rows
		];
	}

	/**
	 * Takes the properties and optional settings from a query request
	 * and adds them as printout properties to the raw query array.
	 * @param mixed $rawQueryArr
	 * @param array $propItems - properties ("id") and optional "settings"
	 * @return
	 */
	private function addPrintoutPropertiesToRawQuery( array &$rawQueryArr, $propItems = [] ) {
		foreach( $propItems as $propItem ) {
			if ( !isset( $propItem["id"] ) ) {
				continue;
			}
			// Use a prefix-less name of the property
			$printoutProp = SMWUtils::handlePropertyName( $propItem["id"], "removeprefix" );
			$rawQueryArr[] = "?$printoutProp";
			// Because some data types come with special requirements
			$propDataType = SMWUtils::getDataTypeOfProperty( $printoutProp );
			if ( $propDataType == "_mlt_rec" ) {
				// @todo improve support for Monolingual text
				// based on content language (@en, @de, etc.)
				$rawQueryArr[] = "+index=1";
			}
			if ( isset( $propItem["settings"]["limit"] ) ) {
				$limit = $propItem["settings"]["limit"];
				if ( $limit !== 0 ) {
					$rawQueryArr[] = "+limit={$limit}";
				}
			}
			if ( isset( $propItem["settings"]["order"] ) ) {
				// asc, desc
				$rawQueryArr[] = "+order={$propItem["settings"]["order"]}";
			}
			// maybe other settings in the future
		}
	}

	/**
	 * Summary of formatPageResults
	 * @param mixed $queryResult
	 * @param mixed $id
	 * @return array
	 */
	private function formatPageResults( $queryResult, $id ) {
		if ( $queryResult->getErrors() !== [] ) {
			return [];
		}
		$props = [];
		$queryResultArr = $queryResult->toArray();
		//print_r( "<pre>" );
		//print_r( $queryResultArr );
		//print_r( "</pre>" );

		$printouts = $queryResultArr["results"][$id]["printouts"];
		$pageValues = [];
		foreach( $printouts as $prop => $vals ) {
			$dataType = $this->getDataTypeIDFromPrintRequests( $prop, $queryResultArr["printrequests"] );
			// $v can be of different datatypes
			// $pageValues[$prop] = ....
			$formattedVals = [];
			foreach( $vals as $val ) {
				switch( $dataType ) {
					case "_wpg":
						$formattedVals[] = $this->getResultForPage( $val["fulltext"], $val["displaytitle"] );
					break;
					case "_txt":
					default:
					$formattedVals[] = [
						"str" => $val
					];
				}
			}
			if ( $prop == "Category" ) {
				// Special handling for MW Categories
				$pageValues["category"] = $formattedVals;
			} else {
				$pageValues["Property:{$prop}"] = $formattedVals;
			}
		}
		return $pageValues;
	}

	/**
	 * Fetch the data type id of a property from print requests
	 * e.g. _wgp, _txt, _str, etc
	 * @param mixed $prop
	 * @param array $printRequests
	 */
	private function getDataTypeIDFromPrintRequests( $prop, array $printRequests ) {
		foreach( $printRequests as $req ) {
			if ( $req["label"] == $prop ) {
				return $req["typeid"];
			}
		}
		return null;
	}

	/**
	 * Create the metadata for requested properties
	 * @param mixed $propertyItems
	 * @return array
	 */
	private function getMetadataForProperties( $propertyItems ) {
		$metadata = [];
		foreach( $propertyItems as $propItem ) {
			$prop = $propItem["id"];
			$name = SMWUtils::handlePropertyName( $prop, "removeprefix" );
			$metadata[] = [
				"id" => $prop,
				"name" => $name
			];
			// @todo maybe support type
			// @todo maybe support service
		}
		return $metadata;
	}

	/**
	 * Create basic output
	 * @return array
	 */
	private function getResultForPage( $page, $displayTitle = null ) {
		$rawQuery = MWUtils::isCategory( $page ) || MWUtils::isConcept( $page )
			? "[[:{$page}]]"
			: "[[{$page}]]";
		$rawQueryArr = [
			$rawQuery,
			"link=none",
			"searchlabel=",
			"?{$this->wgReconAPILabelProp}",
			"?{$this->wgReconAPIDescriptionProp}",
			"?{$this->wgReconAPIThumbnailProp}"
		];
		$smwQueryObj = SMWUtils::createSMWQueryObjFromRawQuery( $rawQueryArr, false );
		$smwQueryBuilder = new SMWQueryBuilder();
		$queryRes = $smwQueryBuilder->getResultFromQueryObject( $smwQueryObj );

		// error check..

		$queryResultArr = $queryRes->toArray();

		$printouts = $queryResultArr["results"][$page]["printouts"];
		$pageItem = [
			"id" => $page,
			"name" => $page
		];
		foreach( $printouts as $prop => $vals ) {
			switch( $prop ) {
				case $this->wgReconAPILabelProp:
					$pageItem["name"] = isset( $vals[0] ) ? $vals[0] : $page;
					break;
				case $this->wgReconAPIDescriptionProp:
					if ( isset( $vals[0] ) ) {
						$pageItem["description"] = $vals[0];
					}
					break;
				case $this->wgReconAPIThumbnailProp:
					//
					break;
			}
		}
		return $pageItem;
	}

}
