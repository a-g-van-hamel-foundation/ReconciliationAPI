<?php

/**
 * Get wiki pages by SQL query:
 * (a) any (getAllPages)
 * (b) from namespaces (getAllPagesForNamespace)
 * (c1) from categories (getAllPagesForCategory)
 * (c2) from categories and namespaces (getAllPagesForCategory)
 * 
 * Large parts adapted from Page Forms (PF_ValuesUtils)
 */

namespace Recon\MW;

use Title;
use MWException;
use MediaWiki\MainConfigNames;
use \MediaWiki\MediaWikiServices;
use \Wikimedia\Rdbms\SelectQueryBuilder;
use Recon\MW\MWUtils;
use Recon\MW\MWNamespaceUtils;
use Recon\MW\MWDBUtils;

class MWPageQueryBuilder {

	// Database (read-only, replica):
	private $dbr;
	// Namespace names as comma-separated string:
	private $mainConfig;
	private $namespaces = null;
	private $namespaceIndexes = null;
	// Category names (without Category: prefix) as comma-separated string:
	private $categories = null;
	private $substringPattern;
	// Autocomplete on display title (bool):
	private $useDisplayTitle;
	// Result limit:
	private $resultLimit = 25;
	private $resultOffset = 0;

	// Site language ($wgLanguageCode)
	private $languageCode;

	private $queryBuilder = false;
	private $tables;
	private $columns;
	private $conditions;

	/**
	 * Some of these should be allowed to be set by getAllPagesForNamespace
	 * @param mixed $useDisplayTitle
	 * @param mixed $substringPattern
	 * @param mixed $resultLimit
	 */
	public function __construct(
		$useDisplayTitle = false,
		$substringPattern = "tokenprefix",
		$resultLimit = 25
	) {
		$this->dbr = MWDBUtils::getReadDB();
		$this->namespaces = null;
		$this->categories = null;
		$this->useDisplayTitle = $useDisplayTitle;
		$this->substringPattern = $substringPattern;
		$this->resultLimit = intval( $resultLimit );
		$this->mainConfig = MediaWikiServices::getInstance()->getMainConfig();
		$this->languageCode = $this->mainConfig->get( MainConfigNames::LanguageCode );
	}

	public function setOptions(
		$resultOffset = null,
		$resultLimit = null,
		$substringPattern = null
	) {
		if ( $resultOffset !== null ) {
			$this->resultOffset = $resultOffset;
		}
		if ( $resultLimit !== null ) {
			$this->resultLimit = $resultLimit;
		}
		if ( $substringPattern !== null ) {
			$this->substringPattern = $substringPattern;
		}
	}

	/**
	 * Sets namespaces as well as their indexes to class variables
	 * 
	 * @param array $namespaces
	 * @return void
	 */
	private function setNamespacesAndIndexes( mixed $namespaces ) {
		if( $namespaces == null || empty( $namespaces ) ) {
			return;
		}
		// special sugar for content namespaces
		if( $namespaces[0] == "_contentNamespaces" ) {
			$namespaces = $this->mainConfig->get( "ContentNamespaces" );
		}
		$this->namespaces = $namespaces;
		$this->namespaceIndexes = MWUtils::getNamespaceIndexes( $namespaces );
	}

