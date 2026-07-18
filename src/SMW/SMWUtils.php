<?php

/**
 * Helper methods for recurrent needs when working with SMW.
 * @todo consolidate methods to check for availabilty of SMW.
 */

namespace Recon\SMW;

//use MediaWiki\MediaWikiServices;
use Title;
use SMW\StoreFactory;
use SMW\SQLStore\SQLStore;
use SMW\SQLStore\SQLStoreFactory;
use SMW\Services\ServicesFactory;
use SMW\DIWikiPage;
use SMW\DIProperty;
use SMWDIUri;
// => SMW\DataItems\Uri in SMW 7
use SMWDIError;
// => SMW\DataItems\Error in SMW 7
use SMW\DataValueFactory;
use SMW\DataValues\TypesValue;
use SMWQueryProcessor;
// => SMW\Query\QueryProcessor in SMW 7
use SMW\RequestOptions;
use Recon\MW\MWUtils;
//use Recon\Localisation\ReconLocalisation;
use Recon\SMW\SMWQuerySyntaxConverters;

class SMWUtils {

	private $store;
	private $requestOptions;
	private $settings;

	/**
	 * Unused. Currently, this class is not called dynamically
	 */
	public function __construct() {
		global $smwgDefaultStore;
		if ( $smwgDefaultStore == null ) {
			$this->isSmwStoreAvailable = false;
			return;
		} else {
			$this->isSmwStoreAvailable = true;
		}
		$this->store = StoreFactory::getStore();
		$this->requestOptions = new RequestOptions();
		$this->settings = ServicesFactory::getInstance()->getSettings();
	}

	/**
	 * Returns smwStore if it exists, else empty array.
	 */
	public static function checkForSMW() {
        $store = self::getSMWStore();
        if ( $store == null ) {
             return [];
        }
		return $store;
    }

	/**
	 * Another method to check if SMW is installed.
	 * Lightweight but watertight?
	 * 
	 * @return bool
	 */
	public static function isSMWStoreAvailable(): bool {
		global $smwgDefaultStore;
		return ( $smwgDefaultStore == null ) ? false : true;
	}

	public static function isSMWInstalled() {
		return class_exists( '\SMW\StoreFactory' ) ? true : false;
	}

	public static function getSMWStore(): mixed {
		if ( class_exists( '\SMW\StoreFactory' ) ) {
			return StoreFactory::getStore();
		} else {
			return null;
		}
	}

	/**
	 * Whether or not Full-Text Search is enabled for SMW.
	 * @return bool
	 */
	public static function isFTSEnabled(): bool {
		global $smwgEnabledFulltextSearch;
		return $smwgEnabledFulltextSearch ?? false;
	}

	/**
	 * string representation not SMW\DIWikiPage
	 * @param mixed $diWikipage
	 * @return string
	 */
	public static function resolveDIWikiPage( string $diWikipage ) {
        //doUnserialize
        $arr = explode( '#', $diWikipage, 4 );
        $namespaceNumber = intval( $arr[1] );
        $prefix = ( $namespaceNumber !== 0 ) ? MWUtils::getNamespaceNameFromIndex( $namespaceNumber ) . ":" : "";
        $pagename = $prefix . $arr[0];
        $str = str_replace( "_", " ", $pagename);
        return $str;
    }

	/**
	 * cf. newPropertyValueByItem()
	 * @param mixed $propertyName
	 * @param mixed $store
	 * @return mixed
	 */
	public static function getPropertyID( $propertyName, $store = null ) {
		$store = $store ?? self::getSMWStore();
		// Get the Object to access the SMW IDs table.
		$idTable = $store->getObjectIds();
		// Get SMW\\DataValues\\PropertyValue
		$propertyDataValue = DataValueFactory::getInstance()->newPropertyValueByLabel( $propertyName );
		// Get SMW\DIProperty
		$propertyDataItem = $propertyDataValue->getDataItem();
		// $diProperty = $propertyDataValue->getProperty();
		if ( $propertyDataItem instanceof SMWDIError ) {
			// @todo
		}
		$propId = $idTable->getSMWPropertyID( $propertyDataItem );
		return $propId;
	}

