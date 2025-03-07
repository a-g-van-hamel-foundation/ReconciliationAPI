<?php

/**
 * Suggest entities from a MediaWiki namespace or category based on substring/prefix.
 * 
 * Results are formatted following the 'Suggest entities response schema':
 * 'id': full page name
 * 'name': either display title or page name, with or without prefix.
 * 'description': none (on its own, MediaWiki does not provide a 'description' field)
 * 'type': MediaWiki category
 * 'match'
 * 'score'
 * @link https://www.w3.org/community/reports/reconciliation/CG-FINAL-specs-0.2-20230410/#suggest-entities-response-json-schema
 * along with additional data
 */

namespace Recon\MW;

use \MediaWiki\MediaWikiServices;
use MediaWiki\Config\Config;
use Title;
use Recon\API\APIMWUtils;
use Recon\MW\MWPageQueryBuilder;
use Recon\MW\MWSuggestEntityFormatter;
use Recon\Config\ReconConfig;

class MWSuggestEntity {

	private $mainConfig;
	private $substring;
	private $substringPattern;
	private $possibleSubstringPatterns = [ "tokenprefix", "stringprefix", "allchars", "exact" ];
	private $profileID = false;
	private $profilePage = false;
	// array or null:
	private $namespaces = [];
	private $categories = [];
	private $languageCode;
	private $useDisplayTitle;
	private $hideNamespacePrefix = true;
	private $usePageImages = false;
	private $resultLimit = 25;
	private $resultOffset = 0;

	public function __construct() {
		$this->substring = "";
		$this->mainConfig = MediaWikiServices::getInstance()->getMainConfig();
		// Default (remove because also done by run()?):
		$this->useDisplayTitle = $this->mainConfig->get( 'ReconAPIMWUseDisplayTitle' );
	}

	public function run(
		string $substring = "",
		mixed $substringPattern = "tokenprefix",
		mixed $useDisplayTitle = null,
		mixed $profileID = false,
		mixed $resultOffset = 0,
		mixed $resultLimit = 25
	) {
		// @todo
		$this->resultOffset = intval($resultOffset);
		$this->resultLimit = intval($resultLimit);
		$this->substring = $substring;
		$this->substringPattern = ( $substringPattern !== null ) && in_array( $substringPattern, $this->possibleSubstringPatterns )
			? $substringPattern
			: "tokenprefix";

		if( isset( $profileID ) && $profileID ) {
			// With profile
			$this->setConfigDataFromProfile( $profileID );
		} else {
			// Without profile
			$this->useDisplayTitle = $useDisplayTitle !== null
				? $useDisplayTitle
				: $this->mainConfig->get( 'ReconAPIMWUseDisplayTitle' );
		}

		$mwPageQueryBuilder = new MWPageQueryBuilder(
			$this->useDisplayTitle,
			$this->substringPattern,
			$this->resultLimit
		);

		$pages = [];
		$rawRes = null;
		if( !empty( $this->categories ) ) {
			// Categories, or categories and namespaces
			$mwPageQueryBuilder->setOptions( 
				$this->resultOffset,
				// Set to higher number because first
				// we need to loop through the results
				1000,
				$this->substringPattern
			);
			// @todo : make depth (here 5) configurable
			$categoryStr = implode( ",", $this->categories );
			$queriedPages = $mwPageQueryBuilder->getAllPagesForCategory(
				$categoryStr,
				5,
				$substring,
				$this->substringPattern,
				$this->namespaces
			);
			// Extract relevant array items since
			// getAllPagesForCategory() does not accept offset/limit
			$pages = [];
			$keys = array_keys( $queriedPages );			
			for( $i = $this->resultOffset; $i < ( $this->resultLimit + $this->resultOffset ); $i++ ) {
				if ( isset( $keys[$i] ) && isset( $queriedPages[ $keys[$i] ] ) ) {
					$pages[ $keys[$i] ] = $queriedPages[ $keys[$i] ];
				}
			}
		} elseif ( !empty( $this->namespaces ) ) {
			// Namespaces only
			$mwPageQueryBuilder->setOptions( 
				$this->resultOffset,
				$this->resultLimit,
				$this->substringPattern
			);
			// Remove:
			$namespaceStr = implode( ",", array: $this->namespaces );
			$rawRes = $mwPageQueryBuilder->getAllPagesForNamespace(
				$namespaceStr,
				$substring,
				$this->substringPattern,
				[],
				true
			);
		} else {
			// without either category or namespace
			// @temp
			if ( $this->substring !== "" ) {
				// $mwPageQueryBuilder->setOptions();
				$mwPageQueryBuilder->setOptions( 
					$this->resultOffset,
					$this->resultLimit,
					$this->substringPattern
				);
				$queryOptions = [
					"limit" => $this->resultLimit,
					"offset" => $this->resultOffset
				];
				$rawRes = $mwPageQueryBuilder->getAllPages(
					$substring,
					$this->substringPattern,
					$this->useDisplayTitle,
					$queryOptions,
					true
				);
			}
		}

		// Now format or re-format
		$formattedRes = [];
		$consolidateRedirects = MediaWikiServices::getInstance()->getMainConfig()->get( 'ReconAPIConsolidateRedirects' );
		$mwSuggestEntityFormatter = new MWSuggestEntityFormatter(
			$this->usePageImages,
			$this->useDisplayTitle,
			$this->substring,
			$consolidateRedirects,
			$this->hideNamespacePrefix
		);
		if ( $rawRes !== null ) {
			// Using rawRes where possible
			$formattedRes = $mwSuggestEntityFormatter->formatResults( $rawRes, [] );
		} else {
			// ...Or else using preformatted $pages
			// (we're moving away from this approach)
			foreach( $pages as $k => $v ) {
				$formattedRes[] = $mwSuggestEntityFormatter->formatResultItem( $k, $v, $substring );
			}
		}

		$res = [
			"result" => $formattedRes,
			"meta" => [
				"source" => "mw",
				"substring" => $substring,
				"substringPattern" => $this->substringPattern,
				"namespaces" => $this->namespaces,
				"categories" => $this->categories,
				"useDisplayTitle" => $this->useDisplayTitle,
				"hideNamespacePrefix" => $this->hideNamespacePrefix ? 1 : 0,
				"usePageImages" => $this->usePageImages ? 1 : 0,
				"resultLimit" => $this->resultLimit,
				"resultOffset" => $this->resultOffset,
				"resultBatchCount" => count( $formattedRes )
			]
		];
		if ( $this->profileID ) {
			$res["profileID"] = $this->profileID;
			$res["profilePage"] = $this->profilePage;
		}
		return $res;
	}

