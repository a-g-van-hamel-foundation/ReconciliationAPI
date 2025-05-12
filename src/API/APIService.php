<?php

/**
 * The starting point for the Reconciliation API.
 * Returns a manifest unless `queries` or `extend` is used.
 * No flyout services: may well be dropped in the future
 * schemaSpace and identifierSpace will be dropped in v0.3
 * 
 * @link https://reconciliation-api.github.io/specs/0.2/
 * @link https://www.w3.org/community/reports/reconciliation/CG-FINAL-specs-0.2-20230410/
 * @link https://data.biblissima.fr/reconcile/fr/api
 * (example)
 */

namespace Recon\API;

use MediaWiki\MainConfigNames;
//use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;
use Recon\ReconUtils;
use Recon\Config\ReconConfig;
use Recon\SMW\SMWUtils;
use Recon\API\APIReconQueryHandler;
use Recon\MW\MWExtendQueryRequest;
use Recon\SMW\SMWExtendQueryRequest;
use Recon\MW\MWUtils;
use Recon\SMW\SMWSuggestType;

class APIService extends \ApiBase {

	private $extensionName = "ReconciliationAPI";
	private $moduleName = "recon";
	private $apiVersion = "0.2";
	// Setting. May be overriden by profile.
	private $source;
	private $profileID = null;
	private $isSMWStoreAvailable;
	private $wgReconAPIDefaultTypes = [];
	private $defaultTypes = [];

