<?php

namespace Recon\Special;

use SpecialPage;
use ExtensionRegistry;
use Recon\ReconUtils;

class ReconSpecial extends SpecialPage {

	private $mainConfig;
	private $extensionPath;
	private $version;

	public function __construct( $name = 'ReconciliationAPI' ) {
		parent::__construct( $name );
		$this->mainConfig = $this->getConfig();
		$this->extensionPath = ReconUtils::getExtensionPath();
		$this->version = $this->getVersion();
	}

	function isExpensive() {
		return false;
	}

	function isSyndicated() {
		return false;
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$output = $this->getContext()->getOutput();

		$fileName = null;
		$res = "";
		switch( $subPage ) {
			case "localsettings":
				global $IP;
				$additionalContent = $this->getWikiFileContents( "localsettings.wiki" );
				$res = $this->getSiteConfigSettings( $additionalContent );
				break;
			case "profiles":
				$fileName = "profiles.wiki";
				$output->setPageTitle( "JSON profiles" );
				break;
			case "matching":
				$fileName = "matching.wiki";
				$output->setPageTitle( "Pattern matching" );
				break;
			case "special-redirect":
				$fileName = "special-redirect.wiki";
				$output->setPageTitle( "Redirect service" );
				break;
			case "typeahead":
				$fileName = "typeahead.wiki";
				$output->setPageTitle( "TypeaheadSearch" );
				break;
			case "recon":
				$fileName = "recon.wiki";
				$output->setPageTitle( "Reconciliation API modules: <code>recon</code>" );
				break;
			case "recon-suggest-property":
				$fileName = "recon-suggest-property.wiki";
				$output->setPageTitle( "Reconciliation API modules: <code>recon-suggest-property</code>" );
				break;
			case "recon-suggest-entity":
				$fileName = "recon-suggest-entity.wiki";
				$output->setPageTitle( "Reconciliation API modules: <code>recon-suggest-entity</code>" );
				break;
			case "recon-suggest-entity-mw":
				$fileName = "recon-suggest-entity-mw.wiki";
				$output->setPageTitle( "Reconciliation API modules: <code>recon-suggest-entity</code> using MediaWiki core" );
				break;
			case "recon-suggest-type":
				$fileName = "recon-suggest-type.wiki";
				$output->setPageTitle( "Reconciliation API modules: <code>recon-suggest-type</code>" );
				break;
			case "recon-suggest-propvalue":
				$fileName = "recon-suggest-propvalue.wiki";
				$output->setPageTitle( "Reconciliation API modules: <code>recon-suggest-propvalue</code>" );
				break;
			case "recon-propose-property":
				$fileName = "recon-propose-property.wiki";
				$output->setPageTitle( "Reconciliation API modules: <code>recon-propose-property</code>" );
				break;
			case "guide":
				$fileName = "guide.wiki";
				$output->setPageTitle( "Reconciliation API: additional guide" );
				break;
			default:
				// Main
				$fileName = "main.wiki";
		}
		// get file internally

		if ( $fileName ) {
			$doc = $this->getWikiFileContents( $fileName );
			$res = $this->insertInPageLayout( $doc, $this->version );
		}

		$output->addModuleStyles( [ 'recon.general.styles' ] );

		$output->addWikiTextAsContent( $res );
	}

	private function getWikiFileContents( $fileName ) {
		$source = $this->extensionPath . "/src/Special/docs/$fileName";
		if ( file_exists( $source ) ) {
			$contents = file_get_contents( $source );
			if ( $contents !== null ) {
				return $contents ?? "";
			}
		}
		return "";
	}

