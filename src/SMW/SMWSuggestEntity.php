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

class SMWSuggestEntity {

	private $store;
	private $stringCondition;
	private $smwgEnabledFulltextSearch;
	private $resultOffset = 0;
	private $resultLimit = 25;
	private $substringPattern;
	// whether to format results for Page Forms (pfautocomplete)
	private $formatForPageForms = false;

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
		$smwQueryBuilder = new SMWQueryBuilder();
		$smwQueryBuilder->setOptions( $this->resultOffset, $this->resultLimit );
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
