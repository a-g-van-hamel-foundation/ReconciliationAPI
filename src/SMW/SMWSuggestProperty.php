<?php

/**
 * SMW implementation of service to suggest properties.
 * 
 * @todo Set config setting for result limit as default
 * @todo Get language code for description and provide fallback (English?)
 * @todo Maybe use addExtraCondition on RequestOptions
 * @todo Maybe render wikitext from description in HTML.
 * 
 * @link https://www.w3.org/community/reports/reconciliation/CG-FINAL-specs-0.2-20230410/#suggest-services
 * @example (v1!) https://offshoreleaks.icij.org/api/v1/reconcile/suggest/property?prefix=da
 * @link https://github.com/SemanticMediaWiki/SemanticMediaWiki/blob/master/includes/querypages/PropertiesQueryPage.php#L26
 */

namespace Recon\SMW;

use Title;
use SMW\SQLStore\SQLStore;
use SMW\StoreFactory;
use SMW\Services\ServicesFactory;
use SMW\DataValueFactory;
use SMW\DataValues\TypesValue;
use SMW\DataValues\ValueFormatters\DataValueFormatter;
use SMW\RequestOptions;
use SMW\StringCondition;
use SMW\DIProperty;
use Recon\MW\MWUtils;
use Recon\SMW\SMWMappingUtils;
use Recon\StringModification\StringModifier;
use Recon\Localisation\ReconLocalisation;

class SMWSuggestProperty {

	private $store;
	private $requestOptions;
	private $profile = null;
	private $settings;
	private $stringCondition;
	private $substring;
	private $resultLimit = 25;
	private $resultBatchCount;
	private $resultOffset;
	private $nextOffset = 0;
	// @todo
	private $languageCode = "en";

	public function __construct( $stringCondition = "prefix" ) {
		// @todo only if SMW is installed
		$this->store = StoreFactory::getStore();
		$this->requestOptions = new RequestOptions();
		$this->settings = ServicesFactory::getInstance()->getSettings();
		switch( $stringCondition ) {
			case "mid":
				$this->$stringCondition = StringCondition::COND_MID;
			default:
				$this->$stringCondition = StringCondition::COND_PRE;
		}
	}

	/**
	 * Summary of getResults
	 * @todo rename to run()
	 * @param string $subStr
	 * @return array
	 */
	public function getResults(
		string $substring,
		int $offset = 0,
		int $limit = 25
	): array {
		if ( $this->store == null ) {
			return [];
		}
		$this->substring = $substring;
		$this->requestOptions->setLimit( $limit );
		$this->resultLimit = $limit;
		$this->resultOffset = $offset;
		if ( $offset > 0 ) {
			$this->requestOptions->setOffset( $offset );
		}

		// SQL method to return array of array( SMW\DIProperty| ?SMW\DIError, integer )
		$lookupList = $this->getListLookup( $substring )->fetchList() ?? [];
		$this->resultBatchCount = count( $lookupList );
		// @todo Not waterproof. What if the final batch count equals 
		// the result limit and there is nothing else to show?
		$this->nextOffset = ( $this->resultBatchCount < $this->resultLimit )
			? 0
			: $this->resultOffset + $this->resultLimit;

		$items = [];
		$pseudoProps = $this->findPseudoProperties( $substring );
		if ( !empty( $pseudoProps ) ) {
			foreach( $pseudoProps as $prop ) {
				$items[] = $prop;
			}
		}

		foreach ( $lookupList as $prop ) {
			[ $dataItem, $useCount ] = $prop;
			// Cf. https://github.com/SemanticMediaWiki/SemanticMediaWiki/blob/master/includes/querypages/PropertiesQueryPage.php#L26
			if ( $dataItem instanceof DIProperty ) {
				$items[] = $this->formatDIPropertyItem( $dataItem, $useCount );
			} elseif ( $dataItem instanceof SMWDIError ) {
				// @todo skip for now
			}
		}

		return [
			"result" => $items,
			"meta" => [
				"service" => "Suggest SMW properties",
				"description" => "Suggest SMW properties whose names match on '{$this->substring}'",
				"source" => "smw",
				"substring" => $this->substring,
				"substringPattern" => ( $this->stringCondition == StringCondition::COND_MID ) ? "mid" : "prefix",
				"resultBatchCount" => $this->resultBatchCount,
				"resultLimit" => $this->resultLimit,
				"resultOffset" => $this->resultOffset,
				"nextOffset" => $this->nextOffset
			]
		];
	}

	/**
	 * @return \SMW\SQLStore\Lookup\CachedListLookup
	 */
	public function getListLookup( string $subStr = "" ) {
		$this->requestOptions->addStringCondition( $subStr, $this->stringCondition );
		$propsList = $this->store->getPropertiesSpecial( $this->requestOptions );
		return $propsList;
	}

