<?php

/**
 * Processes config data from a JSON profile schema in the wiki.
 * 
 * @todo Consolidate $config and $profile
 */

namespace Recon\Config;

use \Title;
use \Article;
use \MediaWiki\MediaWikiServices;
use \MediaWiki\Revision\RevisionRecord;
use Recon\ReconUtils;
use Recon\SMW\SMWUtils;
use Recon\SMW\SMWSuggestType;

class ReconConfig {

	private $isSMWStoreAvailable = true;
	private $pageID = false;
	private $fullPageName = null;
	private $name = "";
	private $type = null;
	private $possibleTypes = [ "Profile", "ReconciliationManifest", "SuggestEntityConfig" ];
	private $possibleSources = [ "mw", "smw" ];
	private $source = null;

	private $suggestEntity = [];
	private $smwquery = [];
	private $smwqueryStatement = null;
	// placeholder for the substring, currently fixed:
	private $smwqueryPlaceholder = "@@@";
	private $substringPattern = null;

	// Output-related:
	// Mapping is @deprecated
	private $mapping = [];
	private $output = [];
	private $labelProperty = null;
	private $descriptionProperty = null;
	private $imageProperty = null;
	private $imageExtension = null;
	private $stripLabel = false;
	private $stripDescription = false;

	private $profileSchema = [];

	// @todo mistake for profile??
	private $config = [
		"name" => false,
		"source" => false
	];

	// work in progress
	private $profile = [
		"name" => false,
		"type" => false,
		"source" => false,
		"profilepage" => [
			"pageid" => false,
			"fullpagename" => false,
		],
		"mwquery" => [
			"categories" => false,
			"namespaces" => false,
			"useDisplayTitle" => true
		],
		"smwquery" => false,
		// printout:
		"smwProperties" => [
			"label" => null,
			"description" => null,
			"thumbnail" => null
		],
		"output" => [
			"name" => [
				"useDisplayTitle" => true
			],
			"description" => false,
			"thumb" => []
		],
		// deprecated, use output
		"printout" => [
			"name" => false,
			"description" => false,
			"thumb" => []
		],
		"exists" => false,
		"redirect" => false,
		"defaultTypes" => false
	];

	public function __construct( $pageID, $profile = null ) {
		// @todo support direct array and population from page
		if ( $pageID ) {
			$this->pageID = $pageID;
			$this->setProfileFromConfigPage( $pageID );
		} elseif( $profile ) {
			// get pageID from URL?
			// presuming setProfileFromConfigPage() is done manually
			$this->$profile = $profile;
		}
		$this->isSMWStoreAvailable = SMWUtils::isSMWStoreAvailable();
	}

	/** 
	 * Read and set profile data from JSON page.
	 * Populates $this->profile (arr)
	 */
	public function setProfileFromConfigPage( $pageID ) {
		// get from JSON page
		// @todo check first if ID resolves to page ID AND contains valid JSON

		$this->profile["profilepage"]["pageid"] = $pageID;
		$title = Title::newFromID( $pageID, 0 );
		if ( $title == null ) {
			$this->profile["exists"] = false;
			return;
		} else {
			$this->profile["exists"] = true;
		}
		$this->profile["profilepage"]["fullpagename"] = $this->fullPageName = $title->getPrefixedText();

		// Full schema from the wiki page:
		$this->profileSchema = $profileSchema = $this->getProfileSchemaFromPage( $title );
		// Validate?

		$this->profile["type"] = $this->type = $profileSchema["type"] ?? null;
		$this->profile["name"] = $this->name = $profileSchema["name"] ?? null;

		// @todo
		if( isset( $profileSchema["source"] )
			&& $profileSchema["source"] == "smw"
		) {
			$this->profile["source"] = $this->source = $this->isSMWStoreAvailable ? "smw" : null;
		} elseif( !isset( $profileSchema["source"] )
			&& isset( $profileSchema["suggestEntity"]["smwquery"] )
			&& $this->isSMWStoreAvailable
		) {
			// 'source' is left unspecified but 'smwquery' is used
			$this->profile["source"] = $this->source = "smw";
		} elseif( !isset( $profileSchema["source"] ) ) {
			$this->profile["source"] = $this->source = null;
		} else {
			$this->profile["source"] = $this->source = $profileSchema["source"];
		}

		$this->profile["substringPattern"] = $this->substringPattern = $profileSchema["substringPattern"] ?? $profileSchema["substringpattern"] ?? null;

		if ( isset( $profileSchema["suggestEntity"] ) ) {
			$this->processSuggestEntityConfig( $profileSchema["suggestEntity"] );
		} else {
			// @deprecated
			$this->processSuggestEntityConfig( $profileSchema );
		}

		// Generic to all suggest services?
		$this->profile["redirect"] = $profileSchema["redirect"] ?? false;

		if ( isset( $profileSchema["defaultTypes"] ) && $this->source == "smw" ) {
			$this->profile["defaultTypes"] = $profileSchema["defaultTypes"];
			// $smwSuggestType = new SMWSuggestType();
			// $this->profile["defaultTypes"] = $smwSuggestType->getOutputForDefaultTypes( $profileSchema["defaultTypes"] );
		}

		// @todo wgSitename
		GLOBAL $wgSitename;
		$this->config = [
			"name" => $this->name ?? "$wgSitename Reconciliation Service",
			"source" => $this->source
		];
	}

