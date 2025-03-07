<?php

/**
 * MediaWiki core implementation of the Data Extension Query Request service.
 * Supports categories and ?namespaces only.
 * @todo Improve support for namespaces.
 */

namespace Recon\MW;

use FormatJson;
use Title;
use Recon\MW\MWUtils;
use Recon\MW\MWCategoryUtils;

class MWExtendQueryRequest {

	public function run( $queryStr ) {
		$res = FormatJson::parse( $queryStr, FormatJson::FORCE_ASSOC | FormatJson::TRY_FIXING );
		$queryRequest = $res->getValue();
		if ( !is_array( $queryRequest ) || json_last_error() !== JSON_ERROR_NONE || $queryRequest == null || empty($queryRequest) ) {
			return [];
		}

		$results = [];
		$ids = isset( $queryRequest["ids"] ) ? $queryRequest["ids"] : [];
		$reqProperties = isset( $queryRequest["properties"] ) ? $queryRequest["properties"] : [];

		// META
		$metadata = $this->getMetadataForProperties( $reqProperties );

		// ROWS
		$rows = [];
		foreach( $ids as $id ) {
			$title = Title::newFromText( $id );
			$pageValues = [];
			foreach( $reqProperties as $propItem ) {				
				if( !isset( $propItem["id"] ) ) {
					continue;
				}
				$prop = $propItem["id"];
				if( $prop == "category" ) {
					// Get all categories from page
					$pageValues[$prop] = MWCategoryUtils::getCategoriesFromTitle( $title, "idname" );
				} elseif( $prop == "namespace" ) {
					$nsIndex = $title->getNamespace();
					$name = MWUtils::getNamespaceNameFromIndex( $nsIndex );
					$pageValues[$prop] = [
						[
							"id" => $nsIndex,
							"name" => ( $name !== "" ) ? $name : "Main"
						]
					];
				}
			}
			$rows[$id] = $pageValues;
		}
		$res = [
			"meta" => $metadata,
			"rows" => $rows
		];
		return $res;
	}

	/**
	 * Create the metadata for requested properties
	 * @param mixed $propertyItems
	 * @return array
	 */
	private function getMetadataForProperties( $propertyItems ) {
		$metadata = [];
		foreach( $propertyItems as $propItem ) {
			$metadata[] = [
				"id" => $propItem["id"],
				"name" => $propItem["id"]
			];
		}
		return $metadata;
	}

}
