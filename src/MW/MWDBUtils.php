<?php

/**
 * Utility methods for working with the MediaWiki database.
 * Large parts originated with the Page Forms extension.
 */

namespace Recon\MW;

use \MediaWiki\MediaWikiServices;

class MWDBUtils {

	// Unused
	// private $db;
	// private $substringMatchType;

	/**
	 * Returns a SQL condition for autocompletion substring value in a given column.
	 *
	 * @param string $column Value column name
	 * @param string $substring Substring to look for
	 * @param bool $replaceSpaces - whether to replace spaces with underscores from substring (e.g. for 'page_title', but should NOT be done for 'pp_displaytitle.pp_value' ) AND if whether to treat underscores as space representations
	 * @return string SQL condition for use in WHERE clause
	 */
	public static function getSQLConditionForAutocompleteInColumn(
		string $column,
		string $substring,
		bool $replaceSpaces = true,
		string $substringPattern = "tokenprefix",
		$db = null
	): string {
		$db = $db ?? self::getReadDB();

		// Some preprocessing because sadly, there is no 
		// search-optimised version of Displaytitle.
		// @todo maybe treat italics as word separators below?
		if ( $db->getType() == 'mysql' ) {
			$column_value = "LOWER(CONVERT($column USING utf8))";
		} else {
			// CONVERT() is also supported in PostgreSQL, but it does not
			// seem to work the same way.
			// @todo test if sth like this works for PostgreSQL today:
			// convert($column,'LATIN1','UTF8')
			$column_value = "LOWER($column)";
		}

		$replaceItalics = MediaWikiServices::getInstance()->getMainConfig()->get( 'ReconAPIRemoveItalicsFromDisplayTitleColumn' );
		if ( $replaceItalics && $column == "pp_displaytitle.pp_value" ) {
			// @warning Potentially heavy!
			$column_value = "REPLACE( REPLACE( REPLACE( REPLACE( $column_value, '<i>', '' ), '</i>', '' ), '<em>', '' ), '</em>', '' )";
		}

		// @todo Dedicate a special stage to preprocessing substrings
		// Case-insensitive
		$substring = strtolower( $substring );
		// Specific to columns, e.g. do NOT do this for "pp_displaytitle.pp_value"
		if ( $replaceSpaces ) {
			$substring = str_replace( ' ', '_', $substring );
		}

		// LIKE - eg Database/IDatabase::buildLike( either array or variadic )
		// @link https://phabricator.wikimedia.org/source/mediawiki/browse/REL1_39/includes/libs/rdbms/database/IDatabase.php
		// $likeValue = new LikeValue( ...$params );
		// returns ' LIKE ' . $likeValue->toSql( $this->quoter );
		// in SQL $db->anyString() = %
		switch( $substringPattern ) {
			case "allchars":
			case "contains":
				// Match anywhere
				$sqlCond = $column_value . $db->buildLike( $db->anyString(), $substring, $db->anyString() );
				break;			
			case "stringprefix":
			case "startswith":
				// Match on beginning of full phrase
				$sqlCond = $column_value . $db->buildLike( $substring, $db->anyString() );
				break;
			case "tokenprefix":
			case "prefix":
			default:
				// Default: match on beginning of each pseudo-token:
				$sqlCond = $column_value . $db->buildLike( $substring, $db->anyString() );
				// for _ MW also has $db->anyChar()
				$spaceRepresentation = $replaceSpaces ? '_' : ' ';
				// '<i>', '</i>', '<em>', '</em>' ??
				$wordSeparators = [ $spaceRepresentation, '/', '(', ')', '-', '\'', '\"' ];
				foreach ( $wordSeparators as $wordSeparator ) {
					$sqlCond .= " OR " . $column_value .
					$db->buildLike( $db->anyString(), $wordSeparator . $substring, $db->anyString() );
				}
		}

		return $sqlCond;
	}

	/**
	 * Provides database for read access
	 * @todo support MW 1.42 getConnectionProvider?
	 * @return IDatabase|DBConnRef
	 */
	public static function getReadDB() {
		// @todo
		// MW 1.42+
		// $this->dbProvider = MediaWikiServices::getInstance()->getConnectionProvider();
		// MW 1.40-41
		// $this->dbr = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->getReplicaDatabase();
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		if ( method_exists( 
			$lbFactory,
			'getReplicaDatabase' )
		) {
			// MW 1.40+
			// The correct type \Wikimedia\Rdbms\IReadableDatabase cannot be used
			// as the return type, as that class only exists since 1.40.
			// @phan-suppress-next-line PhanTypeMismatchReturnSuperType
			return $lbFactory->getReplicaDatabase();
		} else {
			return $lbFactory->getMainLB()->getConnection( DB_REPLICA );
		}
	}

	/**
	 * Wrapper for newSelectQueryBuilder
	 * @todo Support for andWhere, useIndex
	 * @param mixed $builder
	 * @param array $tables
	 * @param array $columns
	 * @param array $joins
	 * @param array $leftJoins
	 * @param array $straightJoins
	 * @param array $conditions
	 * @param array $options
	 * @param mixed $orderBy
	 * @param mixed $limit
	 * @return mixed
	 */
	public static function selectQueryBuilder(
		$builder,
		array $tables,
		array $columns,
		array $joins = [],
		array $leftJoins = [],
		array $straightJoins = [],
		array $conditions = [],
		array $options = [],
		mixed $orderBy = false,
		mixed $limit = false
	) {
		if ( $tables == [] || $columns == [] ) {
			// false?
			return false;
		}
		foreach( $tables as $k => $table ) {
			$build = is_string( $k )
				? $builder->table( $table, $k )
				: $builder->table( $table );
		}
		foreach( $columns as $k => $col ) {
			is_string( $k )
				? $build->field( $col, $k )
				: $build->field( $col );
		}
		foreach( $joins as $join ) {
			$build->join( $join[0], $join[1], $join[2] );
		}
		foreach( $leftJoins as $leftJoin ) {
			$build->leftJoin( $leftJoin[0], $leftJoin[1], $leftJoin[2] );
		}
		foreach( $straightJoins as $straightJoin ) {
			$build->straightJoin( $straightJoin[0], $straightJoin[1], $straightJoin[2] );
		}
		$build->where( $conditions );
		if ( $limit ) {
			$build->limit( $limit );
		}
		if ( $orderBy ) {
			$build->orderBy( $orderBy );
		}
		$build
			->options( $options )
			->caller( __METHOD__ )
		;
		return $build;
	}

}