	/**
	 * Get pages that match on substring
	 * @param mixed $substring
	 * @param string $substringPattern
	 * @param bool $useDisplayTitle
	 * @param array $queryOptions
	 * @param bool $getRaw - Get the unprocessed result
	 * @return mixed
	 */
	public function getAllPages(
		mixed $substring = null,
		string $substringPattern = "stringprefix",
		bool $useDisplayTitle = false,
		array $queryOptions = [],
		bool $getRaw = true
	) {
		$this->substringPattern = $substringPattern;
		$this->useDisplayTitle = $useDisplayTitle;
		if ( $substring == null ) {
			return null;
		}

		$table = "page";
		$columns = [ "page_title", "page_namespace", "page_is_redirect", "page_id" ];
		$likeConditions = [];
		list( $columns, $leftJoins ) = $this->setupBaseSQLQueryForDefaultSort( $columns, [] );

		if ( $this->useDisplayTitle ) {
			list( $columns, $leftJoins ) = $this->setupBaseSQLQueryForDisplayTitle( $columns, $leftJoins );
			$likeConditions[] = $this->createSubstringCondition( $substring, [], true, false );
		} else {
			// @todo ???
			$likeConditions[] = MWDBUtils::getSQLConditionForAutocompleteInColumn(
				"page_title",
				$substring,
				true,
				$this->substringPattern,
				$this->dbr
			);
		}

		// Exact identifier match
		$exactConditions = MWDBUtils::getExactSQLConditionForAutocompleteInColumn( "page_title", $substring );

		$conditions = $this->dbr->makeList([
			$this->dbr->makeList( $likeConditions, LIST_AND ),
			$exactConditions
		], LIST_OR );

		// Options
		$orderBy = "pp_defaultsort_value";
		$limit = isset( $queryOptions["limit"] ) ? $queryOptions["limit"] : $this->resultLimit;

		/* Uncomment for debugging.
		$sqlStuff = [ "tables" => $table, "columns" => $columns, "limit" => $limit, "orderBy" => $orderBy, "leftJoins" => $leftJoins, "conditions" => $conditions ];
		*/

		$build = $this->dbr->newSelectQueryBuilder()
			->fields( $columns )
			->table( $table );
		foreach( $leftJoins as $leftJoin ) {
			$build->leftJoin( $leftJoin[0], $leftJoin[1], $leftJoin[2] );
		}
		$build->where( $conditions )
			->orderBy( $orderBy, SelectQueryBuilder::SORT_ASC )
			->limit( $limit )
			->caller( __METHOD__ );
		$res = $build->fetchResultSet();
		if ( $getRaw ) {
			return $res;
		} else {
			$formattedRes = $this->formatNamespaceResults( $res, [] );
			return $formattedRes;	
		}
	}

	/**
	 * Get pages from namespace given a particular substring
	 * @param mixed $namespaceStr
	 * @param mixed $substring
	 * @param string $substringPattern
	 * @param array $options may include "limit"?
	 * @param bool $getRaw - get the raw, non-preformatted result
	 * @return array
	 */
	public function getAllPagesForNamespace(
		string $namespaceStr,
		mixed $substring = null,
		string $substringPattern = "stringprefix",
		array $queryOptions = [],
		bool $getRaw = false
	) {
		$this->substringPattern = $substringPattern;

		// Get data for the namespaces to be searched
		$mwNamespaceUtils = new MWNamespaceUtils();
		list( $namespaceNames, $namespaceIndexes ) = $mwNamespaceUtils->getNamespaceInfoForQuery( $namespaceStr );
		$this->namespaceIndexes = $namespaceIndexes;

		if ( isset( $queryOptions["limit"] ) ) {
			$this->resultLimit = intval($queryOptions["limit"]);
		}
		if ( isset( $queryOptions["offset"] ) ) {
			$this->resultOffset = intval($queryOptions["offset"]);
		}

		// Get all the elements to build the syntax of the SQL query
		$namespaceConditions = $this->getSQLConditionsFromNamespaceIndexes( $namespaceIndexes );
		list( $tables, $columns, $likeConditions, $options, $join, $leftJoins ) = $this->getBuildingBlocksForSQLQueryOnNamespaces( $substring, $namespaceIndexes, $namespaceConditions );

		$exactConditions = MWDBUtils::getExactSQLConditionForAutocompleteInColumn(
			"page_title",
			$substring,
			$this->dbr,
			$namespaceIndexes
		);
		$conditions = [ $this->dbr->makeList( [
			$this->dbr->makeList( $likeConditions, LIST_AND ),
			$exactConditions
		], LIST_OR ) ];

		// Now run the query
		// Older method where res = Wikimedia\Rdbms\MysqliResultWrapper:
		// $res = $this->dbr->select( $tables, $columns, $conditions, __METHOD__, $options, $join );

		// Modern version
		$build = MWDBUtils::selectQueryBuilder(
			$this->dbr->newSelectQueryBuilder(),
			$tables,
			$columns,
			[],
			$leftJoins,
			[],
			$conditions,
			$options
		);
		// @dev check with: $build->getQueryInfo();

		// Fetch instance of Wikimedia\Rdbms\MysqliResultWrapper
		$resultSet = $build->fetchResultSet();

		if ( $getRaw ) {
			return $resultSet;
		} else {
			$formattedRes = $this->formatNamespaceResults( $resultSet, $namespaceNames );
			return $formattedRes;
		}
	}

