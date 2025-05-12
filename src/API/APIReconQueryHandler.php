<?php

/**
 * Helper class handling queries for the main API ('recon').
 * MW and SMW
 * 
 * @todo Support for profiles
 * @todo Flesh out matching scores
 */

namespace Recon\API;

use FormatJson;
use MediaWiki\MediaWikiServices;
use Recon\MW\MWSuggestEntity;
use Recon\SMW\SMWSuggestEntity;
use Recon\SMW\SMWUtils;
use Recon\ReconUtils;

class APIReconQueryHandler {

	private $source;
	private $profileID;
	private $substrPattern;
	private $useDisplayTitle = null;
	private $limit;
	private $isSMWStoreAvailable;
	private $wgReconAPIMaxQueries;
	private $wgReconAPIMaxResults;

	public function __construct( 
		$source,
		mixed $profileID = null,
		mixed $substrPattern = "stringprefix",
		$useDisplayTitle = null
	) {
		// Note that 'source' may get overridden by profile
		$this->source = $source;
		$this->profileID = $profileID;
		$this->substrPattern = $substrPattern;
		if ( $useDisplayTitle !== null ) {
			$this->useDisplayTitle = $useDisplayTitle;
		}
		$this->isSMWStoreAvailable = SMWUtils::isSMWStoreAvailable();
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$this->wgReconAPIMaxQueries = $config->get( "ReconAPIMaxQueries" );
		$this->wgReconAPIMaxResults = $config->get( "ReconAPIMaxResults" );
	}

	/**
	 * Takes the query string (JSON) of a reconciliation query,
	 * runs the query, and returns an output.
	 * 
	 * @link https://www.w3.org/community/reports/reconciliation/CG-FINAL-specs-0.2-20230410/#structure-of-a-reconciliation-query
	 * @param mixed $str query as JSON string
	 * @return array|mixed
	 */
	public function runQuery( $str ) {
		// Get JSON and create array
		$queries = $this->jsonDecodeAndCheck( $str );
		if ( $queries == null || empty($queries) ) {
			return [];
		}

		// Let's limit the number of queries allowed
		// unless wgReconAPIMaxQueries = null
		if ( gettype( $this->wgReconAPIMaxQueries ) == "integer" 
			&& count( $queries ) > $this->wgReconAPIMaxQueries
		) {
			$queries = array_chunk( $queries, $this->wgReconAPIMaxQueries, true );
		}

		// Handle multiple queries
		$results = [];
		foreach( $queries as $k => $v ) {
			$key = $k;
			$substring = isset( $v["query"] ) ? $v["query"] : "";

			$types = [];
			if ( isset( $v["type"] ) ) {
				$types = ( gettype( $v["type"] ) == "array" ) ? $v["type"] : [ $v["type"] ];
			}
			$properties = isset( $v["properties"] ) ? $v["properties"] : [];
			// @todo Currently ignored
			$typeStrict = $v["type_strict"] ?? "should";
			$limit = $v["limit"] ?? $this->wgReconAPIMaxResults;
			$offset = 0;
			$res = [];

			// @todo With profile
			// Support for profiles has not been implemented yet.
			$profileID = null;
			// Query result here:

			// Without profile:
			switch( $this->source ) {
				case "mw":
					$mwSuggestEntity = new MWSuggestEntity();
					// Here 'types' strictly refer to MW Categories
					if ( !empty( $types ) ) {
						$categoryNames = ReconUtils::removeNamespacePrefixFromNames( $types );
						$mwSuggestEntity->setCategories( $categoryNames );
					}
					if( !empty( $properties ) ) {
						$mwSuggestEntity->setProperties( $properties );
					}
					$res = $mwSuggestEntity->run(
						$substring,
						$this->substrPattern,
						$this->useDisplayTitle,
						$profileID,
						0,
						intval($limit)
					);
				break;
				case "smw":
					if ( $this->isSMWStoreAvailable ) {
						$smwSuggestEntity = new SMWSuggestEntity();
						$smwSuggestEntity->setOptions(
							intval($offset),
							intval($limit),
							false
						);
						// concept not supported
						$res = $smwSuggestEntity->run(
							$substring,
							$this->substrPattern,
							null,
							$this->useDisplayTitle,
							$profileID,
							$types,
							$properties
						);
					}
				break;
			}
			$results[$key] = [];
			$results[$key]["result"] = $res["result"] ?? [ "substr" => $substring ];
			// The following is used mostly for debugging / diagnostics.
			$results[$key]["meta"] = $res["meta"] ?? [];
			$results[$key]["meta"]["query"] = [
				"query" => $substring,
				"types" => $types,
				"properties" => $properties,
				"limit" => $limit,
				"offset" => $offset
			];
		}
		return $results;
	}

	public function jsonDecodeAndCheck( $str ) {
		$res = FormatJson::parse( $str, FormatJson::FORCE_ASSOC | FormatJson::TRY_FIXING );
		$data = $res->getValue();
		if ( !is_array( $data ) || json_last_error() !== JSON_ERROR_NONE ) {
			return [];
		}
		return $data;
	}

	/**
	 * Simple mechanism for 'scoring' how well a candidate matches the query
	 * @todo To be crystallised in the future.
	 * @todo Cater for multiple names incl. alternative labels
	 * 
	 * @param mixed $substring
	 * @param mixed $pageName - fullpagename or pagename?
	 * @param mixed $displayTitle
	 * 
	 * @return array
	 */
	public static function getRelevancyDataForCandidate( 
		$substring,
		$pageName,
		$displayTitle,
		$namespaceName = null
	) {
		$substring = mb_detect_encoding( $substring ) == "ASCII" ? html_entity_decode( $substring ) : $substring;
		$substring = str_replace( "_", " ", $substring );
		$lcSubstring = strtolower( $substring );
		$lcPageName = strtolower( $pageName );
		// Ignore html formatting such as italics
		$displayTitle = strip_tags( html_entity_decode( $displayTitle ) );
		$lcDisplayTitle = strtolower( $displayTitle );		

		if ( $namespaceName !== null ) {
			// Not entirely perfect because namespace names may be language-dependent
			$isFullMatch = $substring === "{$namespaceName}:{$pageName}" || $substring === $pageName || $substring === $displayTitle;
		} else {
			$isFullMatch = $substring === $pageName || $substring === $displayTitle;
		}

		$isLowerCaseMatch = ( $lcSubstring == $lcPageName || $lcSubstring == $lcDisplayTitle ) ? true : false;
		$isInitialMatch = str_starts_with( $lcPageName, $lcSubstring ) || str_starts_with( $lcDisplayTitle, $lcSubstring );

		$score = $isFullMatch 
			? 100 
			: ( $isLowerCaseMatch 
				? 80
				: ( $isInitialMatch ? 70 : 50 )
			);
		return [ $isFullMatch, $isLowerCaseMatch, $score ];
	}

}