	/**
	 * @param mixed $content
	 * @return string
	 */
	private static function insertInPageLayout( $content, $version ) {
		$menuItems = [
			[ "Special:ReconciliationAPI", "Main" ],
			[ null, "Configuration" ],
			[ "Special:ReconciliationAPI/localsettings", "Local file settings" ],
			[ "Special:ReconciliationAPI/profiles", "JSON profiles" ],
			[ "Special:ReconciliationAPI/matching", "Pattern matching" ],
			[ null, "Modules" ],
			[ "Special:ReconciliationAPI/recon", "recon" ],
			[ "Special:ReconciliationAPI/recon-suggest-entity", "recon-suggest-entity" ],
			[ "Special:ReconciliationAPI/recon-suggest-entity-mw", "recon-suggest-entity (MW)" ],
			[ "Special:ReconciliationAPI/recon-suggest-property", "recon-suggest-property" ],
			[ "Special:ReconciliationAPI/recon-suggest-type", "recon-suggest-type" ],
			[ "Special:ReconciliationAPI/recon-suggest-propvalue", "recon-suggest-propvalue" ],
			[ "Special:ReconciliationAPI/recon-propose-property", "recon-propose-property" ],
			[ null, "Other" ],
			[ "Special:ReconciliationAPI/special-redirect", "Redirect service" ],
			[ "Special:ReconciliationAPI/typeahead", "TypeaheadSearch" ],
			[ "Special:ReconciliationAPI/guide", "Additional usage guide" ]
		];
		$menu = "<li><strong>Reconciliation API</strong><br>v. $version</li>";
		foreach( $menuItems as $item ) {
			if ( $item[0] == null ) {
				$menu .= "<li class='recon-subheading'>{$item[1]}</li>";
			} else {
				$menu .= "<li>[[{$item[0]}|{$item[1]}]]</li>";
			}
		}
		$res = <<<WIKI
		<div class="recon-row">
		<div class="recon-col recon-col-menu recon-order-1 recon-order-md-2">
		__NOTOC__<ul class="recon-list-group">$menu</ul>
		</div>
		<div class="recon-col recon-col-content recon-order-2 recon-order-md-1">$content</div>
		</div>
		WIKI;
		return $res;
	}

	public function getSiteConfigSettings( $additionalContent ) {
		$config = $this->getConfig();
		$propertySettings = [ "ReconAPILabelProp", "ReconAPIAltLabelProp", "ReconAPISearchableLabelProp", "ReconAPIDescriptionProp", "ReconAPIClassProp", "ReconAPIBroaderClassProp", "ReconAPIBroaderConceptProp", "ReconAPIThumbnailProp", "ReconAPISMWClassPropertiesSchema" ];
		$otherSettings = [ "ReconAPIMaxQueries", "ReconAPIMaxResults", "ReconAPIMWUseDisplayTitle", "ReconAPIDefaultTypes", "ReconAPIConsolidateRedirects", "ReconAPIRemoveItalicsFromDisplayTitleColumn", "ReconRedirectDefaultQueryPage", "ReconAPISMWQueryForCategories", "ReconAPISMWQueryForConcepts", "ReconAPISMWQueryForClasses" ];
		$body = "<p class='recon-summary'>The following configuration settings provide defaults which can be customised in your LocalSettings.php file.</p>
		<h3>Properties</h3>";
		foreach ( $propertySettings as $name ) {
			$property = $config->get( $name ) ?? null;
			if ( $property == null) {
				$propertyStr = "<code>null</code>";
			} elseif ( gettype( $property ) == "array" ) {
				$propertyStr = "";
				foreach( $property as $k => $prop ) {
					$propertyStr .= $prop
						? "<div><code>$k</code>: [[Property:$prop | $prop ]]</div>"
						: "<div><code>$k</code>: <code>false</code></div>";
				}
			} else {
				$propertyStr = "[[Property:$property | $property ]]";
			}
			$description = "{{int:reconciliationapi-config-" . strtolower( $name ) . "}}";
			$body .= <<<WIKI
			<div class='recon-data-item'>
				<div style="min-width:300px" ><code>\$wg{$name}</code></div>
				<div>$propertyStr</div>
			</div><p>$description</p>
			WIKI;
		}
		$body .= "<h3>Other</h3>";
		foreach ( $otherSettings as $name ) {
			$propertyVal = $config->get( $name ) ?? null;
			// Create string representation
			switch( gettype( $propertyVal ) ) {
				case "boolean":
					$propertyValStr = $propertyVal ? "true" : "false";
				break;
				case "array":
					$valStrs = [];
					foreach( $propertyVal as $val ) {
						$valStrs[] = "\"{$val}\"";
					}
					$propertyValStr = "<pre>[ " . implode( ", ", $valStrs ) . " ]</pre>";
				break;
				default:
					$propertyValStr = "<pre>$propertyVal</pre>";
			}
			$description = "{{int:reconciliationapi-config-" . strtolower( $name ) . "}}";
			$body .= <<<WIKI
			<div class='recon-data-item'>
				<div style="min-width:300px" ><code>\$wg{$name}</code></div>
				<div>$propertyValStr</div>
			</div><p>$description</p>
			WIKI;
		}
		$body .= $additionalContent;
		$res = self::insertInPageLayout( $body, $this->version );
		return $res;
	}

	private function getVersion() {
		$source = $this->extensionPath . "/extension.json";
		if ( file_exists( $source ) ) {
			$contents = file_get_contents( $source );
			if ( $contents == null ) {
				return "";
			}
		}
		$arr = json_decode( $contents, true );
		return $arr["version"] ?? "?";
	}

}