	/**
	 * Array of SQL conditions for given namespace indexes
	 * format 'page_namespace = index'
	 * @todo convert to newer syntax
	 * ->where( $dbr->expr( 'page_namespace', '=', $namespaceCode ) )
	 * @param array $namespaceIndexes
	 * @return string[]
	 */
	private function getSQLConditionsFromNamespaceIndexes( array $namespaceIndexes ) {
		$namespaceConditions = array_map(
			static function ( $ns ) { return "page_namespace = $ns"; },
			$namespaceIndexes
		);
		return $namespaceConditions;
	}

	/**
	 * Returns tables, columns, conditions, options and join
	 * required for SQL query.
	 * Currently for namespace query only.
	 * @param mixed $substring
	 * @param mixed $namespaceIndexes
	 * @param mixed $namespaceConditions
	 * @return array
	 */
	public function getBuildingBlocksForSQLQueryOnNamespaces(
		mixed $substring,
		array $namespaceIndexes,
		array $namespaceConditions
	): array {

		$conditions = $leftJoins = [];
		$conditions[] = implode( " OR ", $namespaceConditions );

		if ( $this->useDisplayTitle ) {
			$tables = [ "page" ];
			$columns = [ "page_title", "page_namespace", "page_is_redirect", "page_id" ];
			list( $tables, $columns, $join, $leftJoins ) = $this->setupBaseSQLQuery( $tables, $columns );
			if ( $substring != null ) {
				// @todo getAllCategories???????
				$conditions[] = $this->createSubstringCondition( $substring, $namespaceIndexes, false );
			}
		} else {
			// No displaytitle
			$tables = [ "page" ];
			$columns = [ "page_title", "page_namespace", "page_is_redirect", "page_id" ];
			$join = $leftJoins = [];
			if ( $substring != null ) {
				$conditions[] = MWDBUtils::getSQLConditionForAutocompleteInColumn(
					"page_title",
					$substring,
					true,
					$this->substringPattern,
					$this->dbr
				);
			}
		}
		$options = [
			"LIMIT" => $this->resultLimit,
			"OFFSET" => $this->resultOffset
		];
		return [ $tables, $columns, $conditions, $options, $join, $leftJoins ];
	}

	/**
	 * Base query elements for defaultsort
	 * Helper function used for eg getAllPages, getAllPagesForNamespace 
	 * and getAllPagesForCategory
	 * @param array $tables
	 * @param array $columns
	 * @return array
	 */
	public function setupBaseSQLQueryForDefaultSort( array $columns, array $leftJoins ) {
		$columns["pp_defaultsort_value"] = "pp_defaultsort.pp_value";
		//$leftJoins['pp_defaultsort'] = [ ... ];
		// @todo IDatabase::addQuotes()
		$leftJoins[] = [
			"page_props",
			"pp_defaultsort", 
			[
				"pp_defaultsort.pp_page = page_id",
				"pp_defaultsort.pp_propname = 'defaultsort'"
			]
		];
		return [ $columns, $leftJoins ];
	}

	/**
	 * Compatible with newSelectQueryBuilder
	 * @param array $columns
	 * @param array $leftJoins
	 * @return array
	 */
	public function setupBaseSQLQueryForDisplayTitle( array $columns, array $leftJoins ) {
		$columns["pp_displaytitle_value"] = "pp_displaytitle.pp_value";
		// associative? $leftJoins['pp_displaytitle'] = [ ... ];
		// @todo IDatabase::addQuotes()
		$leftJoins[] = [
			"page_props",
			"pp_displaytitle",
			[
				"pp_displaytitle.pp_page = page_id",
				"pp_displaytitle.pp_propname = 'displaytitle'"
			]
		];
		return [ $columns, $leftJoins ];
	}