	/**
	 * @param mixed DataItem or ?null
	 * @param mixed $useCount
	 * @return mixed
	 */
	private function formatDIPropertyItem( mixed $property, $useCount ) {
		//$diWikiPage = $property->getDiWikiPage();
		$diWikiPage = $property->getCanonicalDiWikiPage();

		$propLookup = ServicesFactory::getInstance()->getPropertySpecificationLookup();
		$propDescription = $propLookup->getPropertyDescriptionByLanguageCode( $property, $this->languageCode );

		if ( $property->isUserDefined() ) {
			$title = ( $diWikiPage !== null ) ? $diWikiPage->getTitle() : null;
			if ( $title === null ) {
				// @todo Not all properties come with a wiki page
				return [];
			}
			$userDefined = true;
			list( $id, $name, $dataTypeStr ) = $this->getUserDefinedPropertyInfo( $title, $property, $useCount );
			$description = ( $propDescription) ? "$propDescription. Data type: $dataTypeStr" : "Data type: $dataTypeStr";
		} else {
			$userDefined = false;
			// [ $typestring, $proplink ] = $this->getPredefinedPropertyInfo( $property );
			list( $id, $name, $dataTypeStr ) = $this->getPreDefinedPropertyInfo( $property );
			$description = "$propDescription ";
			$description .= ( $dataTypeStr !== "" ) ? "(Data type: $dataTypeStr)" : "";
		}

		// @todo Currently settling on 'Property' but must be multilingual
		//$propertyNSName = MWUtils::getNamespaceNameFromIndex( SMW_NS_PROPERTY );
		$propertyNSNameLocal = ReconLocalisation::getNamespaceName( SMW_NS_PROPERTY );

		$res = [
			"id" => "{$propertyNSNameLocal}:{$id}",
			"name" => $name,
			"description" => $description,
			"other" => [
				"pagename" => $id,
				"fullpagename" => "{$propertyNSNameLocal}:{$id}",
				"highlighted" => StringModifier::createHighlightedString( $name, $this->substring ),
				"userdefined" => $userDefined ? 1 : 0
			]
		];

		if ( $userDefined ) {
			$res["other"]["fullpagename"] = "$propertyNSNameLocal:$name";
			$res["other"]["pageid"] = MWUtils::getPageIDFromPagename(  "$propertyNSNameLocal:$name" );
		}

		return $res;
	}

	/**
	 * @todo Being moved to SMWUtils
	 */
	private function getUserDefinedPropertyInfo( Title $title, $property, int $useCount ) {
		$label = htmlspecialchars( $property->getLabel() );
		$dataTypeStr = $this->getPropertyType( $property );
		// $displaytitle
		return [ $label, $label, $dataTypeStr ];
	}

	/**
	 * @todo Being moved to SMWUtils
	 */
	private function getPreDefinedPropertyInfo( DIProperty $property  ) {
		$dataValue = DataValueFactory::getInstance()->newDataValueByItem( $property, null );
		//$propId = $property->id;
		//$propKey = $property->getKey();
		$label = $property->getLabel();
		$dataTypeStr = TypesValue::newFromTypeId( $property->findPropertyValueType() )->getLongHTMLText();		
		return [ $label, $label, $dataTypeStr ];
	}

	/**
	 * @todo Being moved to SMWUtils
	 */
	public function getPropertyType( DIProperty $property ): string {
		$defaultType = $this->settings->get( 'smwgPDefaultType' );
		$defaultTypeStr = TypesValue::newFromTypeId( $defaultType )->getLongHTMLText();

		$typeProperty = new DIProperty( '_TYPE' );
		$types = $this->store->getPropertyValues( $property->getDiWikiPage(), $typeProperty );
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
	 * Categories and concepts will be treated as 'pseudo-properties'
	 * @todo eventually, consider if namespaces should be included.
	 * @todo make configurable/optional
	 * @todo localisation
	 */
	private function findPseudoProperties( $substring ) {
		$propNames = [ "concept", "category", "namespace" ];
		$localPropNames = [];
		// $localPropNames[] = strtolower( ReconLocalisation::getNamespaceName( NS_CATEGORY ) );
		// $localPropNames[] = strtolower( ReconLocalisation::getNamespaceName( SMW_NS_CONCEPT ) );
		$vals = array_unique( array_merge( $propNames, $localPropNames ) );

		$matches = [];
		foreach ( $vals as $val ) {
			if ( str_contains( 
				strtolower( $val ),
				trim(strtolower( $substring ) )
			) ) {
				$matches[] = $val;
			}
		}
		$res = [];
		foreach ( $matches as $match ) {
			$res[] = [
				"id" => "$match",
				"name" => $match,
				"description" => $match
			];
		}
		return $res;
	}

}
