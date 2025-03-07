<?php

/**
 * Utility methods for working with the MediaWiki API.
 * To be avoided in favour of direct implementations
 * and probably unused.
 */

namespace Recon\API;

use Recon\ReconUtils;

class APIMWUtils {

	/**
	 * Client URL method to get results from the MediaWiki Action API
	 * @param array $params
	 */
	public static function getResultFromMWAPI( array $params ) {
		$urlBase = ReconUtils::getURLBase();
		$endPoint = "{$urlBase}/api.php";
		$url = $endPoint . "?" . http_build_query( $params );

		$curlHandle = curl_init( $url );
		curl_setopt( $curlHandle, CURLOPT_RETURNTRANSFER, true );
		$output = curl_exec( $curlHandle );
		curl_close( $curlHandle );
		$res = json_decode( $output, true );

		return $res;
	}

	/**
	 * Alternative method to get pages from the OpenSearch API
	 * Does not allow for searching by display title (pagename only)
	 * Formerly used in MWSuggestEntity and kept for historical reference.
	 * 
	 * @deprecated
	 * @return array
	 */
	public function getPagesFromOpenSearchAPI( $prefix, $namespace = "0", $limit = "25" ) {
		$openSearchParams = [
			"action" => "opensearch",
			"search" => $prefix,
			"limit" => $limit,
			"namespace" => $namespace,
			"format" => "json",
			"formatversion" => "2"
		];
		$openSearchQueryRes = self::getResultFromMWAPI( $openSearchParams );
		$newRes = [];
		if ( array_key_exists( 1, $openSearchQueryRes ) ) {
			foreach( $openSearchQueryRes[1] as $pageName ) {
				// @todo
				// $newRes[] = $this->formatResultItem( $pageName );
			}
		}
		return $newRes;
	}

}
