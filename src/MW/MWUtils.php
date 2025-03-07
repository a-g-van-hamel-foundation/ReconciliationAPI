<?php

/**
 * Utility methods for working with categories, namespaces, page ids, etc.
 */

namespace Recon\MW;

use \MediaWiki\MediaWikiServices;
use Title;
use Recon\MW\MWDBUtils;
use Recon\MW\MWCategoryUtils;

class MWUtils {

	/**
	 * Get the canonical name of the namespace from a given namespace index
	 */
	public static function getCanonicalNamespaceName( $index ) {
		return MediaWikiServices::getInstance()->getNamespaceInfo()->getCanonicalName( $index );
	}

	/**
	 * @deprecated
	 */
	public static function getCanonicalName( $index ) {
		return self::getCanonicalNamespaceName( $index );
	}

	/**
	 * For index, get namespace name in content language
	 * @todo maybe use : str_replace( '_', ' ', $name )
	 * @note MWNamespaceUtils contains an updated version.
	 */
	public static function getNamespaceNameFromIndex( $index ) {
		/* SMW method
		$localizer = Localizer::getInstance();
		$language = $localizer->getContentLanguage();
		*/
		$language = MediaWikiServices::getInstance()->getContentLanguage();
		$name = $language->getFormattedNsText( $index );
		return $name;
	}

	/**
	 * For each namespace name in the array, return the index
	 * Supports special handling of "Main" for the main namespace
	 * @note MWNamespaceUtils contains an updated version.
	 * 
	 * @param array $namespaceNames
	 * @return array
	 */
	public static function getNamespaceIndexes( array $namespaceNames ) {
		if( empty($namespaceNames) ) {
			return [];
		}
		$indexes = [];
		$language = MediaWikiServices::getInstance()->getContentLanguage();
		// Language::getNamespaces() / getFormattedNamespaces()
		// getLocalNsIndex( $text ) - only for the current language
		foreach( $namespaceNames as $ns ) {
			if ( strtolower( $ns ) == "main" ) {
				$ns = "";
			}
			$indexes[] = $language->getNsIndex( $ns );
			// getLocalNsIndex( $text ) - only for the current language
			// maybe do additional check on English if site language is not English?
		}
		return $indexes;
	}

	/**
	 * Get page ID from a given (full) pagename
	 * Returns zero (int) if page does not exist.
	 * @param string $fullPageName
	 * @return int
	 */
	public static function getPageIDFromPagename( string $fullPageName ) {
		$title = Title::newFromText( $fullPageName );
		if ( $title == null ) {
			return 0;
		}
		return $title->getArticleID();
	}

	/**
	 * Check, purely on the basis of namespace prefix, if the page name 
	 * resolves to a category page
	 */
	public static function isCategory( $name ) {
		$categoryNames = array_unique( [ "Category", self::getNamespaceNameFromIndex( NS_CATEGORY ) ] );
		foreach( $categoryNames as $cat ) {
			if( str_starts_with( $name, "{$cat}:" ) ) {
				return true;
			}
		}
		return false;
	}

	public static function isConcept( $name ) {
		$conceptNames = array_unique( [ "Concept", self::getNamespaceNameFromIndex( SMW_NS_CONCEPT ) ] );
		foreach( $conceptNames as $concept ) {
			if( str_starts_with( $name, "{$concept}:" ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get new Parser instance. A last resort to be used
	 * only if you do not have access to the Parser.
	 * 
	 * @return \Parser
	 */
	public static function getFreshParser( $ot = 'html' ) {
		$freshParser = MediaWikiServices::getInstance()->getParserFactory()->create();
		$freshParser->setOutputType( $ot );
		$freshParser->setOptions( \ParserOptions::newFromAnon() );
		return $freshParser;
	}

	/**
	 * @param mixed $id
	 */
	public static function getTitleFromPageID( $id ) {
		return Title::newFromID( $id );
	}

	/**
	 * @deprecated Moved to MWCategoryUtils
	 * @param mixed $title
	 * @param mixed $outputFormat
	 */
	public static function getCategoriesFromTitle( $title, $outputFormat = null ) {
		return MWCategoryUtils::getCategoriesFromTitle( $title, $outputFormat = null );
	}

	/**
	 * Get all MediaWiki categories based on match. Supports phrase matching
	 * @deprecated Moved to MWCategoryUtils
	 * 
	 * @param mixed $substring
	 * @param int $offset
	 * @param int $limit
	 * @return array
	 */
	public static function getAllCategories(
		mixed $substring = null,
		mixed $substringpattern = "stringprefix",
		int $offset = 0,
		int $limit = 25
	) {
		return MWCategoryUtils::getAllCategories( $substring, $substringpattern, $offset, $limit );
	}

	/**
	 * @deprecated Moved to MWCategoryUtils
	 * @param mixed $topCategoryNames
	 * @param mixed $categoryDepth
	 * @return array
	 */
	public static function getSubCategories(
		string $topCategoryNames,
		int $categoryDepth = 5,
		mixed $substring = null,
		string $substringPattern = "stringprefix",
		int $offset = 0,
		int $limit = 25
	): array|bool {
		return MWCategoryUtils::getSubCategories( $topCategoryNames, $categoryDepth, $substring, $substringPattern, $offset, $limit );
	}

	/**
	 * @deprecated Moved to MWCategoryUtils
	 */
	public static function formatCategoryResultRow( $row, array &$subCats, array &$nextLevelCategories, array &$pageIds ) {
		return MWCategoryUtils::formatCategoryResultRow( $row, $subCats, $nextLevelCategories, $pageIds );
	}

}