	/**
	 * Base query elements when searching pages by displaytitle
	 * defaultsort and optionally displaytitle
	 * Used for both getAllPagesForNamespace and getAllPagesForCategory
	 * @param array $tables
	 * @param array $columns
	 * @return array
	 */
	public function setupBaseSQLQuery( array $tables, array $columns ) {
		$tables["pp_defaultsort"] = "page_props";
		$columns["pp_defaultsort_value"] = "pp_defaultsort.pp_value";
		$joins = [];
		$joins["pp_defaultsort"] = [
			"LEFT JOIN", [
				"pp_defaultsort.pp_page = page_id",
				"pp_defaultsort.pp_propname = 'defaultsort'"
			]
		];

		if ( $this->useDisplayTitle ) {
			$tables["pp_displaytitle"] = "page_props";
			$columns["pp_displaytitle_value"] = "pp_displaytitle.pp_value";
			$joins["pp_displaytitle"] = [
				"LEFT JOIN", [
					"pp_displaytitle.pp_page = page_id",
					"pp_displaytitle.pp_propname = 'displaytitle'"
				]
			];
		}

		$leftJoins = [
			[
				"page_props",
				"pp_displaytitle",
				[
					"pp_displaytitle.pp_page = page_id",
					"pp_displaytitle.pp_propname = 'displaytitle'"
				]
			],
			[
				"page_props",
				"pp_defaultsort",
				[
					"pp_defaultsort.pp_page = page_id",
					"pp_defaultsort.pp_propname = 'defaultsort'"
				]
			]
		];

		return [ $tables, $columns, $joins, $leftJoins ];
	}

	/**
	 * @deprecated Moved to setupBaseSQLQuery
	 */
	public function setupBaseSQLQueryForDisplayTitleOLD( array $tables, array $columns ) {
		return $this->setupBaseSQLQuery( $tables, $columns );
	}

	/**
	 * Returns a SQL condition for autocompletion substring value in a column.
	 * @deprecated Use MWDBUtils::getSQLConditionForAutocompleteInColumn
	 *
	 * @param string $column Value column name
	 * @param string $substring Substring to look for
	 * @param bool $replaceSpaces
	 * @return string SQL condition for use in WHERE clause
	 */
	public function getSQLConditionForAutocompleteInColumn( string $column, string $substring, bool $replaceSpaces = true ): string {
		return MWDBUtils::getSQLConditionForAutocompleteInColumn( $column, $substring, $replaceSpaces, $this->substringPattern, $this->dbr );
	}

	/**
	 * Re-format arrays for getAllPagesForNamespace() and 
	 * maybe getAllPages()
	 * Formats query results as associative array of
	 * pagenames (keys) and labels (values)
	 * Adapted from Page Forms
	 * @param $res (Wikimedia\Rdbms\MysqliResultWrapper)
	 * @param $namespaceNames = names of namespaces to include
	 * @return array
	 */
	public function formatNamespaceResults( $res, $namespaceNames ) {
		$pages = $sortkeys = [];
		if ( !$res ) {
			return [];
		}
		while ( $res && $row = $res->fetchRow() ) {
			// $row['page_is_redirect']
			$titleNoPrefix = str_replace( "_", " ", $row["page_title"] );
			if ( array_key_exists( "page_namespace", $row ) ) {
				$actualTitle = Title::newFromText( $row["page_title"], $row["page_namespace"] );
				$title = $actualTitle->getPrefixedText();
			} else {
				$title = $titleNoPrefix;
			}
			if ( array_key_exists( "pp_displaytitle_value", $row )
				&& ( $row["pp_displaytitle_value"] ) !== null 
				&& trim( str_replace( "&#160;", "", strip_tags( $row["pp_displaytitle_value"] ) ) ) !== ""
			) {
				$pages[ $title ] = htmlspecialchars_decode( $row["pp_displaytitle_value"], ENT_QUOTES );
			} else {
				// Pagename. Include namespace prefix only
				// if there is more than one namespace
				$fromMultipleNamespaces = count( $namespaceNames ) > 1;
				$pages[ $title ] = $fromMultipleNamespaces
					? $title
					: $titleNoPrefix;
			}
			if ( array_key_exists( "pp_defaultsort_value", $row )
				&& ( $row["pp_defaultsort_value"] ) !== null
			) {
				$sortkeys[$title] = $row["pp_defaultsort_value"];
			} else {
				$sortkeys[$title] = $title;
			}
		}
		$res->free();

		// @todo Should we use fixedMultiSort() ?
		array_multisort( $sortkeys, $pages );
		return $pages;
	}

