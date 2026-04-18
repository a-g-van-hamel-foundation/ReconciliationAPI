<?php

namespace Recon;

use MediaWiki\Title\Title;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Revision\Hook\ContentHandlerDefaultModelForHook;
use MediaWiki\Extension\CodeEditor\Hooks\CodeEditorGetPageLanguageHook;
// AdminLinks
use AdminLinksHook;
use ALItem;
use ALSection;
use ALTree;
use Recon\ParserFunctions\ReconSearch;
use Recon\ParserFunctions\ReconSMWQueryUrl;
use Recon\ParserFunctions\ReconQueryHelper;

class ReconHooks implements
	ParserFirstCallInitHook,
	ContentHandlerDefaultModelForHook,
	BeforePageDisplayHook
	// AdminLinksHook
	//CodeEditorGetPageLanguageHook 
	{

	private $jsonContentModel = 'CONTENT_MODEL_RECON_JSON';

	public function __construct() {
		//
	}

	/**
	 * Set up parser functions
	 * @param mixed $parser
	 * @return void
	 */
	public function onParserFirstCallInit( $parser ) {
		$flags = Parser::SFH_OBJECT_ARGS;
		$parser->setFunctionHook(
			"recon-search",
			function( Parser $parser, PPFrame $frame, array $args ) {
				$pf = new ReconSearch;
				return $pf->run( $parser, $frame, $args );
			},
			$flags
		);
		$parser->setFunctionHook(
			"recon-query-helper",
			function( Parser $parser, PPFrame $frame, array $args ) {
				$pf = new ReconQueryHelper;
				return $pf->run( $parser, $frame, $args );
			},
			$flags
		);
		$parser->setFunctionHook(
			"recon-smwquery-url",
			function( Parser $parser, PPFrame $frame, array $args ) {
				$pf = new ReconSMWQueryUrl;
				return $pf->run( $parser, $frame, $args );
			},
			$flags
		);
	}

	public static function registrationCallback() {
		// Must match the name used in the 'ContentHandlers' section 
		// of extension.json
		define( 'CONTENT_MODEL_RECON_JSON', 'reconjson' );
	}

	public function onContentHandlerDefaultModelFor( $title, &$model ) {
		// Again, must match data in the 'ContentHandlers' section
		if ( $title->getNamespace() === NS_RECON ) {
			$model = CONTENT_MODEL_RECON_JSON;
			return false;
		}
		return true;
	}

	public function onCodeEditorGetPageLanguage( Title $title, &$lang, string $model, string $format ) {
		if ( ExtensionRegistry::getInstance()->isLoaded( 'CodeEditor' ) == false ) {
			return true;
		}
		if ( $title->getNamespace() === NS_RECON ) {
			$lang = 'json';
			return false;
		}
		return true;
	}

	public function onBeforePageDisplay( $out, $skin ): void {
		// Modules and styles no longer added globally
		// See parser functions.
	}

	/**
	 * Add extension to Special:AdminLinks if available
	 * @param ALTree $adminLinksTree
	 */
	public static function onAdminLinks( &$adminLinksTree ) {
		if ( ExtensionRegistry::getInstance()->isLoaded( 'Admin Links' ) == false ) {
			return true;
		}

		// Fetch or create section
		$linkSection = $adminLinksTree->getSection( 'CODECS' );
		if ( is_null( $linkSection ) ) {
			$adminLinksTree->addSection(
				new ALSection( 'CODECS' ),
				wfMessage( 'adminlinks_general' )->text()
			);
			$linkSection = $adminLinksTree->getSection( 'CODECS' );
			$extensionsRow = new ALRow( 'extensions' );
			$linkSection->addRow( $extensionsRow );
		}
		$extensionsRow = $linkSection->getRow( 'extensions' );
		if ( is_null( $extensionsRow ) ) {
			$extensionsRow = new ALRow( 'extensions' );
			$linkSection->addRow( $extensionsRow );
		}

		// Now add extension to section
		global $wgScript;
		$extensionsRow->addItem(
			ALItem::newFromExternalLink(
				str_replace( "/index.php", "", $wgScript )
					. "/index.php/Special:ReconciliationAPI",
				"Reconciliation API"
			)
		);
		return true;
	}

}
