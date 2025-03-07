<?php

/**
 * Return matching values from a given SMW Property.
 * This service is not part of the Reconciliation API.
 * 
 * Adopts code from Page Forms as used
 * for the "values from property" parameter 
 * @link https://github.com/wikimedia/mediawiki-extensions-PageForms/blob/master/includes/PF_AutocompleteAPI.php
 * 
 * @todo Make cache timeout configurable
 * @todo Increase limit by 1 so we know whether to set the
 * 'cursor' for further results?
 */

namespace Recon\SMW;

use MediaWiki\MediaWikiServices;
use SMW\DataValueFactory;
use SMW\DataValues\PropertyValue; // = use SMWPropertyValue
use ObjectCache;
use Recon\MW\MWUtils;
use Recon\MW\MWDBUtils;
use Recon\SMW\SMWUtils;
use Recon\ReconUtils;
use Recon\StringModification\StringModifier;

class SMWSuggestPropertyValue {

	// Get replica DB (read only)
	private $dbr;
	private $store;
	private $substring;
	private $substringPattern;
	private $propertyName;
	private $propertyNameUnderscored;
	private $propertyType = "undefined";
	private $propertyId;
	private $isUserDefinedProperty;
	private $isFixedProperty = false;
	private $fixedPropertyTableId = false;
	private $showNamespacePrefix;
	private $cacheAutocompleteValues = false;
	private $autocompleteCacheTimeout = 60 * 60 * 24;
	// To be considered
	private $resultBatchCount = 0;
	private $resultTotal = 0;
	private $resultOffset = 0;
	private $resultLimit = 25;
	private $nextOffset;
	private $smwgEnabledFulltextSearch;

	public function __construct() {
		$this->dbr = MWDBUtils::getReadDB();
		$this->store = SMWUtils::getSMWStore();
		// @todo make this configurable
		$this->cacheAutocompleteValues = false;
		global $smwgEnabledFulltextSearch;
		$this->smwgEnabledFulltextSearch = $smwgEnabledFulltextSearch ? 1 : 0;
		$this->showNamespacePrefix = true;
	}

	public function run(
		string $propertyName,
		string $substring = "",
		string $substringPattern = "tokenprefix",
		bool $showNamespacePrefix = true,
		int $offset = 0,
		int $limit = 25
	) {
		if ( $this->store == null ) {
			return [
				"meta" => [
					"error" => "In order for the API to query on a property, Semantic MediaWiki must be installed."
				]
			];
		}

		$this->substring = $substring;
		$this->substringPattern = $substringPattern;
		$this->showNamespacePrefix = $showNamespacePrefix;
		$this->resultOffset = $offset;
		$this->resultLimit = $limit;

		$this->propertyName = str_replace( '_', ' ', $propertyName );
		$this->propertyNameUnderscored = str_replace( ' ', '_', $propertyName );
		$this->propertyType = SMWUtils::getDataTypeOfProperty( $propertyName );
		$this->propertyId = SMWUtils::getPropertyID( $propertyName, $this->store );
		$propertyDV = DataValueFactory::getInstance()->newPropertyValueByLabel( $this->propertyNameUnderscored );
		$this->fixedPropertyTableId = SMWUtils::getFixedPropertyTableId( $propertyDV, $this->store );
		$this->isFixedProperty = ( !$this->fixedPropertyTableId ) ? false : true;
		$this->isUserDefinedProperty = SMWUtils::isUserDefinedProperty( $propertyName ) ? 1 : 0;

		// produces a sequential array
		$queryRes = $this->getAllValuesForProperty( $propertyName, $substring );
		// @todo not waterproof
		$this->nextOffset = ( $this->resultBatchCount < $this->resultLimit )
			? 0
			: ( $this->resultOffset + $this->resultLimit );

		// Format first?
		$result = [];
		foreach ( $queryRes as $v ) {
			$result[] = [
				"id" => $v,
				"name" => $v,
				// Not ReconAPI
				"highlighted" => StringModifier::createHighlightedString( $v, $substring )
			];
		}

		return [
			"result" => $result,
			"meta" => [
				"service" => "Suggest SMW property values",
				"description" => "Values from property '" . $this->propertyName . "' that match on substring '" . $this->substring . "'",
				"substring" => $this->substring,
				"substringPattern" => $this->substringPattern,
				"source" => "smw",
				"smwgEnabledFulltextSearch" => $this->smwgEnabledFulltextSearch,
				"property" => $this->propertyName,
				"propertyType" => $this->propertyType,
				"propertyID" => $this->propertyId,//dev only
				"isFixedProperty" => $this->isFixedProperty ? 1 : 0,
				"isUserDefinedProperty" => $this->isUserDefinedProperty,
				"cached" => $this->cacheAutocompleteValues ? 1 : 0,		
				"resultTotal" => $this->resultTotal,
				"resultOffset" => $this->resultOffset,
				"resultLimit" => $this->resultLimit,
				"resultBatchCount" => $this->resultBatchCount,
				"nextOffset" => $this->nextOffset
			]
		];
	}