	/**
	 * Fetch profile and use it to set config data
	 * @param mixed $profileID
	 * @return void
	 */
	private function setConfigDataFromProfile( $profileID ) {
		$this->profileID = intval( $profileID );
		$reconConfig = new ReconConfig( $this->profileID );
		$this->profilePage = $reconConfig->getFullPageName() ?? false;

		// Something wrong with the output section of this profile
		$profile = $reconConfig->getProfile();

		if ( isset( $profile["mwquery"]["namespaces"] ) ) {
			// @todo Maybe trim
			$nss = $profile["mwquery"]["namespaces"];
			$this->namespaces = gettype( $nss ) == "array"
				? $nss
				: explode( ",", $nss );
		}
		if ( isset( $profile["mwquery"]["categories"] ) ) {
			$cats = $profile["mwquery"]["categories"];
			$this->categories = gettype( $cats ) == "array"
				? $cats
				: explode( ",", $cats );
		}
		if( isset( $profile["mwquery"]["substringPattern"] ) ) {
			$this->substringPattern = $profile["mwquery"]["substringPattern"];
		}

		$output = $reconConfig->getOutput();
		if( isset( $output["name"]["useDisplayTitle"] ) ) {
			$this->useDisplayTitle = $output["name"]["useDisplayTitle"];
		}
		if( isset( $output["name"]["hideNamespacePrefix"] ) ) {
			$this->hideNamespacePrefix = $output["name"]["hideNamespacePrefix"];
		}
		if( isset( $output["image"]["extension"] ) ) {
			$this->usePageImages = $output["image"]["extension"] === "PageImages";
		}		

	}

	/**
	 * Set namespaces to be searched
	 * @param string $namespaces
	 * @return void
	 */
	public function setNamespaces( string $namespaces ) {
		$namespaceArr = explode( ",", $namespaces );
		$this->namespaces = $namespaceArr;
	}

	/**
	 * Set categories to instance
	 * @param string|array $categories
	 * @return void
	 */
	public function setCategories( mixed $categoryNames ) {
		if ( gettype( $categoryNames ) == "string" ) {
			$this->categories = explode( ",", $categoryNames );
		} else {
			// Assuming arrays
			$this->categories = $categoryNames;
		}
	}

	/**
	 * Set property-value pairs from 'queries' parameters,
	 * translate them to MW's native categories and namespaces,
	 * and apply them to the query.
	 * Adds to, does not override, anything set previously.
	 * @param array $properties
	 * @return void
	 */
	public function setProperties( array $properties ) {
		$categories = $namespaces = [];
		foreach( $properties as $pair ) {
			if ( !isset( $pair["pid"] ) || !isset( $pair["v"] ) ) {
				continue;
			}
			switch( $pair["pid"] ) {
				// @todo localisation
				case "category":
				case "Category":
					$this->categories[] = $pair["v"];
				break;
				case "namespace":
				case "Namespace":
					$this->namespaces[] = $pair["v"];
				break;
			}
		}

	}

}