	public function execute() {
		$params = $this->extractRequestParams();

		$urlBase = ReconUtils::getURLBase();
		$extensionJson = ReconUtils::fetchExtensionJson();

		$extensionVersion = ( $extensionJson !== false && array_key_exists( "version", $extensionJson ) ) ? $extensionJson["version"] : [ "version" => "?" ];

		$wgLogo = ReconUtils::getSiteLogo();
		$this->source = $params["source"];
		$this->profileID = $params["profile"] ?? null;
		$this->isSMWStoreAvailable = SMWUtils::isSMWStoreAvailable();
		$substrPattern = $params["substrpattern"];
		
		if ( isset( $params["displaytitle"] ) && $params["displaytitle"] == "1" ) {
			// Should only ever be used to override label property
			$useDisplayTitle = true;
		} elseif( isset( $params["displaytitle"] ) && $params["displaytitle"] == "0" ) {
			$useDisplayTitle = false;
		} else {
			// Leave the decision to profile or default setting
			$useDisplayTitle = null;
		}

		// Common building blocks of the URL
		$originUrlParam = "&origin=*";
		$formatUrlParams = "&format=json&formatversion=2";
		$profileUrlParam = $this->profileID !== null
			? "&profile={$this->profileID}"
			: "";
		$commonUrlParams = $originUrlParam . $formatUrlParams . $profileUrlParam;
		$sourceParam = "&source={$this->source}";
		$id = "{$urlBase}/api.php?action=recon" . $commonUrlParams;

		$queries = $params["queries"] ?? false;
		if ( $queries !== false ) {
			$apiReconQueryHandler = new APIReconQueryHandler(
				$this->source,
				$this->profileID,
				$substrPattern,
				$useDisplayTitle
			);
			$res = $apiReconQueryHandler->runQuery( $queries );
			$apiResult = $this->getResult();
			foreach( $res as $k => $v ) {
				$apiResult->addValue( null, $k, $v );
			}
			$this->setCache();
			return;
		}

		// 'extend' may hold a URL-encoded query request
		$extend = $params["extend"] ?? false;
		if ( $extend !== false && $extend !== "" ) {
			$res = [];
			if ( $this->source == "mw" ) {
				$mwExtendQueryRequest = new MWExtendQueryRequest();
				$res = $mwExtendQueryRequest->run( $extend );
			} elseif( $this->source == "smw" && $this->isSMWStoreAvailable ) {
				$smwExtendQueryRequest = new SMWExtendQueryRequest();
				$res = $smwExtendQueryRequest->run( $extend );
			}
			$apiResult = $this->getResult();
			foreach( $res as $k => $v ) {
				$apiResult->addValue( null, $k, $v );
			}
			$this->setCache();
			return;
		}

		// No 'queries' or 'extend'? Default to manifest
		if ( $this->profileID !== null ) {
			$configProfiler = new ReconConfig( $this->profileID );
			$configProfile = $configProfiler->getProfile();
			if ( $configProfile["exists"] == 1 ) {
				$profilePage = $configProfile["profilepage"]["fullpagename"];
			} else {
				$profilePage = false;
			}
			$name = $configProfile["name"];
			$this->source = $configProfile["source"] ?? "mw";
			$previewUrlStr = $this->profileID;

			// Assuming for now we're using SMW
			$suggestEntityService = [
				"service_url" => "{$urlBase}/api.php?action=recon-suggest-entity&source={$this->source}" . $commonUrlParams,
				"service_path" => ""
			];
			$suggestPropertyService = [
				"service_url" => "{$urlBase}/api.php?action=recon-suggest-property&source={$this->source}" . $commonUrlParams,
				"service_path" => ""
			];
			$suggestTypeService = [
				"service_url" => "{$urlBase}/api.php?action=recon-suggest-type&source={$this->source}" . $commonUrlParams,
				"service_path" => "",
				"comment" => "unless MediaWiki categories are used. Again point to a profile ID."
			];
			$suggestPropValueService = [
				"service_url" => "{$urlBase}/api.php?action=recon-suggest-propvalue&source={$this->source}" . $commonUrlParams . "&property=",
				"service_path" => "",
				"comment" => "Unoffical addition to the specifications."
			];
			$this->wgReconAPIDefaultTypes = $this->getConfig()->get( "ReconAPIDefaultTypes" ) ?? [];
			$this->defaultTypes = !$configProfile["defaultTypes"]
				? $this->wgReconAPIDefaultTypes
				: $configProfile["defaultTypes"];
			// Data Extension Metadata 
			// @todo for profiles
			// Settings for the data extension protocol, to fetch property values
			// data extension property proposal service returns properties for a given type identifier.
			$extendService = [
				// array of data extension property settings
				"property_settings" => [],
				// service path object defining a URL which implements data extension property proposal
				// example https://lobid.org/gnd/reconcile/properties
				"propose_properties" => [
					"service_url" => "{$urlBase}/api.php?action=recon-propose-property". $commonUrlParams . $sourceParam,
					"service_path" => ""
				]
			];
		} else {
			// default setup (MW or SMW)
			$profilePage = false;
			$wgSitename = $this->getConfig()->get( MainConfigNames::Sitename );
			$name = "{$wgSitename} - Reconciliation Service";
			$previewUrlStr = "generic";

			$this->wgReconAPIDefaultTypes = $this->getConfig()->get( "ReconAPIDefaultTypes" ) ?? [];
			if ( $this->source == "smw" ) {
				$this->defaultTypes = $this->wgReconAPIDefaultTypes;
			} elseif( $this->source == "mw" ) {
				$this->defaultTypes = $this->siftTypesForCategories( $this->wgReconAPIDefaultTypes );
			}

			// MediaWiki-based defaults
			$suggestEntityService = [
				"service_url" => "{$urlBase}/api.php?action=recon-suggest-entity" . $commonUrlParams . $sourceParam . "&substrpattern={$substrPattern}",
				"service_path" => ""
			];
			$suggestPropertyService = [
				"service_url" => "{$urlBase}/api.php?action=recon-suggest-property" . $commonUrlParams . $sourceParam,
				"service_path" => ""
			];
			$suggestTypeService = [
				"service_url" => "{$urlBase}/api.php?action=recon-suggest-type" . $commonUrlParams . $sourceParam . "&substrpattern={$substrPattern}",
				"service_path" => ""
			];
			$suggestPropValueService = false;
		}

		// Data Extension Metadata service not yet configurable with a profile:
		if ( $this->source == "smw" ) {
			// Settings for the data extension protocol, to fetch property values
			// data extension property proposal service returns properties for a given type identifier.
			$extendService = [
				// array of data extension property settings
				"property_settings" => [
					[
						"name" => "limit",
						"label" => "limit",
						"type" => "number",
						"default" => 0,
						"help_text" => "Maximum number of values to return per row (0 for no limit)"
					],
					[
						"name" => "order",
						"label" => "order",
						"type" => "select",
						"choices" => [
							[ "value" => "", "name" => "none" ],
							[ "value" => "asc", "name" => "ascending" ],
							[ "value" => "desc", "name" => "descending" ]
						],
						"default" => "asc",
						"help_text" => "Order by which to sort property values."
					]
				],
				// service path object defining a URL which implements data extension property proposal
				// example https://lobid.org/gnd/reconcile/properties
				"propose_properties" => [
					"service_url" => "{$urlBase}/api.php?action=recon-propose-property" . $commonUrlParams . $sourceParam,
					"service_path" => ""
				]
			];
		} else {
			// MW
			$extendService = [
				"property_settings" => [],
				"propose_properties" => [
					"service_url" => "{$urlBase}/api.php?action=recon-propose-property" . $commonUrlParams . $sourceParam,
					"service_path" => ""
				]
			];
		}

		// Get config settings specific to extension
		// @todo only if SMW is installed. Default to null.

		$configSettings = $this->getConfigSettings();
		$props = [
			"versions" => [ $this->apiVersion ],
			// @todo Json profile may want to use a unique descriptive name.
			"name" => $name,
			// URI describing the entity identifiers used in this service
			"identifierSpace" => "{$urlBase}/Special:Recon/entity",
			// ? URI describing the schema used in this service
			//"schemaSpace" => "{$urlBase}/Special:Recon/prop/redirect"
			"schemaSpace" => "http://www.w3.org/2004/02/skos/core#Concept",
			"documentation" => "{$urlBase}/api.php?action=help&format=json&formatversion=2&modules={$this->moduleName}",
			"serviceVersion" => "MediaWiki with the {$this->extensionName} extension, version {$extensionVersion}",
			// @todo types, e.g. a category or concept, to be set from profile? From config?
			// may also refer to another JSON file?
			"preview" => [
				// generic or /{profileid}
				// @todo Allows for alternative service based on 
				// page ID. Should we accommodate this?
				"url" => "{$urlBase}/Special:ReconPreview/{$previewUrlStr}/page/{{id}}",
				"height" => 200,
				"width" => 350
			],
			"logo" => "{$urlBase}{$wgLogo}",
			// @todo option to request users to provide an authentication token
			// e.g. for OAuth 2. Examples
			// "authentication" => [ "type" => "html", "scheme" => "basic" ],
			// "authentication" => [ "type" => "apiKey", "name" => "api_key", "in" => "query" ],
			"view" => [
				// template transforming an entity identifier into the corresponding URI
				// @todo again don't assume short URLs
				"url" => "{$urlBase}/{{id}}"
			],
			"feature_view" => [
				// template to transform a matching feature identifier into the corresponding URI
				// @todo handling for non-existing pages with semantic relationships in SMW
				"url" => "{$urlBase}/{{id}}"
			],
			"suggest" => [
				// Currently, SMW only. Check if SMW is installed
				"entity" => $suggestEntityService,
				"type" => $suggestTypeService,
				"property" => $suggestPropertyService,
				"value" => $suggestPropValueService
			],
			// Data Extension Metadata
			// Settings for the data extension protocol, to fetch property values
			// data extension property proposal service returns properties for a given type identifier.
			"extend" => $extendService
		];
		if ( !empty($this->defaultTypes) && $this->defaultTypes !== null ) {
			// smw only @todo
			$smwSuggestType = new SMWSuggestType();
			$props["defaultTypes"] = $smwSuggestType->getOutputForDefaultTypes( $this->defaultTypes );
		}

		// Add a meta section
		$meta = [
			"@id" => $id,
			"@context" => "https://reconciliation-api.github.io/specs/{$this->apiVersion}/schemas/manifest.json",
			"software" => [
				"smw" => $this->isSMWStoreAvailable ? 1 : 0
			],
			"help" => [ "{$urlBase}/Special:ReconciliationAPI", "{$urlBase}/api.php?action=help&format=json&formatversion=2&modules={$this->moduleName}" ],
			"config" => $configSettings			
		];
		if ( $this->profileID !== null ) {
			$meta["profileID"] = intval( $this->profileID );
			$meta["profilePage"] = $profilePage;
		}
		$props["meta"] = $meta;

		$res = $props;
		$apiResult = $this->getResult();
		foreach( $res as $key => $val ) {
			$apiResult->addValue( null, $key, $val );
		}
		$this->setCache();
	}