	/**
	 * Get all the pages that belong to a category and all its
	 * subcategories, down a certain number of levels.
	 * heavily based on PF and SMW's previous SMWInlineQuery::includeSubcategories()
	 * Returns associative array where k = pagename and v = displaytitle or pagename
	 * 
	 * @param string $topCategory - string, comma-separated if multiple
	 * @param int $categoryDepth
	 * @param string|null $substring
	 * @param string $substringPattern
	 * @param array|null $namespaces
	 * @return string[]
	 */
	public function getAllPagesForCategory(
		string $topCategories,
		int $categoryDepth,
		mixed $substring = null,
		string $substringPattern = "",
		mixed $namespaces = null
	) {
		$topCategories = str_replace( " ", "_", $topCategories );
		// cumulative $categories = all
		// checkCategories = which to check next below
		$categories = $checkCategories = explode( ",", $topCategories );
		if ( $categoryDepth === 0 ) {
			return $categories;
		}
		$pages = $sortkeys = [];
		$this->substringPattern = $substringPattern;
		$this->setNamespacesAndIndexes( $namespaces );

		for ( $level = $categoryDepth; $level > 0; $level-- ) {
			// set and reset for each cat depth
			$newCategories = [];
			foreach( $checkCategories as $category ) {
				// Common
				$table = "page";
				$columns = [ "page_title", "page_namespace", "page_is_redirect", "page_id" ];
				$likeConditions = $joins = $leftJoins = [];
				$likeConditions[] = "cl_from = page_id";
				$likeConditions[] = "cl_to = '$category'";
				// = same as $likeConditions["cl_to"] = $category;

				$joins[] = [ "categorylinks", "cl", [ "cl.cl_from = page_id" ] ];
				$leftJoins[] = [ "category", null, [ "cat_title = page_title", "page_namespace = " . NS_CATEGORY ] ];

				list( $columns, $leftJoins ) = $this->setupBaseSQLQueryForDefaultSort( $columns, $leftJoins );
				if ( $this->useDisplayTitle ) {
					// Match on and return display title
					// Move before?
					list( $columns, $leftJoins ) = $this->setupBaseSQLQueryForDisplayTitle( $columns, $leftJoins );
					// Search by display title
					if ( $substring !== null ) {
						$likeConditions[] = $this->createSubstringCondition( $substring, [], true, true );
					}
				} else {
					// No display title
					//$leftJoins = [];
					if ( $substring != null ) {
						/*
						$likeConditions[] = MWDBUtils::getSQLConditionForAutocompleteInColumn( 
							"page_title",
							$substring,
							true,
							$this->substringPattern,
							$this->dbr
						) 
						. " OR page_namespace = " . NS_CATEGORY;
						*/
						$cond = $this->dbr->makeList([
							MWDBUtils::getSQLConditionForAutocompleteInColumn( 
								"page_title",
								$substring,
								true,
								$this->substringPattern,
								$this->dbr
							),
							// get categories for next query
							"page_namespace = " . NS_CATEGORY
						], LIST_OR );
						$likeConditions[] = $cond;
					}
				}

				$conditions = $this->dbr->makeList([
					$this->dbr->makeList( $likeConditions, LIST_AND ),
					MWDBUtils::getExactSQLConditionForAutocompleteInColumn( "page_title", $substring, $this->dbr, null, $category )
				], LIST_OR );

				// order by cl_type? (file, page, subcat)
				$options = [
					//'ORDER BY' => 'cl_type, cl_sortkey',
					"ORDER BY" => "cl.cl_sortkey",
					"LIMIT" => $this->resultLimit
				];

				// Now do the query.
				$build = $this->dbr->newSelectQueryBuilder()
					->fields( $columns )
					->table( $table );
				foreach( $joins as $join ) {
					$build->join( $join[0], $join[1], $join[2] );
				}
				foreach( $leftJoins as $leftJoin ) {
					$build->leftJoin( $leftJoin[0], $leftJoin[1], $leftJoin[2] );
				}
				$build->where( $conditions )
					->options( $options )
					->caller( __METHOD__ );
				// @dev Test with $build->getQueryInfo()

				$resultSet = $build->fetchResultSet();
				if ( $resultSet ) {
					$this->formatCategoryResults( $resultSet, $categories, $newCategories, $pages, $sortkeys );
				}
			}

			if ( count( $newCategories ) === 0 ) {
				return self::fixedMultiSort( $sortkeys, $pages );
			} else {
				$categories = array_merge( $categories, $newCategories );
			}
			// The next categories to check
			$checkCategories = array_diff( $newCategories, [] );
		}
		// Just in case. Probably never reaches this point
		return self::fixedMultiSort( $sortkeys, $pages );
	}

