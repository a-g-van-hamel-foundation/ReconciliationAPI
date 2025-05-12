<?php

/**
 * General and miscellaneous utility methods
 */

namespace Recon;

use Title;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use HtmlArmor;
use Recon\MW\MWUtils;
use MediaWiki\Logger\LoggerFactory;

class ReconUtils {
	private const LOGNAME = 'ReconciliationAPI';
	
	/**
	 * Get URL base, without trailing slash,
	 * handling both short and long (@todo?) URLs.
	 * @todo maybe optionally include `/index.php?title=` 
	 * (if wiki pages are intended)
	 */
	public static function getURLBase() {
		$server = MediaWikiServices::getInstance()->getUrlUtils()->getCanonicalServer();
		$scriptPath = MediaWikiServices::getInstance()->getMainConfig()->get( 'ScriptPath' );
		return $server . $scriptPath;
	}

	public static function fetchExtensionJson() {		
		$extPath = self::getExtensionPath();
		$jsonSource = $extPath . "/extension.json";

		if ( file_exists( $jsonSource ) ) {
			$jsonContents = file_get_contents( $jsonSource );
			$jsonStr = json_decode( $jsonContents, true );
			if ( $jsonStr !== false ) {
				return $jsonStr;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	/**
	 * Get URL path for public extension folder
	 * @return string
	 */
	public static function getExtensionFolder() {
		$baseUrl = self::getURLBase();
		$mainConfig = MediaWikiServices::getInstance()->getMainConfig();
		$extAssets = $mainConfig->get( 'ExtensionAssetsPath' );
		return $baseUrl . $extAssets . "/ReconciliationAPI";
	}

	/**
	 * Get file path for public extension folder
	 * @return string
	 */
	public static function getExtensionPath() {
		global $IP;
		$mainConfig = MediaWikiServices::getInstance()->getMainConfig();
		$extAssets = $mainConfig->get( 'ExtensionAssetsPath' );
		return $IP . $extAssets . "/ReconciliationAPI";
	}

	/**
	 * Get a page's displaytitle or else default to its pagename.
	 * @param Title $titleObj
	 * @param mixed $pageId
	 * @param mixed $default
	 * @param bool $includeNamespacePrefix - if defaulting to pagename, whether to include the namespace prefix
	 * @return mixed
	 */
	public static function getDisplayTitleElsePageName(
		Title $titleObj,
		mixed $pageId,
		mixed $default = false,
		bool $includeNamespacePrefix = true
	) {
		$displayTitle = self::getDisplayTitle( $titleObj, $pageId, false );
		if ( $displayTitle == false ) {
			return $includeNamespacePrefix ? $titleObj->getPrefixedText() : $titleObj->getText();
		}
		return $displayTitle;
	}

	/**
	 * Get the display title from Title object
	 * move to MWDisplayTitle ? - MWMappingUtils maybe
	 * @return string
	 */
	public static function getDisplayTitle( Title $titleObj, mixed $pageId, mixed $default = false ) {
		// $titleObj = $titleObj->createFragmentTarget( '' );
		if ( $pageId == null ) {
			$pageId = $titleObj->getArticleID();
		}
		$pageProps = MediaWikiServices::getInstance()->getPageProps();
		$displaytitles = $pageProps->getProperties( [ $titleObj ], [ "displaytitle" ] );

		if ( array_key_exists( $pageId, $displaytitles ) ) {
			$displaytitle = $displaytitles[$pageId]['displaytitle'];
		} else {
			$displaytitle = $default;
		}
		$html = HtmlArmor::getHtml( $displaytitle );
		return $html;
	}

	/**
	 * @deprecated moved
	 */
	public static function getNamespaceNameFromIndex( $index ) {
		return MWUtils::getNamespaceNameFromIndex( $index );
	}

	/**
	 * Originally in PFUtils
	 * @deprecated @moved
	 */
	public static function getCanonicalName( $index ) {
		return MWUtils::getCanonicalNamespaceName( $index );
	}

	/**
	 * Primitive method to remove namespace prefix from fullpagename.
	 * Not suitable for all use cases
	 * @todo move to MWNamespaceUtils or MWUtils
	 * @param array $names
	 * @return array
	 */
	public static function removeNamespacePrefixFromNames( array $names ): array {
		$newNames = [];
		foreach( $names as $name ) {
			$nameComponents = explode( ":", $name );
			if ( count( $nameComponents ) > 1 ) {
				// preserves the colon if it used within string
				$prefix = array_shift( $nameComponents );
				$newNames[] = implode( ":", $nameComponents );
			} else {
				$newNames[] = $name;
			}
		}
		return $newNames;
	}

	/**
	 * Return a phrase with a substring highlighted in bold (strong)
	 * Case-insensitive though not diacritic-insensitive.
	 * @deprecated move to StringModifier
	 * @param string $fullString
	 * @return string
	 */
	public static function createHighlightedString( string $fullString, string $subString ) {
		$pattern = "/" . preg_quote( strtolower( $subString ), "/" ) . "/i";
		preg_match( $pattern, $fullString, $matches );
		$highlightedPhrase = ( $matches ) ? $matches[0] : $fullString;
		$res = str_replace( $highlightedPhrase, "<strong>$highlightedPhrase</strong>", $fullString );
		return $res;
	}

	/**
	 * Converts ('flattens') string to a lowercase string 
	 * stripped of diacritics and tags
	 * @deprecated moved to StringModifier
	 * @param string $str
	 * @return string
	 */
	public static function flattenString( string $str ): string {
		$str = html_entity_decode( $str );
		$str = strip_tags( $str );
		// remove punctuation
		$str = preg_replace("/[^a-zA-Z0-9]+/", " ", $str );
		// remove some diacritics
		$str = iconv( 'UTF-8', 'US-ASCII//TRANSLIT//IGNORE', $str );
		$str = strtolower( $str );
		return trim( $str );
	}

	/**
	 * Strip tags and do other semi-cleanup actions on the string.
	 * @deprecated moved to StringModifier
	 * @param string $str
	 * @return string
	 */
	public static function stripTags( string $str ) {
		$str = html_entity_decode( $str );
		$str = str_replace( [ "'''", "''" ], "", $str );
		$str = strip_tags( $str );
		return trim( $str );
	}

	/**
	 * Helper function to format results in the format
	 * required by Page Forms' 'values from url' with pfautocomplete
	 * @return array
	 */
	public static function formatResultForPageForms( array $qRes ): array {
		$newRes = [];
		foreach( $qRes["result"] as $res ) {
			$newRes[] = [
				"title" => $res["id"] ?? "",
				"displaytitle" => $res["name"] ?? "",
				"description" => $res["description"] ?? ""
			];
		}
		return [ 
			"pfautocomplete" => $newRes
		];
	}

	/**
	 * Get image URL given a certain width
	 * Returns false if no image was found
	 * @param mixed $fileName
	 * @param mixed $width
	 * @return mixed
	 */
	public static function getImageThumbnailUrl( $fileName, $width = "50", $includeDomain = false ) {
		$width = intval($width);
		// @todo language localisation
		// NS_FILE -> get namespace name in language
		$fileNamespaceName = "File";
		$fileName = ( substr( $fileName, 0, 5 ) == "$fileNamespaceName:" ) 
			? substr( $fileName, strlen( "$fileNamespaceName:" )) 
			: $fileName;
		$title = Title::newFromText( $fileName, NS_FILE );
		if ( !$title ) {
			return false;
		}
		return self::getImageThumbnailUrlFromTitle( $title, $width );
	}

	public static function getImageThumbnailUrlFromTitle( $title, $width ) {
		$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $title );
		if ( $file ) {
			$thumbPath = $file->createThumb( $width );
			$height = $file->getDisplayWidthHeight( $width, 200 );
			$mimeAnalyzer = MediaWikiServices::getInstance()->getMimeAnalyzer();
			$mimeType = $mimeAnalyzer->guessMimeType( substr( $thumbPath, 1 ), false );
			//$includeDomain ? self::getURLBase() . $thumb : $thumb;
			$thumb = [
				"url" => $thumbPath,
				"width" => $width,
				"height" => $height[1],
				"duration" => null,
				"mimetype" => $mimeType
			];
			return $thumb;
		}
		return false;
	}

	/**
	 * Get appropriate site logo (small size)
	 * without base URL
	 * @return mixed
	 */
	public static function getSiteLogo() {
		$wgLogos = MediaWikiServices::getInstance()->getMainConfig()->get( MainConfigNames::Logos );
		return $wgLogos["1x"] ?? false;
	}

	/**
	 * @depreciated
	 * @param string $msg
	 * @param array $data
	 * @return void
	 */
	public static function log( string $msg, array $data = [] ) {
		// $logger = LoggerFactory::getInstance( self::LOGNAME );
		// $logger->info( $msg, $data );
	}

	/**
	 * Sort array values by length (asc)
	 * (and preserve keys)
	 *
	 * @param array $values
	 * @return array $values
	 */
	public static function sortValuesByLength( $values ) {
		if ( empty( $values ) ) {
			return $values;
		}
		uasort( $values, static function ( $a, $b ) {
			return strlen( $a ) - strlen( $b );
		} );
		return $values;
	}

}