	/**
	 * Get data type of property. 
	 * Produces contants like _wpg (see TypesRegistry.php)
	 * @param mixed $propertyName (without prefix?)
	 * @return string|mixed
	 */
	public static function getDataTypeOfProperty( $propertyName ) {
		$propertyDV = DataValueFactory::getInstance()->newPropertyValueByLabel( $propertyName );
		return $propertyDV->getPropertyTypeID();
	}

	/**
	 * Get key (not ID) of property,
	 * whether user-defined or 'special'
	 * @param mixed $propertyName name without page prefix
	 */
	public static function getPropertyKey( string $propertyName ) {
		$propertyDV = DataValueFactory::getInstance()->newPropertyValueByLabel( $propertyName );
		$propertyDI = $propertyDV->getDataItem();
		return $propertyDI->getKey();
	}

	/**
	 * 
	 * @param SMW\DIProperty $property
	 * @param mixed $store
	 * @param mixed $settings
	 * @return string
	 */
	public static function getPropertyType( DIProperty $property, $store, $settings ): string {
		$defaultType = $settings->get( 'smwgPDefaultType' );
		$defaultTypeStr = TypesValue::newFromTypeId( $defaultType )->getLongHTMLText();

		$typeProperty = new DIProperty( '_TYPE' );
		$types = $store->getPropertyValues( $property->getDiWikiPage(), $typeProperty );
		if ( is_array( $types ) && count( $types ) >= 1 ) {
			$typeDataValue = DataValueFactory::getInstance()->newDataValueByItem( current( $types ), $typeProperty );
			$typeStr = $typeDataValue->getLongHTMLText();
		} else {
			$typeStr = $defaultTypeStr;
			// $this->getMessageFormatter()->addFromKey( 'smw_propertylackstype', $typestring );
		}
		return $typeStr;
		// @todo internationalisation
		// $dataValue = DataValueFactory::getInstance()->newDataValueByItem( $property );
	}

	/**
	 * Get table ID of fixed property
	 * return false if the property if not fixed.
	 * @param mixed $property
	 * @param mixed $store
	 * @return string|bool
	 */
	public static function getFixedPropertyTableId( $propertyDV, $store ): mixed {
		// @todo not here?
		if ( !$store instanceof SQLStore ) {
			return false;
		}
		$inceptiveProperty = $propertyDV->getInceptiveProperty();
		$propertyTableId = $store->findPropertyTableID( $inceptiveProperty );
		$isFixedProperty = self::isFixedPropertyTable( $store, $propertyTableId );
		if ( $isFixedProperty ) {
			return $propertyTableId;
		}
		return false;
	}

	/**
	 * Based on SMW's ProximityPropertyValueLookup::isFixedPropertyTable()
	 * @note Cf. Page Forms: `$isFixedProperty = preg_match( '/smw_fpt_/', $tableId );`
	 * But does it also cover SESP properties ( smw_ftp_sesp_*) with the 'fixed' setting?
	 * @return bool
	 */
	private static function isFixedPropertyTable( $store, string $tableID ) {
		$propertyTables = $store->getPropertyTables();
		foreach ( $propertyTables as $propertyTable ) {
			if ( $propertyTable->getName() === $tableID ) {
				return $propertyTable->isFixedPropertyTable();
			}
		}
		return false;
	}

	public static function getDataLookup( $store = null ) {
		$store = $store ?? self::getSMWStore();
		$sqlStorefactory = new SQLStoreFactory( $store );
		return $sqlStorefactory->newSemanticDataLookup();
	}

	public static function getEntityLookup( $store = null ) {
		$store = $store ?? self::getSMWStore();
		$sqlStorefactory = new SQLStoreFactory( $store );
		return $sqlStorefactory->newEntityLookup();
	}

	// propertyDI
	public static function isUserDefinedProperty( $propertyName ): bool {
		$propertyDV = DataValueFactory::getInstance()->newPropertyValueByLabel( $propertyName );
		// Get SMW\DIProperty
		$propertyDI = $propertyDV->getDataItem();
		return $propertyDI->isUserDefined();
	}

	/**
	 * 
	 * @param Title $title
	 * @param mixed $propertyDI
	 * @param int $useCount
	 * @return string[]
	 */
	public static function getUserDefinedPropertyInfo( Title $title, $propertyDI, int $useCount ) {
		// @todo 
		$store = $settings = null;

		$label = htmlspecialchars( $propertyDI->getLabel() );
		$dataTypeStr = self::getPropertyType( $propertyDI, $store, $settings );
		// $displaytitle
		return [ $label, $label, $dataTypeStr ];
	}

