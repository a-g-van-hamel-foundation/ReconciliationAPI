<?php

/**
 * Utility function for working with MediaWiki categories.
 */

namespace Recon\MW;

use \MediaWiki\MediaWikiServices;
use Title;
use Recon\MW\MWDBUtils;

class MWCategoryUtils {

	public static function getCategoriesFromTitle( $title, $outputFormat = null ) {
		$wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
		$wikiPage = $wikiPageFactory->newFromTitle( $title );
		$categories = $wikiPage->getCategories();
		// exclude hidden categories?
		$res = [];
		switch( $outputFormat ) {
			case "Title";
				$res = $categories;
			break;
			case "idname";
				foreach( $categories as $cat ) {
					$res[] = [
						"id" => $cat->getPrefixedText(),
						"name" => $cat->getText()
					];
				}
			break;
			case "shortname";
				foreach( $categories as $cat ) {
					$res[] = $cat->getText();
				}
			break;
		}
		return $res;
	}

	/**
	 * Get all MediaWiki categories based on match. Supports phrase matching
	 * 
	 * @param mixed $substring
	 * @param int $offset
	 * @param int $limit
	 * @return array
	 */
	public static function getAllCategories(
		mixed $substring = null,
		mixed $substringpattern = "stringprefix",
		int $offset = 0,
		int $limit = 25
	) {
		$dbr = MWDBUtils::getReadDB();
		$tables = [ 'page' ];
		$fields = [ 'page_id', 'page_namespace', 'page_title', 'page_is_redirect', 'cl_to', 'cl_from', 'cl_sortkey', 'cat_title', 'cat_subcats' ];
		$joins[] = [ "categorylinks", null, [ 'cl_from = page_id' ] ];		
		$leftJoins[] = [ "category", null, [ 'cat_title = page_title', 'page_namespace = ' . NS_CATEGORY ] ];
		$options = [ 'ORDER BY' => 'cl_sortkey' ];
		$orderBy = "cl_sortkey";
		// or use ->useIndex( [ 'categorylinks' => 'cl_sortkey' ] );
		$options['USE INDEX']['categorylinks'] = 'cl_sortkey';
		$offset = 0;
		$limit = 25;
		$where = [];
		$where["cl_type"] = "subcat";
		$where["page_is_redirect"] = 0;

		if ( $substring != null ) {
			$where[] = MWDBUtils::getSQLConditionForAutocompleteInColumn( 'page_title', $substring, true, $substringpattern, $dbr );
		}

		$build = MWDBUtils::selectQueryBuilder(
			$dbr->newSelectQueryBuilder(),
			$tables,
			$fields,
			$joins,
			$leftJoins,
			[],
			$where,
			$options,
			false,
			$limit
		);

		$subCats = $nextLevelCategories = $pageIds = [];
		$resultSet = $build->fetchResultSet();
		while ( $resultSet && $row = $resultSet->fetchRow() ) {
			self::formatCategoryResultRow( $row, $subCats, $nextLevelCategories, $pageIds );
		}
		$resultSet->free();
		ksort( $subCats, SORT_NATURAL );
		return $subCats;
	}

