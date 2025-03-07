<?php

/*
 * Utility for retrieving an associated image as suggested by
 * the PageImages extension.
 * @todo Maybe add support for remote image repositories such as AWS.
 * @link https://www.mediawiki.org/wiki/Extension:PageImages
 */

namespace Recon\MW;

use \MediaWiki\MediaWikiServices;
use ExtensionRegistry;
use Recon\ReconUtils;

class ExtPageImages {

	private $extensionAvailable;

	public function __construct() {
		$this->extensionAvailable = ExtensionRegistry::getInstance()->isLoaded( 'PageImages' );
	}

	/**
	 * Get image thumbnail (url, width, height) from the API.
	 * Returns false if nothing was found
	 * or the extension is not installed.
	 * @param string $fullPageName
	 * @param mixed $width
	 * @return array|bool
	 */
	public function getImage( string $fullPageName, $width = 50 ) {
		if ( !$this->extensionAvailable  ) {			
			return false;
		}
		$urlBase = ReconUtils::getURLBase();
		$params = [
			"action" => "query",
			"format" => "json",
			"prop" => "pageimages",
			"titles" => $fullPageName,
			"pithumbsize" => $width,
			"pilimit" => 1
		];
		$queryStr = http_build_query( $params, "", "&" );

		$response = $this->getResponse( "{$urlBase}/api.php?{$queryStr}" );		
		if ( empty( $response ) ) {
			return false;
		}
		$pages = isset( $response["query"]["pages"] ) ? $response["query"]["pages"] : [];

		$firstKey = array_keys( $pages )[0];
		if ( isset( $pages[ $firstKey ]["thumbnail"] ) ) {
			// Example: https://en.wikipedia.org/w/api.php?action=query&prop=pageimages&titles=Albert_Einstein&pithumbsize=50
			$thumbnail = $pages[ $firstKey ]["thumbnail"];
			$path = str_replace( "{$urlBase}/", "", $thumbnail["source"] );
			$mimeAnalyzer = MediaWikiServices::getInstance()->getMimeAnalyzer();
			$mimeType = $mimeAnalyzer->guessMimeType( $path, false );
			$res = [
				"url" => $thumbnail["source"],
				"width" => $thumbnail["width"],
				"height" => $thumbnail["height"],
				"duration" => null,
				"mimetype" => $mimeType
			];
			return $res;
		}
		return false;
	}

	public function getResponse( $url ) {
		// @todo Maybe allow for POST, too
		$contents = MediaWikiServices::getInstance()->getHttpRequestFactory()->get( $url, [], __METHOD__ );
		if ( empty( $contents ) ) {
			return [];
		}
		$res = json_decode( $contents, true );
		return $res;
	}

}