	public static function getPreDefinedPropertyInfo( DIProperty $propertyDI ) {
		// $dataValue = DataValueFactory::getInstance()->newDataValueByItem( $propertyDI, null );
		//$propId = $property->id;
		//$propKey = $property->getKey();
		$label = $propertyDI->getLabel();
		$dataTypeStr = TypesValue::newFromTypeId( $propertyDI->findPropertyValueType() )->getLongHTMLText();
		return [ $label, $label, $dataTypeStr ];
	}

	/**
	 * Helper function to handle getPropertyValues().
	 * @credits originally part of Page Forms (PF_ValuesUtils)
	 * @todo Does this work for predefined properties?
	 *
	 * @param Store $store
	 * @param Title $subject
	 * @param string $propID
	 * @param SMW\RequestOptions|null $requestOptions
	 * @return array
	 * @suppress PhanUndeclaredTypeParameter For Store
	 */
	public static function getSMWPropertyValues(
		$store,
		$subject,
		string $propID,
		$requestOptions = null
	): array {
		// If SMW is not installed, exit out.
		if ( !class_exists( 'SMW\DIWikiPage' ) ) {
			return [];
		}
		$page = ( $subject === null ) ? null : DIWikiPage::newFromTitle( $subject );
		$property = DIProperty::newFromUserLabel( $propID );

		$res = $store->getPropertyValues( $page, $property, $requestOptions );
		$values = [];
		foreach ( $res as $value ) {
			if ( $value instanceof SMWDIUri ) {
				$values[] = $value->getURI();
			} elseif ( $value instanceof DIWikiPage ) {
				$realValue = str_replace( '_', ' ', $value->getDBKey() );
				if ( $value->getNamespace() != 0 ) {
					$realValue = MWUtils::getCanonicalNamespaceName( $value->getNamespace() ) . ":$realValue";
				}
				$values[] = $realValue;
			} else {
				// getSortKey() seems to return the correct
				// value for all the other data types.
				$values[] = str_replace( '_', ' ', $value->getSortKey() );
			}
		}		
		return $values;
	}

	/**
	 * Summary of handlePropertyName
	 * @param mixed $name
	 * @param mixed $action: removeprefix, addprefix
	 * @param string $language - 'English' or 'contentlanguage'
	 */
	public static function handlePropertyName(
		string $name,
		string $action = "removeprefix",
		string $language = "English"
	) {
		$localisedNamespace = MWUtils::getNamespaceNameFromIndex( SMW_NS_PROPERTY );
		$otherLanguage = $language == "English" ? "contentlanguage" : "English";
		$namespace = [
			"English" => "Property",
			"contentlanguage" => MWUtils::getNamespaceNameFromIndex( SMW_NS_PROPERTY )
		];
		$namespaces = array_unique( [ $namespace["English"], $namespace["contentlanguage"] ] );
		switch( $action ) {
			case "removeprefix":
				// Remove prefix in English or content language
				foreach( $namespaces as $ns ) {
					if( str_starts_with( $name, "$ns:") ) {
						$name = substr( $name, strlen( "$ns:" ) );
						return $name;
					}
				}
			break;
			case "addprefix":
				// Also ensures that a prefix already there stays included
				if( str_starts_with( $name, $namespace[$language] . ":" ) ) {
					return $name;
				} elseif( str_starts_with( $name, "{$namespace[$otherLanguage]}:" ) ) {
					// if the namespace prefix is not in the desired language
					$nameOnly = substr( $name, strlen( "{$namespace[$otherLanguage]}:" ) );
					return "{$namespace[$language]}:$nameOnly";
				} else {
					return "{$namespace[$language]}:$name";
				}
		}
		return $name;
	}