	/**
	 * Get all matching values of a given property. 
	 * Produces a sequential array.
	 * Based on PFAutocompleteAPI::getAllValuesForProperty()
	 * @param string $propertyName
	 * @param string $substring
	 * @return mixed
	 */
	private function getAllValuesForProperty(
		string $propertyName,
		string $substring
	) {
		$propertyName = str_replace( " ", "_", $propertyName );

		// Use cache if allowed
		if ( !$this->cacheAutocompleteValues ) {
			return $this->computeAllValuesForProperty( $propertyName, $substring );
		}
		return $this->getAllValuesForPropertyCached( $substring, $propertyName );
	}

	/**
	 * Cache-based version of the method,
	 * adapted from Page Forms
	 * @todo Test once caching can be set to true.
	 * 
	 * @param string $substring
	 * @param mixed $propertyName
	 * @return mixed
	 */
	public function getAllValuesForPropertyCached( $substring, $propertyName ) {
		$cache = self::getCache();
		// Remove trailing whitespace to avoid unnecessary database selects
		$cacheKeyString = $propertyName . '::' . rtrim( $substring );
		$cacheKey = $cache->makeKey( "smwsuggestpropertyvalue", md5( $cacheKeyString ) );
		return $cache->getWithSetCallback(
			$cacheKey,
			$this->autocompleteCacheTimeout,
			function () use ( $propertyName, $substring ) {
				return $this->computeAllValuesForProperty( $propertyName, $substring );
			}
		);
	}