	/**
	 * Result formatter for getAllPagesForCategory()
	 * Adds '@' to key
	 * 
	 * @param $res - members incl. both wiki and category pages
	 * @param $categories - categories gathered so far
	 * @param $newCategories - category pages in result get assigned here
	 * @param $pages - wiki pages in result get assigned here
	 * @param $sortkeys
	 */
	public function formatCategoryResults(
		$res,
		array $categories,
		array &$newCategories,
		array &$pages,
		array &$sortkeys
	) {
		if ( !$res ) {
			return;
		}
		while ( $res && $row = $res->fetchRow() ) {
			if ( !array_key_exists( "page_title", $row ) ) {
				continue;
			}
			$pageNamespace = $row["page_namespace"];
			$pageName = $row["page_title"];
			if ( $pageNamespace == NS_CATEGORY ) {
				// Assign category pages to its own array, down from top category
				if ( !in_array( $pageName, $categories ) ) {
					$newCategories[] = $pageName;
				}
			} else {
				// optionally, filter by namespace
				if ( $this->namespaceIndexes !== null && !in_array( $pageNamespace, $this->namespaceIndexes ) ) {
					continue;
				}
				// non-category wiki pages with a match
				$curTitle = Title::makeTitleSafe( $pageNamespace, $pageName );
				if ( $curTitle === null ) {
					// In case it's a 'phantom' page, in a namespace that 
					// no longer exists
					continue;
				}
				$curName = $curTitle->getPrefixedText();
				if ( !in_array( $curName, $pages ) ) {
					// pages with display title or page name
					if ( array_key_exists( "pp_displaytitle_value", $row ) &&
							( $row[ "pp_displaytitle_value" ] ) !== null &&
							trim( str_replace( "&#160;", "", strip_tags( $row["pp_displaytitle_value"] ) ) ) !== "" ) {
						$pages[ $curName . "@" ] = htmlspecialchars_decode( $row[ "pp_displaytitle_value"] );
					} else {
						$pages[ $curName . "@" ] = $curName;
					}
					// sortkeys
					if ( array_key_exists( "pp_defaultsort_value", $row )
						&& ( $row["pp_defaultsort_value"] ) !== null
					) {
						$sortkeys[ $curName ] = $row["pp_defaultsort_value"];
					} else {
						$sortkeys[ $curName ] = $curName;
					}
				}
			}
		}
		$res->free();
	}

	/**
	 * Leftover from Page Forms
	 * Helper for getAllPagesForCategory
	 * Removes any '@' that got appended during formatCategoryResults().
	 * @param mixed $sortkeys
	 * @param mixed $pages
	 * @return array
	 */
	public static function fixedMultiSort( array $sortkeys, array $pages ) {
		array_multisort( $sortkeys, $pages );
		$newPages = [];
		foreach ( $pages as $key => $value ) {
			$fixedKey = rtrim( $key, "@" );
			$newPages[$fixedKey] = $value;
		}
		return $newPages;
	}

