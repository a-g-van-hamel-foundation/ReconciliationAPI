<?php

/**
 * SMW implementation of responses to the Data Extension Query
 * Request service.
 * @link https://www.w3.org/community/reports/reconciliation/CG-FINAL-specs-0.2-20230410/#data-extension-responses
 * @link https://www.w3.org/community/reports/reconciliation/CG-FINAL-specs-0.2-20230410/#data-extension-query-requests (API)
 * 
 * @todo Make clear how we name properties.
 * 
 * Datatypes currently supported: Text, Monolingual Text, Number,
 * Page (=entity), URL, Date (timestamp).
 * The v0.2 specs offer no recommendations on date formatting,
 * though considerations are found in the draft for v2.0.
 * ISO 8601 or RFC 3339 can be considered as an alternative.
 * @todo Consider other data types.
 * @link https://www.semantic-mediawiki.org/wiki/Help:Displaying_information 
 */

namespace Recon\SMW;

use MediaWiki\MediaWikiServices;
use MediaWiki\Json\FormatJson;
use SMW\Query\QueryResult;
use Recon\MW\MWUtils;
use Recon\Services\ReconServices;
use Recon\SMW\SMWUtils;

class SMWExtendQueryRequest {

	private $wgReconAPILabelProp;
	private $wgReconAPIDescriptionProp;
	private $wgReconAPIThumbnailProp;
	private $siteLanguageCode = "en";

	public function __construct() {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$this->wgReconAPILabelProp = $config->get( "ReconAPILabelProp" );
		$this->wgReconAPIDescriptionProp = $config->get( "ReconAPIDescriptionProp" );
		$this->wgReconAPIThumbnailProp = $config->get( "ReconAPIThumbnailProp" );

		$this->siteLanguageCode = MediaWikiServices::getInstance()->getContentLanguage()->getHtmlCode();
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
			$smwQueryBuilder = ReconServices::getInstance()->getSMWQueryBuilder();
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
	private function addPrintoutPropertiesToRawQuery(
		array &$rawQueryArr,
		array $propItems = []
	) {
		foreach( $propItems as $propItem ) {
			if ( !isset( $propItem["id"] ) ) {
				continue;
			}
			// Use a prefix-less name of the property
			$printoutProp = SMWUtils::handlePropertyName( $propItem["id"], "removeprefix" );
			$rawQueryArr[] = "?$printoutProp";
			// Because some data types come with special requirements
			$propDataType = SMWUtils::getDataTypeOfProperty( $printoutProp );

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
			// Maybe other settings in the future
		}
	}

	/**
	 * Summary of formatPageResults
	 * @param mixed $queryResult
	 * @param mixed $id
	 * @return array
	 */
	private function formatPageResults( QueryResult $queryResult, $id ) {
		if ( count( $queryResult->getErrors() ) !== 0 ) {
			return [];
		}

		$queryResultArr = $queryResult->toArray();
		$printouts = $queryResultArr["results"][$id]["printouts"];

		$pageValues = [];
		$defaultVal = null;
		foreach( $printouts as $prop => $vals ) {
			$dataType = SMWUtils::getDataTypeIdFromPrintRequests( $prop, $queryResultArr["printrequests"] );
			$formattedVals = [];
			foreach( $vals as $index => $val ) {
				switch( $dataType ) {
					case "_wpg":
						$formattedVals[] = $this->getResultForPage( $val["fulltext"], $val["displaytitle"] );
					break;
					case "_mlt_rec":
						// Monolingual Text
						if ( gettype($val) === "string" ) {
							// Output if +index=1 filter is used
							$formattedVals[] = [ "str" => $val ];
						} elseif( gettype($val) === "array" && isset($val["Language code"]) ) {
							if ( $index == 0 ) {
								// To be used if we have no match
								// for $this->siteLanguageCode
								$defaultVal = $val["Text"]["item"][0];
							}
							if ( $val["Language code"]["item"][0] === $this->siteLanguageCode ) {
								$formattedVals[] = [ "str" => $val["Text"]["item"][0] ];
							}
						}
					break;
					case "_dat":
						$formattedVals[] = [ "str" => $val["timestamp"] ];
					break;
					case "_num":
						// @todo Enforce string?
						$formattedVals[] = [ "str" => intval($val) ];
					break;
					case "_txt":
					case "_keyw":
					case "_uri":
					default:
						$formattedVals[] = [ "str" => $val ];
				}
			}
			if ( $dataType == "_mlt_rec" && count($formattedVals) == 0 && isset($defaultVal) ) {
				// See note above
				$formattedVals[] = [ "str" => $defaultVal ];
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
		$smwQueryBuilder = ReconServices::getInstance()->getSMWQueryBuilder();
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
