<?php

namespace Recon\Special;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Registration\ExtensionRegistry;
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

		$content = $this->getPageContentAndSetTitle( $subPage, $output );

		$output->addModuleStyles( [ 'recon.general.styles' ] );
		$output->addWikiTextAsContent( $content );
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

	private function getPageContentAndSetTitle( string|null $subPage, &$output ) {
		$tocItems = $this->getTOC();
		$res = "";

		if ( $subPage === "localsettings" ) {
			// special case
			$additionalContent = $this->getWikiFileContents( "localsettings.wiki" );
			$output->setPageTitle( $tocItems[$subPage]["pagetitle"] );
			return $this->getSiteConfigSettings( $additionalContent );
		}

		// Default to main
		$subPage = array_key_exists( $subPage, $tocItems ) && $tocItems[$subPage]["type"] !== "header" 
			? $subPage 
			: "main";
		$output->setPageTitle( $tocItems[$subPage]["pagetitle"] );
		return $this->insertInPageLayout(
			$this->getWikiFileContents( $tocItems[$subPage]["file"] ),
			$this->version
		);
	}

	/**
	 * @param mixed $content
	 * @return string
	 */
	private function insertInPageLayout( $content, $version ) {
		$tocItems = $this->getTOC();
		$tocMenu = "<li><strong>Reconciliation API</strong><br>v. $version</li>";
		foreach( $tocItems as $k => $item ) {
			if ( $item["type"] === "header" ) {
				$tocMenu .= "<li class='recon-subheading'>{$item["menutitle"]}</li>";
			} elseif( $k === "main" ) {
				$tocMenu .= "<li>[[Special:ReconciliationAPI|{$item["menutitle"]}]]</li>";
			} else {
				$tocMenu .= "<li>[[Special:ReconciliationAPI/{$k}|{$item["menutitle"]}]]</li>";
			}
		}

		$res = <<<WIKI
		<div class="recon-row">
		<div class="recon-col recon-col-menu recon-order-1 recon-order-md-2">
		__NOTOC__<ul class="recon-list-group">$tocMenu</ul>
		</div>
		<div class="recon-col recon-col-content recon-order-2 recon-order-md-1">$content</div>
		</div>
		WIKI;
		return $res;
	}

	public function getSiteConfigSettings( $additionalContent ) {
		$config = $this->getConfig();
		$propertySettings = [ "ReconAPILabelProp", "ReconAPIAltLabelProp", "ReconAPISearchableLabelProp", "ReconAPIDescriptionProp", "ReconAPIClassProp", "ReconAPIBroaderClassProp", "ReconAPIBroaderConceptProp", "ReconAPIThumbnailProp", "ReconAPISMWClassPropertiesSchema" ];
		$otherSettings = [ "ReconAPIQueryTrigger", "ReconAPIMaxQueries", "ReconAPIMaxResults", "ReconAPIMWUseDisplayTitle", "ReconAPIDefaultTypes", "ReconAPIConsolidateRedirects", "ReconAPIRemoveItalicsFromDisplayTitleColumn", "ReconRedirectDefaultQueryPage", "ReconAPISMWQueryForCategories", "ReconAPISMWQueryForConcepts", "ReconAPISMWQueryForClasses" ];
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
		$res = $this->insertInPageLayout( $body, $this->version );
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

	private function getTOC() {
		return [
			"main" => [
				"type" => "page",
				"page" => "Special:ReconciliationAPI",
				"pagetitle" => "Reconciliation API",
				"menutitle" => "Main",
				"file" => "main.wiki"
			],
			"configuration" => [
				"type" => "header",
				"menutitle" => "Configuration"
			],
			"localsettings" => [
				"type" => "page",
				"pagetitle" => "Local file settings",
				"menutitle" => "Local file settings",
				"file" => "localsettings.wiki"
			],
			"profiles" => [
				"type" => "page",
				"menutitle" => "JSON profiles",
				"pagetitle" => "JSON profile pages",
				"file" => "profiles.wiki"
			],
			"profiles-query" => [
				"type" => "page",
				"menutitle" => "JSON profiles, pt 2",
				"pagetitle" => "JSON profiles in the URL string",
				"file" => "profiles-query.wiki"
			],
			"matching" => [
				"type" => "page",
				"menutitle" => "Pattern matching",
				"pagetitle" => "Pattern matching",
				"file" => "matching.wiki"
			],
			"modules" => [
				"type" => "header",
				"menutitle" => "Modules"
			],
			"recon" => [
				"type" => "page",
				"pagename" => "Reconciliation API modules: <code>recon</code>",
				"menutitle" => "recon",
				"file" => "recon.wiki"
			],
			"recon-suggest-entity" => [
				"type" => "page",
				"pagetitle" => "Reconciliation API modules: <code>recon-suggest-entity</code>",
				"menutitle" => "recon-suggest-entity",
				"file" => "recon-suggest-entity.wiki"
			],
			"recon-suggest-entity-mw" => [
				"type" => "page",
				"pagetitle" => "Reconciliation API modules: <code>recon-suggest-entity</code> using MediaWiki core",
				"menutitle" => "recon-suggest-entity (MW)",
				"file" => "recon-suggest-entity-mw.wiki"
			],
			"recon-suggest-property" => [
				"type" => "page",
				"pagetitle" =>  "Reconciliation API modules: <code>recon-suggest-property</code>",
				"menutitle" => "recon-suggest-property",
				"file" => "recon-suggest-property.wiki"
			],
			"recon-suggest-type" => [
				"type" => "page",
				"pagetitle" => "Reconciliation API modules: <code>recon-suggest-type</code>",
				"menutitle" => "recon-suggest-type",
				"file" => "recon-suggest-type.wiki"
			],
			"recon-suggest-propvalue" => [
				"type" => "page",
				"pagetitle" => "Reconciliation API modules: <code>recon-suggest-propvalue</code>",
				"menutitle" => "recon-suggest-propvalue",
				"file" => "recon-suggest-propvalue.wiki"
			],
			"recon-propose-property" => [
				"type" => "page",
				"pagetitle" => "Reconciliation API modules: <code>recon-propose-property</code>",
				"menutitle" => "recon-propose-property",
				"file" => "recon-propose-property.wiki"
			],
			"other" => [
				"type" => "header",
				"menutitle" => "Other"
			],
			"special-redirect" => [
				"type" => "page",
				"pagetitle" => "Redirect service",
				"menutitle" => "Redirect service",
				"file" => "special-redirect.wiki"
			],
			"typeahead" => [
				"type" => "page",
				"pagetitle" => "Typeahead search widget",
				"menutitle" => "Typeahead search widget",
				"file" =>  "typeahead.wiki"
			],
			"testbench" => [
				"type" => "page",
				"pagetitle" => "Testbench",
				"menutitle" => "Testbench",
				"file" => "testbench.wiki"
			],
			"guide" => [
				"type" => "page",
				"menutitle" => "Additional usage guide",
				"pagetitle" => "Reconciliation API: additional guide",
				"file" => "guide.wiki"
			]
		];
	}

}