	/**
	 * Get configuration settings
	 * @return array
	 */
	private function getConfigSettings() {
		$config = $this->getConfig();
		$propertySettings = [ "ReconAPILabelProp", "ReconAPIAltLabelProp", "ReconAPISearchableLabelProp", "ReconAPIDescriptionProp", "ReconAPIClassProp", "ReconAPIBroaderClassProp", "ReconAPIBroaderConceptProp", "ReconAPIThumbnailProp" ];
		$otherSettings = [ "ReconAPIMaxQueries", "ReconAPIMaxResults", "ReconAPIDefaultTypes", "ReconAPIConsolidateRedirects", "ReconAPIRemoveItalicsFromDisplayTitleColumn", "ReconRedirectDefaultQueryPage" ];
		$settingNames = array_merge( $propertySettings, $otherSettings );		
		$configSettings = [];
		foreach( $settingNames as $name ) {
			$configSettings["wg{$name}"] = $config->get( $name );
		}
		return $configSettings;
	}

	/**
	 * Helper function to filter on types and get categories only.
	 * @param mixed $types
	 * @return array
	 */
	private function siftTypesForCategories( $types ) {
		$categories = [];
		foreach( $types as $type ) {
			if( MWUtils::isCategory( $type ) ) {
				$categories[] = $type;
			}
		}
		return $categories;
	}

	public function getAllowedParams() : array {		
		$arr = [
			"source" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => "mw"
			],
			"profile" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			"substrpattern" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				// @todo maybe default to global setting
				ParamValidator::PARAM_DEFAULT => "stringprefix"
			],
			"displaytitle" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			// MUST support HTTP POST requests on its endpoint with application/x-www-form-urlencoded bodies containing a reconciliation query batch (serialized in JSON) in a form element named queries. 
			// SHOULD support HTTP GET requests with a reconciliation query batch in a query string parameter named queries.
			"queries" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			// URL-encoded data extension query
			"extend" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			// @todo Unused: option to be considered
			"limit" => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => $this->getConfig()->get( "ReconAPIMaxResults" )
			]
		];
		return $arr;
	}

	private function setCache() {
		$this->getMain()->setCacheMaxAge( 3600 );
		$this->getMain()->setCacheMode( 'private' );
	}

}
