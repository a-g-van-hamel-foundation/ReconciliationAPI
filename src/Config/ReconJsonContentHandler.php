<?php

/**
 * Content handler for profile pages (JSON schemas)
 * in the 'Recon' namespace.
 */

namespace Recon\Config;

use MediaWiki\Parser\ParserOutput;
use MediaWiki\Content\Content;
use MediaWiki\Content\Renderer\ContentParseParams;
use MediaWiki\Content\JsonContentHandler;
use MediaWiki\Title\Title;
use MediaWiki\Context\RequestContext;
use Recon\Config\ReconJsonContent;
use Recon\Validation\ReconValidator;
use Recon\ReconUtils;

class ReconJsonContentHandler extends JsonContentHandler {

	public function __construct(
		$modelId = 'reconjson', // alt. CONTENT_MODEL_IIIF_JSON
		$formats = [ CONTENT_FORMAT_JSON ]
	) {
		parent::__construct( $modelId, $formats );
	}

	/**
	 * @return string
	 */
	protected function getContentClass() {
		return ReconJsonContent::class;
	}

	/**
	 * Set default text to be used when starting a new page.
	 * Currently, 'ReconciliationProfile' only.
	 * {@inheritDoc}
	 */
	public function makeEmptyContent() {
		// smw
		$def = [
			"type" => "Profile",
			"name" => "Search entities",
			"source" => "smw",
			"suggestEntity" => [
				"smwquery" => [
					"statement" => [
						[
							"from" => "[[Modification date::+]]", 
							"where" => "[[Display title of::~@@@]]",
							"substringpattern" => "allchars"
						]
					]
				],
				"output" => [
					"name" => [
						"smwproperty" => "Display title of",
						"stripNamespacePrefix" => true
					],
					"description" => [
						"smwproperty" => "Has description"
					],
					"image" => [
						"smwproperty" => "Has primary image"
					]
				],
				"redirect" => [
					"queryPage" => "Special:Search",
					"query" => [
						"fulltext" => "1",
						"search" => ""
					]
				]
			]
		];
		$json = json_encode( $def, JSON_PRETTY_PRINT );
		// wfMessage( 'recon-default-content' )->plain()
		$class = $this->getContentClass();
		return new $class( $json );
	}

	public function supportsPreloadContent(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supportsSections() {
		return false;
	}

	/**
	 * Summary of fillParserOutput
	 * @param Content $content
	 * @param ContentParseParams $cpoParams
	 * @param ParserOutput $parserOutput
	 * @return void
	 */
	protected function fillParserOutput(
		$content,
		$cpoParams,
		&$parserOutput
	): void {
		'@phan-var ReconJsonContent $content';
		$outputPage = RequestContext::getMain()->getOutput();
		$title = Title::castFromPageReference( $cpoParams->getPage() );
		$pageID = $title->getId();
		// Default:
		$profileType = "ReconciliationProfile";

		$parserOutput->addModuleStyles( [ 'recon.general.styles' ] );

		if ( $cpoParams->getGenerateHtml() ) {
			if ( $content->isValid() ) {
				$jsonObj = json_decode( $content->getText(), false );

				// Possibly replace default profile type
				if ( property_exists( $jsonObj, "type" ) && $jsonObj->type === "FacetedSearchProfile" ) {
					$profileType = "FacetedSearchProfile";
				}

				// Validation (none available for FacetedSearchProfile just yet)
				$reconValidator = new ReconValidator();
				$validationMsg = $profileType === "ReconciliationProfile"
					? $reconValidator->validateProfile( $jsonObj )
					: "";

				// Use tabular representation
				$jsonContent = $content->rootValueTable( $content->getData()->getValue() );
				// Custom additions to the wiki page
				$header = $this->buildHeader( $title, $outputPage );
				$footer = $profileType === "ReconciliationProfile"
					? $this->buildFooter( $title, $validationMsg )
					: "";

				$parserOutput->setText( $header . $jsonContent . $footer );
			} else {
				$error = wfMessage( 'invalid-json-data' )->parse();
				$parserOutput->setText( $error );
			}
			$parserOutput->addModuleStyles( [ 'mediawiki.content.json' ] );
		} else {
			$parserOutput->setText( null );
		}

		if ( $profileType === "ReconciliationProfile" ) {
			$wikiWidget = $this->createWidget( $pageID );
			$outputPage->addWikiTextAsContent( $wikiWidget );
		}
	}

	private function buildHeader( Title $title, $outputPage ) {
		$pageId = $title->getId();
		$pageName = $title->getFullText();
		$urlName = urlencode( $pageName );
		$res = <<<WIKI
		<div class='recon-header'>
		<div class='recon-header-left'>
			<span class='label-recon'>page id: {$pageId}</span>
		</div>
		<div class='recon-header-right'>
			
		</div>
		</div>
		WIKI;
		return $res;
	}

	private function buildFooter( $title, $validationMsg = "" ) {
		$profileId = $title->getId();
		$reconConfig = new ReconConfig( $profileId );
		$suggestEntityUrl = $reconConfig->getApiUrl( "recon-suggest-entity" );
		$manifestUrl = $reconConfig->getApiUrl( "recon" );

		$res = <<<WIKI
		<ul class='recon-footer' style='margin-top:1rem'>
			<li>$validationMsg</li>
			<li><a href='$manifestUrl'>View manifest</a></li>
			<li>Try the entity suggester in the browser: <a href='$suggestEntityUrl'><code>$suggestEntityUrl</code></a></li>
		</ul>
		WIKI;

		return $res;
	}

	/**
	 * Set up parser function for the widget that can demonstrate profile
	 * @todo targeturl/footerurl should reflects 'redirect' settings
	 * @param mixed $profileID
	 * @return string
	 */
	private function createWidget( $profileID ) {
		$currentSite = ReconUtils::getURLBase();		
		$build = <<<WIKI
			{{#recon-search:
			|apiurl={$currentSite}/api.php
			|apiurlparams=action=recon-suggest-entity
			profile={$profileID}
			substr=
			|limit=10
			|placeholder=Suggest entity (profile test)...
			|internal=true
			|targeturl={$currentSite}/Special:Search?fulltext=0&search=
			|footerurl={$currentSite}/Special:Search?fulltext=1&search=
			}}
			WIKI;
		return $build;
	}

}
