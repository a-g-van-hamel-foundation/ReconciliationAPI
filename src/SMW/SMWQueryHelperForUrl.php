<?php

namespace Recon\SMW;

use MediaWiki\MediaWikiServices;
use MediaWiki\MainConfigNames;

class SMWQueryHelperForUrl {

	public function __construct() {
		//
	}

	/**
	 * Helper function for #recon-smwquery-url
	 * @param mixed $str
	 * @return
	 */
	public static function convertQueryToJSONObject(
		string $queryCondition,
		string $searchProperty,
		string $labelProperty,
		string $descriptionProperty
	): string {
		// JSON object
		$suggestEntity = [
			"smwquery" => [
				"statement" => [
					[
						"from" => $queryCondition,
						"where" => "[[{$searchProperty}::~@@@]]",
						"substringPattern" => "allchars"
					]
				]
			],
			"output" => [
				"name" => [
					"smwproperty" => $labelProperty,
					"hideNamespacePrefix" => true
				],
				"description" => [
					"smwproperty" => $descriptionProperty
				]
			]
		];

		$canonServer = MediaWikiServices::getInstance()->getUrlUtils()->getCanonicalServer();
		$scriptPath = MediaWikiServices::getInstance()->getMainConfig()->get( MainConfigNames::ScriptPath );
		$urlBase = $canonServer . $scriptPath . "/api.php?";
		$jsonStr = json_encode( $suggestEntity, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE );
		$queryStr = http_build_query( [
			"action" => "recon-suggest-entity",
			"format" => "json",
			"formatversion" => "2",
			"origin" => "*",
			"source" => "smw",
			"query" => $jsonStr,
			"substr" => ""
		], "", "&" );
		$url = $urlBase . $queryStr;
		return $url;
	}

}