	/**
	 * Get all subcategories of a parent category down to a certain level,
	 * Support for phrase matching.
	 * Initially based on Category::getMembers() and PageForms
	 * 
	 * Redirects are ignored.
	 * Expensive because it involves multiple queries upon queries
	 * A maximum limit is hard-coded to a value of 250
	 * 
	 * @todo add topCategory to results unless there's no match
	 * @todo phrase matching by display title
	 * @todo use display title as label
	 * @todo handle redirects?
	 * @todo limit and offset
	 * 
	 * Allows for multiple category names separated by semi-colon
	 * 
	 * @param mixed $topCategoryNames
	 * @param mixed $categoryDepth
	 * @return array
	 */
	public static function getSubCategories(
		string $topCategoryNames,
		int $categoryDepth = 5,
		mixed $substring = null,
		string $substringPattern = "stringprefix",
		int $offset = 0,
		int $limit = 25
	): array|bool {
		// Initially we look for any category member, category page or otherwise
		$dbr = MWDBUtils::getReadDB();
		$tables = [ 'page' ];
		$fields = [ 'page_id', 'page_namespace', 'page_title', 'page_is_redirect', 'cl_to', 'cl_from', 'cl_sortkey', 'cat_title', 'cat_subcats' ];
		//$fields = array_merge( $fields, [ 'page_is_redirect',  'page_len', 'page_latest', 'cat_id', 'cat_title', 'cat_subcats', 'cat_pages', 'cat_files' ] );
		$joins = $leftJoins = [];
		$joins[] = [ "categorylinks", null, [ 'cl_from = page_id' ] ];		
		$leftJoins[] = [ "category", null, [ 'cat_title = page_title', 'page_namespace = ' . NS_CATEGORY ] ];
		// or use ->orderBy( 'cl_type, cl_sortkey' );
		$options = [ 'ORDER BY' => 'cl_sortkey' ];
		$orderBy = "cl_sortkey";
		// or use ->useIndex( [ 'categorylinks' => 'cl_sortkey' ] );
		$options['USE INDEX']['categorylinks'] = 'cl_sortkey';
		$where = [];
		$where["cl_type"] = "subcat";
		$where["page_is_redirect"] = 0;

		// Initially we look for any category member, incl. regular wiki pages
		$subCats = $pageIds = [];
		$catsToCheck = explode( ";", $topCategoryNames );

		for ( $level = $categoryDepth; $level > 0; $level-- ) {
			// categories specific to a certain depth, needs to be reset
			$nextLevelCategories = [];
			foreach ( $catsToCheck as $checkCat ) {
				$checkCat = trim( $checkCat );
				// Limit hardcoded
				if ( !self::isValidCategory( $checkCat )
				|| count( $subCats ) > 250
				) {
					continue;
				}

				$where["cl_to"] = str_replace( ' ', '_', $checkCat );

				$build = MWDBUtils::selectQueryBuilder(
					$dbr->newSelectQueryBuilder(),
					$tables,
					$fields,
					$joins,
					$leftJoins,
					[],
					$where,
					$options
				);
				//print_r( "<pre>" );
				//print_r( $build->getQueryInfo() );
				//print_r( "</pre>" );
				$resultSet = $build->fetchResultSet();				
				while ( $resultSet && $row = $resultSet->fetchRow() ) {
					self::formatCategoryResultRow( $row, $subCats, $nextLevelCategories, $pageIds );	
				}
				$resultSet->free();
			}
			// The next subcats to check if any
			$catsToCheck = $nextLevelCategories ?? [];
		}

		ksort( $subCats, SORT_NATURAL );
		// Because we could not match or limit on multiple SQL queries
		// we're doing it now.
		$subCats = self::findMatches( $subCats, $substring, $substringPattern );
		$subCats = array_slice( $subCats, $offset, $limit );
		return $subCats;
	}

	/**
	 * Find matches on subcategories through simple string comparison
	 * @todo Accent folding would be nice
	 * @param mixed $subCats
	 * @param mixed $substring
	 * @param mixed $substringPattern
	 * @return array
	 */
	private static function findMatches( $subCats, $substring, $substringPattern = "stringprefix" ) {
		$newCats = [];
		$substring = strtolower( $substring );
		foreach( $subCats as $subCat ) {
			$title = strtolower( $subCat["name"] );
			if ( $substringPattern == "allchars" && str_contains( $title, $substring ) ) {
				$newCats[] = $subCat;
			} elseif ( str_starts_with( $title, $substring ) ) {
				$newCats[] = $subCat;
			}
		}
		return $newCats;
	}

	/**
	 * Formatter function for getAllCategories() and getSubCategories()
	 * @param mixed $row
	 * @param array $subCats
	 * @param array $nextLevelCategories
	 * @param array $pageIds
	 * @return void
	 */
	public static function formatCategoryResultRow( $row, array &$subCats, array &$nextLevelCategories, array &$pageIds ) {
		// Used to check if we did not find a duplicate
		if ( !array_key_exists( "page_id", $row )
			|| array_key_exists( $row['page_id'], $pageIds ) ) {
			return;
		}
		$pageIds[] = $row['page_id'];
		$title = Title::makeTitleSafe( $row["page_namespace"], $row['page_title'] );
		// maybe guard against phantom pages from lost namespaces?
		$pageTitle = $title->getText();

		// Do not check this category for subcategories
		$subCatCount = intval( $row['cat_subcats'] );
		if ( $subCatCount > 0 ) {
			$nextLevelCategories[] = $pageTitle;
		}
		$subCats[ $row['cl_sortkey'] ] = [
			"name" => $pageTitle,
			"description" => "Category",
			"other" => [
				"pageid" => $row['page_id'],
				"ns" => $row['page_namespace'],
				"isredirect" => $row['page_is_redirect'] ? 1 : 0,
				"subcats" => $subCatCount
			],
			"sortkey" => $row['cl_sortkey'],
			"cl_to" => $row['cl_to'] // category,
		];
	}

	private static function isValidCategory( $categoryName ): bool {
		$title = Title::newFromText( $categoryName, NS_CATEGORY );
		if ( $title == null ) {
			// malformed
			return false;
		}
		return ( $title->getArticleID() == 0 ) ? false : true;
	}

}