	/**
	 * Accepts a raw query in the array format and
	 * creates from it an SMWQuery object.
	 * @link https://github.com/SemanticMediaWiki/SemanticMediaWiki/blob/master/src/MediaWiki/Api/Query.php
	 * 
	 * @param array $rawQueryArr
	 * @param mixed $useShowMode - whether to use #show instead of #ask
	 * @return object SMWQuery
	 */
	public static function createSMWQueryObjFromRawQuery( array $rawQueryArr, $useShowMode = false ) {
		[ $queryString, $processedParams, $printouts ] = SMWQueryProcessor::getComponentsFromFunctionParams( $rawQueryArr, $useShowMode );
		SMWQueryProcessor::addThisPrintout( $printouts, $processedParams );
		$processedParams = SMWQueryProcessor::getProcessedParams( $processedParams, $printouts );

		// Run query (SMWQuery) and return SMWQuery obj
		$queryObj = SMWQueryProcessor::createQuery(
			$queryString,
			$processedParams,
			SMWQueryProcessor::SPECIAL_PAGE,
			'',
			$printouts
		);
		return $queryObj;
	}

	/**
	 * Fetch the data type id of a property from 
	 * QueryResult's print requests,
	 * e.g. _wgp, _txt, etc.
	 * @param mixed $propertyName Property name
	 * @param array $printRequests "printrequests" section
	 */
	public static function getDataTypeIdFromPrintRequests(
		string $propertyName,
		array $printRequests
	) {
		$propertyKey = self::getPropertyKey( $propertyName );
		foreach( $printRequests as $req ) {
			// Because printout props can be relabelled,
			// do not rely on req["label"]
			if ( isset($req["key"]) && $req["key"] == $propertyKey ) {
				return $req["typeid"];
			}
			// 'Category' comes without key and can be safely ignored
		}
		return null;
	}

	/**
	 * Extract value(s) or page label(s) from a query result's
	 * printout, specifically intended to fetch label-type strings.
	 * Supports Text, Keyword, Monolingual Text and Page datatypes.
	 * In the case of 'Page' (though presumably rare), a display 
	 * title or pagename is used.
	 * 
	 * @param array|null $printout
	 * @param string $datatypeId ID of property's data type
	 * @param string|null|bool $default Default value to return if nothing was found
	 * @param string $languageCode IETF language tag (BCP47) for Monolingual Text - https://www.semantic-mediawiki.org/wiki/Help:Special_property_Language_code
	 * @param bool $getSingleValue Whether to return only the first value from a multi-valued property (unused for Monolingual Text).
	 * 
	 * @return string|array|null Returns null if no value could be retrieved. Returns a string if getSingleValue=true. Returns an array if getSingleValue=false.
	 */
	public static function getPropertyValueLabelFromPrintout(
		?array $printout,
		?string $datatypeId,
		bool $getSingleValue = true,
		string $languageCode = "en"
	): mixed {
		if ( $printout === null || count( $printout ) === 0 ) {
			return null;
		}

		if ( ( $datatypeId === "_txt" || $datatypeId === "_keyw" ) && gettype( $printout[0] ) === "string" ) {
			// Text or Keyword
			return $getSingleValue ? $printout[0] : $printout;
		} elseif ( $datatypeId == "_mlt_rec" && gettype( $printout[0] ) === "string" ) {
			// Monolingual Text - simple output with +index=1 filter
			return $printout[0];
		} elseif ( $datatypeId == "_mlt_rec" && gettype( $printout[0] ) === "array" ) {
			// Monolingual Text - regular
			$res = $default = null;
			foreach( $printout as $index => $item ) {
				if ( $index == 0 && isset( $item["Text"] ) ) {
					// Redefine default
					$default = $item["Text"]["item"][0];
				}
				if ( isset( $item["Language code"] ) && $item["Language code"]["item"][0] === $languageCode ) {
					$res = $item["Text"]["item"][0];
				}
			}
			return $res ?? $default;
		} elseif ( $datatypeId == "_wpg" ) {
			// Page
			$items = $getSingleValue ? [ $printout[0] ] : $printout;
			$vals = [];
			foreach( $items as $item ) {
				if ( isset( $item["displaytitle"] ) && $printout[0]["displaytitle"] !== "" ) {
					$vals[] = $item["displaytitle"];
				} else {
					$vals[] = $item["fulltext"];
				}
			}
			if ( count ($vals ) === 0 ) {
				return null;
			}
			return $getSingleValue ? $vals[0] : $vals;
		}
		return null;
	}

	/**
	 * @deprecated Moved to SMWQuerySyntaxConverters
	 */
	public static function translateTypesToSMWSyntax( array $types ) {
		return SMWQuerySyntaxConverters::translateTypesToSMWSyntax( $types );
	}

}
