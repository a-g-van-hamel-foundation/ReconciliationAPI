<?php

/**
 * Let SMW suggest entities based on query statement and
 * variable substring/prefix.
 * Used for the Suggest Entity service as well as for similar purposes.
 * @note Objects with fragment identifiers like subobjects are excluded.
 * 
 * To match at beginning of string, a HAVING clause might work 
 * for MATCH ... AGAINST
 * @link https://stackoverflow.com/questions/20621024/mysql-fulltext-search-for-words-at-beginning-or-end-of-string
 */

namespace Recon\SMW;

use \SMW\StoreFactory;
use \SMW\StringCondition;
use Recon\ReconUtils;
use Recon\StringModification\StringModifier;
use Recon\SMW\SMWQueryBuilder;
use Recon\SMW\SMWQuerySyntaxConverters;
use Recon\SMW\SMWResultFormatter;

class SMWSuggestEntity {

	private $store;
	private $stringCondition;
	private $smwgEnabledFulltextSearch;
	private $resultOffset = 0;
	private $resultLimit = 25;
	private $substringPattern;
	// whether to format results for Page Forms (pfautocomplete)
	private $formatForPageForms = false;
	private $computedBatchCount;

	public function __construct() {
		$this->stringCondition = StringCondition::COND_PRE;
		global $smwgEnabledFulltextSearch;
		$this->smwgEnabledFulltextSearch = $smwgEnabledFulltextSearch;
	}

	public function setOptions(
		$offset = 0,
		$limit = 25,
		$formatForPageForms = 0
	) {
		$this->resultOffset = $offset;
		$this->resultLimit = $limit;
		$this->formatForPageForms = ( $formatForPageForms === 1 ) ? true : false;
	}

	/**
	 * Best executed after setting options
	 * @param mixed $substring
	 * @param mixed $substringPattern
	 * @param mixed $concept - @deprecated @todo should be removed in favour of $types
	 * @param mixed $useDisplayTitle - null (default) or true/false if set through URL
	 * @param mixed $profileID
	 * @param mixed $offset
	 * @param mixed $limit
	 * @param array $types - allows for 'types' (classes, concepts, categories) to be added to the query
	 * @param array properties - actually property-value pairs
	 * @return array
	 */
	public function run(
		$substring,
		$substringPattern = null,
		$concept = null,
		mixed $useDisplayTitle = null,
		$profileID = null,
		$types = [],
		$properties = []
	) {
		// Unfortunately, we need to run this query separately from 
		// the main one
		$exactMatches = $this->runQueryForExactMatch( $substring, $useDisplayTitle, $profileID, $types, $properties, $concept );

		$smwQueryBuilder = new SMWQueryBuilder();
		$smwQueryBuilder->setOptions(
			$this->resultOffset,
			$this->resultLimit
		);
		$this->substringPattern = $substringPattern ?? "tokenprefix";

		$qRes = $smwQueryBuilder->run(
			$substring,
			$this->substringPattern,
			$concept,
			$useDisplayTitle,
			$profileID,
			null,
			$types,
			$properties
		);
		$this->computedBatchCount = $qRes["meta"]["resultBatchCount"];

		if ( count($exactMatches) > 0 && $this->resultOffset === 0 ) {
			$qRes["result"] = $this->mergeExactMatchIntoQueryResult( $qRes["result"], $exactMatches );
		} elseif( count($exactMatches) > 0 ) {
			// @todo 
			// Exact match not returned when offset is not 0, 
			// or > limit, but offset may need to be adjusted,
			// which is complicated by the possibility of duplicates
			// Consider extraction?
			foreach( $qRes["result"] as $k => $res ) {
				if( $res["id"] === $exactMatches[0]["id"] ) {
					array_splice( $qRes["result"], $k, 1 );
					$this->computedBatchCount--;
				}
			}
		}

		// Update batch count
		$qRes["meta"]["resultBatchCount"] = $this->computedBatchCount;

		if ( $this->formatForPageForms ) {
			return ReconUtils::formatResultForPageForms( $qRes );
		}

		$res = [
			"result" => $qRes["result"],
			"meta" => $qRes["meta"]
		];
		return $res;
	}

	/**
	 * Add exact match to start and return new array
	 * @param array $qResults - corresponds to qRes["result"]
	 * @param array $exactMatches
	 */
	private function mergeExactMatchIntoQueryResult( $qResults, $exactMatches ) {
		if ( count($exactMatches) === 0 ) {
			return $qResults;
		}

		// Remove duplicate if any
		$countRemoved = 0;
		foreach( $qResults as $k => $res ) {
			if( $res["id"] === $exactMatches[0]["id"] ) {
				array_splice( $qResults, $k, 1 );
				$countRemoved++;
				$this->computedBatchCount--;
			}
		}
		// Assumption: there should be only a single exact match

		// No duplicate found? Remove final item
		if ( $countRemoved === 0 && count($qResults) > 0 ) {
			array_splice( $qResults, count($qResults) - 1, 1 );
			$this->computedBatchCount--;
		}
		// Add exact match to start
		array_unshift( $qResults, $exactMatches[0] );
		$this->computedBatchCount++;

		return $qResults;
	}

	/**
	 * Support 6.4: "supplying an entity identifier as prefix 
	 * should return this entity in the suggest response"
	 * @return array
	 */
	private function runQueryForExactMatch(
		$substring,
		$useDisplayTitle,
		$profileID,
		$types,
		$properties,
		$concept = null
	) {
		$smwQueryBuilder = new SMWQueryBuilder();
		$smwQueryBuilder->setOptions(
			0,
			$this->resultLimit,
			$substring,
			"exactpagename",
			$useDisplayTitle,
			true
		);

		// Set class props and create raw query for SMW
		if( isset( $profileID ) ) {
			// substring unknown
			$rawQuery = $smwQueryBuilder->setProfileAndGetRawQuery( $profileID );
		} elseif( $concept !== null && isset( $concept ) ) {
			$rawQuery = $smwQueryBuilder->getRawQueryForConcept( $concept );
		} else {
			// Create raw query condition
			$rawTypeQuery = SMWQuerySyntaxConverters::translateTypesToSMWSyntax( $types );
			$rawPropValQuery = SMWQuerySyntaxConverters::translatePropValPairsToSMWSyntax( $properties );
			$fromQuery = trim($rawTypeQuery . $rawPropValQuery ) == ""
				? "" // "[[Modification date::+]]"
				: $rawTypeQuery . $rawPropValQuery;
			$rawQuery = "{$fromQuery} [[{$substring}]]";
		}

		// Formatting using printout properties
		if ( $useDisplayTitle !== null ) {
			if ( $useDisplayTitle ) {
				// @todo
				//$this->labelProperty = $useDisplayTitle;
			}
		}

		// Run the query
		$queryRes = $smwQueryBuilder->getResultForQuery( $rawQuery );
		return $smwQueryBuilder->formatQueryResult( $queryRes );
	}

	/**
	 * @deprecated Deprecated?
	 * @param mixed $pages
	 * @param mixed $stripTags
	 * @return array{id: mixed, label: mixed[]}
	 */
	private static function rearrangeValuesWithPageAndLabel( $pages, $stripTags = false ) {
		$res = [];
		foreach( $pages as $k => $v ) {
			$res[] = [
				"id" => $k,
				"label" => $stripTags ? StringModifier::stripTags( $v ) : $v
			];
		}
		return $res;
	}

}