	/**
	 * Takes the JSON page identified by Page ID and returns 
	 * array from JSON
	 * 
	 * @param mixed $title
	 * @return mixed
	 */
	private function getProfileSchemaFromPage( $title ) {
		$article = new Article( $title );
		$wikiPage = $article->getPage();
		// should work for JsonContent
		// @warning accesses page regardless of page permissions
		// else use RevisionRecord::FOR_THIS_USER / FOR_PUBLIC
		$jsonRaw = $wikiPage->getContent( RevisionRecord::RAW )->getText();
		$res = json_decode( $jsonRaw, true );
		return $res;
	}

	public function getProfileSchema() {
		return $this->profileSchema;
	}

	public function getConfig() {
		return $this->config;
	}

	public function getProfile(): array {
		return $this->profile;
	}

	public function getFullPageName() {
		return $this->fullPageName;
	}

	/**
	 * Get type of source.
	 * @return mixed string or null
	 */
	public function getSource() {
		return $this->source;
	}

	/**
	 * Get SMW query statement with placeholder left unreplaced.
	 * @return mixed string or null
	 */
	public function getSMWQuery() {
		return $this->smwqueryStatement;
	}

	public function getSubstringPattern() {
		return $this->substringPattern;
	}
	
	public function getOutput() {
		return $this->output;
	}

	/**
	 * SMW only. To be run after setup.
	 * @return array
	 */
	public function getOutputPropertyInfo() {
		return [
			"label" => $this->labelProperty,
			"description" => $this->descriptionProperty,
			"image" => $this->imageProperty,
			"stripLabel" => $this->stripLabel,
			"stripDescription" => $this->stripDescription
		];
	}

	public function getDescriptionProperty() {
		return $this->descriptionProperty;
	}

	public function getApiUrl( $service = "recon" ) {
		// 1 url + api.php
		$urlBase = ReconUtils::getURLBase();
		// 2. action= ?
		// $type = $this->profile["type"] ?? false;
		// maybe use action not type in config
		$action = "";
		switch( $service ) {
			case "recon-suggest-entity":
			case "SuggestEntityConfig":
				$action = "recon-suggest-entity";
				break;
			default:
				$action = $service;
		}
		$queryParams = [
			"action" => $action,
			"origin" => "*",
			"format" => "json",
			"formatversion" => 2,
			"source" => $this->profile["source"],
			"profile" => $this->profile["profilepage"]["pageid"],
			"substrpattern" => $this->profile["substringPattern"],
		];
		if ( $service !== "recon" ) {
			$queryParams["substr"] = "";
		}
		$queryParamsStr = http_build_query( $queryParams, "", "&" );

		$str = $urlBase . "/api.php?" . $queryParamsStr;
		return $str;
	}

	private function processSuggestEntityConfig( $config ) {
		// MW
		if( $this->source == "mw" || isset( $config["mwquery"] ) ) {
			$this->source = "mw";
			$this->profile["mwquery"] = $config["mwquery"];
			// @todo
			//$categories = $this->profile["mwquery"]["categories"];
			//$namespaces = $this->profile["mwquery"]["namespaces"];
			$this->mapping = $config["mapping"] ?? null;
			$this->output = $config["output"] ?? $this->mapping;
			$profile["output"] = $this->output;
		} elseif( $this->source == "smw" ) {
			$this->smwquery = $config["smwquery"] ?? [];
			$this->smwqueryStatement = $this->smwquery["statement"] ?? null;
			
			$this->mapping = $config["mapping"] ?? [];
			$this->output = $config["output"] ?? $this->mapping;
			$profile["output"] = $this->output;
		}

		if ( $this->output == null ) {
			return;
		}

		if ( $this->source == "mw" ) {
			// MW: use display title, no description...
			$this->imageExtension = $this->output["image"]["extension"] ?? null;
			if ( $this->imageExtension !== null ) {
				// $this->profile["output"]["image"]["extension"] = $this->imageExtension;
				$this->profile["output"]["thumb"]["extension"] = $this->imageExtension;
			}
		} elseif( $this->source == "smw" ) {
			// SMW:
			if ( isset( $this->output["name"]["smwproperty"] ) ) {
				// null means: check default
				// false means: don't use a property at all
				$this->labelProperty = $this->output["name"]["smwproperty"];
			} else {
				$this->labelProperty = null;
			}
			$this->profile["smwProperties"]["label"] = $this->labelProperty;
			$this->stripLabel = $this->output["name"]["striptags"] ?? false;
			$this->descriptionProperty = $this->output["description"]["smwproperty"] ?? null;
			$this->stripDescription = $this->output["description"]["striptags"] ?? false;
			$this->imageProperty = $this->output["image"]["smwproperty"] ?? null;
		}
	}

	/**
	 * @deprecated
	 * @return array
	 */
	public function getMappingPropertyInfo() {
		return $this->getOutputPropertyInfo();
	}

}