	/**
	 * Helper function to create condition for substring.
	 * @todo If possible, rewrite using newSelectQueryBuilder() syntax
	 * @deprecated - see createSubstringCondition, kept for comparison
	 * 
	 * @param string $substring
	 * @param mixed $queriedNamespaces
	 * @param bool $replaceSpacesinDisplayTitle
	 * @param bool $checkIfDisplayTitleEmpty
	 * @param bool $getAllCategories whether to get categories in addition to substring matches
	 * @return string
	 */
	public function createSubstringConditionOld(
		string $substring,
		array $queriedNamespaces = [],
		bool $checkIfDisplayTitleEmpty = false,
		bool $getAllCategories = false
	) {
		if ( $checkIfDisplayTitleEmpty ) {
			$substringCondition = "((pp_displaytitle.pp_value IS NULL ";
			// ( () AND () ) OR	
			// used by getAllPages as well as getAllPagesForCategory
			$substringCondition .= "OR pp_displaytitle.pp_value = '') ";
		} else {
			$substringCondition = "(pp_displaytitle.pp_value IS NULL ";
			// here: ( ... AND () )
			// used by getAllPagesForNamespace
		}

		$substringCondition .= "AND (";
		$substringCondition .= MWDBUtils::getSQLConditionForAutocompleteInColumn(
			"page_title",
			$substring,
			true,
			$this->substringPattern,
			$this->dbr
		);
		$substringCondition .= ")";

		$substringCondition .= ") OR ";
		$substringCondition .= MWDBUtils::getSQLConditionForAutocompleteInColumn(
			"pp_displaytitle.pp_value",
			$substring,
			// $replaceSpacesinDisplayTitle - AllPages had 'true', but this should always be false I think:
			false,
			$this->substringPattern,
			$this->dbr
		);

		// Necessary for ?category and ?namespace versions
		// Necessary for subcategory search
		if ( $getAllCategories ) {
			$substringCondition .= " OR page_namespace = " . NS_CATEGORY;
		} elseif( !in_array( NS_CATEGORY, $queriedNamespaces ) ) {
			// @todo Here we would check if we want NS_CATEGORY to be included
			// $substringCondition .= ' OR page_namespace = ' . NS_CATEGORY;
		}

		return $substringCondition;

		/* @todo If no displaytitle, then....
		getAllPages has:
		$conditions[] = MWDBUtils::getSQLConditionForAutocompleteInColumn( 
			'page_title', $substring, true, $this->substringPattern, $this->dbr 
		);
		*/
	}

	/**
	 * Helper function to create condition for substring.
	 * @todo If possible, rewrite using newSelectQueryBuilder() syntax
	 * 
	 * @param string $substring
	 * @param mixed $queriedNamespaces
	 * @param bool $replaceSpacesinDisplayTitle
	 * @param bool $checkIfDisplayTitleEmpty
	 * @param bool $getAllCategories whether to get categories in addition to substring matches
	 * @return string
	 */
	public function createSubstringCondition(
		string $substring,
		array $queriedNamespaces = [],
		bool $checkIfDisplayTitleEmpty = false,
		bool $getAllCategories = false
	) {
		// 1. Display title
		$orCondsForDisplayTitle = [];
		$orCondsForDisplayTitle[] = "pp_displaytitle.pp_value IS NULL";
		if ( $checkIfDisplayTitleEmpty ) {
			$orCondsForDisplayTitle[] = "pp_displaytitle.pp_value = ''";
		}
		$orCondForDisplayTitle = $this->dbr->makeList( $orCondsForDisplayTitle, LIST_OR );

		//2. LIKE
		$likeCondition = MWDBUtils::getSQLConditionForAutocompleteInColumn(
			"page_title",
			$substring,
			true,
			$this->substringPattern,
			$this->dbr
		);

		// 3. Combine conditions and potentially extend
		// to get categories, too
		$newCondition = $this->dbr->makeList([ $orCondForDisplayTitle, $likeCondition ], LIST_AND );
		if ( $getAllCategories ) {
			$newCondition = $this->dbr->makeList([
				"page_namespace = " . NS_CATEGORY,
				$newCondition
			], LIST_OR );
		} elseif( !in_array( NS_CATEGORY, $queriedNamespaces ) ) {
			// @todo Here we would check if we want NS_CATEGORY to be included
			// $substringCondition .= ' OR page_namespace = ' . NS_CATEGORY;
		}

		return $newCondition;
	}

	/**
	 * @param mixed $substring
	 * @return mixed
	 * @deprecated
	 */
	public function getMaxValuesToRetrieve( $substring = null ) {
		return $this->resultLimit;
	}

}