	/**
	 * Get all values of SMW property that match on substring of value.
	 * 
	 * @author authors of PF_AutocompleteAPI.php
	 * 
	 * @param string $propertyName
	 * @param string $substring
	 * @return array
	 */
	private function computeAllValuesForProperty(
		string $propertyName,
		string $substring
	) {
		// Handling for https://www.semantic-mediawiki.org/wiki/Help:Fixed_properties

		$conditions = [];
		$propertyHasTypePage = ( $this->propertyType == '_wpg' );
		if( $propertyHasTypePage ) {
			// data type Page
			if ( $this->smwgEnabledFulltextSearch ) {
				// @todo?
			}
			$propsTableID = "p";
			$propsTable = $this->dbr->tableName( 'smw_di_wikipage' );
			$idsTable = $this->dbr->tableName( 'smw_object_ids' );

			if ( $this->isFixedProperty ) {
				// @todo
			} else {
				$conditions = [ 'p_ids.smw_title' => $propertyName ];
			}

			$valueField = "o_ids.smw_title";
			$valueFieldSearchable = $valueFieldPrintable = $valueFieldSort = "o_ids.smw_title";
			// index number of namespace
			$valueNamespace = "o_ids.smw_namespace";

			// page has an extra join
			// $fromClause = "$propsTable p JOIN $idsTable p_ids ON p.p_id = p_ids.smw_id JOIN $idsTable o_ids ON p.o_id = o_ids.smw_id";

			$q = $this->dbr->newSelectQueryBuilder()
				->distinct()
				->fields( [ $valueFieldSearchable, $valueNamespace ] )
				->table( $propsTable, $propsTableID )
				->join( $idsTable, "p_ids", "$propsTableID.p_id = p_ids.smw_id" )
				->join( $idsTable, "o_ids", "$propsTableID.o_id = o_ids.smw_id" );
			$qForCount = $this->dbr->newSelectQueryBuilder()
				->fields( $valueFieldSearchable )
				->table( $propsTable, $propsTableID )
				->join( $idsTable, "p_ids", "$propsTableID.p_id = p_ids.smw_id" )
				->join( $idsTable, "o_ids", "$propsTableID.o_id = o_ids.smw_id" );
			$orderBy = "o_ids.smw_title";
		} else {
			// data type Text, or other - @todo
			$propsTableID = "p";
			// better use a language-neutral method
			if ( $this->isFixedProperty ) {
				$propsTable = $this->dbr->tableName( $this->fixedPropertyTableId );
				$propsTableJoinColumn = "$propsTableID.s_id";
				// $fromClause = "$propsTable p JOIN $idsTable p_ids ON p.s_id = p_ids.smw_id";
			} else {
				$propsTable = $this->dbr->tableName( 'smw_di_blob' );
				$propsTableJoinColumn = "$propsTableID.p_id";
				//"$propsTableID.p_id = p_ids.smw_id"
				$conditions = [ 'p_ids.smw_title' => $propertyName ];
			}
			
			if( $this->propertyName == "Display title of" ) {
				// Probably unnecessary
				// $displayTitleTable = $this->dbr->tableName( "smw_fpt_dtitle" ); // uses s_id, o_blob, o_hash
			}
			$idsTable = $this->dbr->tableName( 'smw_object_ids' );

			$valueField = "$propsTableID.o_hash";
			$valueFieldSearchable = $valueFieldSort = "$propsTableID.o_hash";
			$valueFieldPrintable = "$propsTableID.o_hash,$propsTableID.o_blob";
			$valueNamespace = null;

			// $fromClause = "$propsTable $propsTableID JOIN $idsTable p_ids ON $propsTableID.p_id = p_ids.smw_id";

			$q = $this->dbr->newSelectQueryBuilder()
				->distinct()
				->fields( [ "$propsTableID.o_hash", "$propsTableID.o_blob" ] ) // select
				->table( $propsTable, $propsTableID ) // from
				//->join( $idsTable, "p_ids", "$propsTableID.p_id = p_ids.smw_id" );
				->join( $idsTable, "p_ids", "$propsTableJoinColumn = p_ids.smw_id" );
			$qForCount = $this->dbr->newSelectQueryBuilder()
				->fields( "$propsTableID.o_hash" )
				->table( $propsTable, $propsTableID ) // from
				->join( $idsTable, "p_ids", "$propsTableJoinColumn = p_ids.smw_id" );
			$orderBy = "$propsTableID.o_hash";
		}
	
		if ( $substring !== null ) {
			// "Page" type property values are stored differently
			// in the DB, i.e. underlines instead of spaces.
			$conditions[] = MWDBUtils::getSQLConditionForAutocompleteInColumn( $valueFieldSearchable, $substring, $propertyHasTypePage, $this->substringPattern, $this->dbr );
		}

		// Now do the query and get a Wikimedia\Rdbms\MysqliResultWrapper
		$qRes = $q
			->where( $conditions )
			->limit( $this->resultLimit )
			->offset( $this->resultOffset )
			->orderBy( [ $orderBy ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		// dev: getQueryInfo()

		// Get total count:
		$this->resultTotal = $qForCount
			->where( $conditions )
			->offset( 0 )
			->caller( __METHOD__ )
			->fetchRowCount();

		// Format result and return
		$values = $this->convertQueryRowsToArray( $qRes, $substring, $propertyHasTypePage, $valueNamespace );
		$this->resultBatchCount = count( $values );
		return $values;
	}

	private function convertQueryRowsToArray(
		\Wikimedia\Rdbms\MysqliResultWrapper $res,
		mixed $substring,
		bool $propertyHasTypePage,
		mixed $valueNamespace = null
	): array {
		$connection = $this->store->getConnection( 'mw.db' );
		$values = [];
		$ns = null;
		while ( $row = $res->fetchRow() ) {			
			if ( $propertyHasTypePage ) {
				$pagename = str_replace( '_', ' ', $row[0] );
				if ( isset( $valueNamespace ) && $row[1] !== '0' ) {
					$ns = MWUtils::getCanonicalNamespaceName( $row[1] );
					$pagename = $ns . ":" . $pagename;
				}
			} else {
				// use either o_hash or o_blob (if it is null)
				// else the string can get truncated/hashed
				$pagename = ( $row[1] !== null )
					? $connection->unescape_bytea( $row[1] )
					: $row[0];
				$pagename = str_replace( '_', ' ', $pagename );
			}
			$values[] = $pagename;
		}
		$res->free();

		$values = self::reorderMatchesByInit( $substring, $values, $ns );
		return $values;
	}

	/**
	 * Get the cache object used by the form cache
	 * Adapted from PageForms - PFFormUtils::getFormCache()
	 * @todo Should be in a helper class like MWUtils
	 * @return BagOStuff
	 */
	public static function getCache() {
		$wgParserCacheType = MediaWikiServices::getInstance()->getMainConfig()->get( 'ParserCacheType' );
		// @todo Set to null for now (not yet configurable)
		$cacheType = null;
		$ret = ObjectCache::getInstance( ( $cacheType !== null ) ? $cacheType : $wgParserCacheType );
		return $ret;
	}

	/**
	 * Moves values that match at start of substring to the beginning of the array.
	 * @param mixed $substring
	 * @param array $values
	 * @param string $ns
	 * @return array
	 */
	private static function reorderMatchesByInit( mixed $substring, array $values, string $ns = null ) {
		$rankFirst = $rankSecond = [];
		$substring = StringModifier::flattenString( $substring );
		foreach( $values as $value ) {
			// ignore namespace prefix
			$checkVal = ( $ns == null ) ? $value : str_replace( $ns . ":", "", $value );
			if ( str_starts_with( StringModifier::flattenString( $checkVal ), $substring ))  {
				$rankFirst[] = $value;
			} else {
				$rankSecond[] = $value;
			}
		}
		return array_merge( $rankFirst, $rankSecond );
	}

	private function handleBlob( $row, $connection ) {
		$connection = $this->store->getConnection( 'mw.db' );
		if ( $row->o_blob !== null ) {
			$msg = $connection->unescape_bytea( $row->o_blob );
		} else {
			$msg = $row->o_hash;
		}
	}

	/**
	 * @deprecated
	 * @param mixed $propertyName
	 * @return string|mixed
	 */
	private function getDataTypeOfProperty( $propertyName ) {
		return SMWUtils::getDataTypeOfProperty( $propertyName );
	}

}
