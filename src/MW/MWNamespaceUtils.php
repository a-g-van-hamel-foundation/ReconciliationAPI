<?php

/**
 * Utility functions for working with namespaces.
 */

namespace Recon\MW;

use Title;
use MediaWiki\MediaWikiServices;
use MediaWiki\MainConfigNames;

class MWNamespaceUtils {

	private $mainConfig;
	private $languageCode;
	private $contentLanguage;
	private $englishLanguage;

	public function __construct() {
		$instance = MediaWikiServices::getInstance();
		$this->mainConfig = $instance->getMainConfig();
		$this->languageCode = $this->mainConfig->get( MainConfigNames::LanguageCode );		
		$this->contentLanguage = $instance->getContentLanguage();
		$this->englishLanguage = $instance->getLanguageFactory()->getLanguage( "en" );
	}

	/**
	 * Helper method for getAllPagesForNamespace
	 * Takes a comma-separated string e.g. "Main,Project,Help"
	 * For the main namespace, use 'Main'
	 * For all content namespaces, use '_contentNamespace'
	 * Checks content language as well as English
	 * 
	 * Adapted from Page Forms.
	 * 
	 * @param string $namespaceStr - e.g. "Main,Project,MyCustomNS,_contentNamespaces"
	 * @return array arrays namespace names and their codes
	 */
	public function getNamespaceInfoForQuery( mixed $namespaceStr ) {
		$namespaceNames = explode( ",", $namespaceStr );
		$namespaceIndexes = [];

		// As in Page Forms, "_contentNamespaces" has special meaning
		if( in_array( "_contentNamespaces", $namespaceNames ) ) {
			unset( $namespaceNames["_contentNamespaces"] );
			[ $contentNamespaceNames, $contentNamespaceIndexes ] = $this->getContentNamespaceInfoForQuery();
			// Don't merge just yet
		}

		// For each namespace, get index
		$allNamespaces = $this->contentLanguage->getNamespaces();
		if ( $this->languageCode !== "en" ) {
			$allEnglishNamespaces = $this->englishLanguage->getNamespaces();
		}
		foreach ( $namespaceNames as $namespaceName ) {
			// Cycle through all the namespace names for this language, and
			// if one matches the namespace specified in the form, get the
			// names of all the pages in that namespace.
			$namespaceName = self::standardizeNamespace( $namespaceName );
			$matchingNamespaceCode = null;
			foreach ( $allNamespaces as $curNSCode => $curNSName ) {
				if ( $curNSName == $namespaceName ) {
					$matchingNamespaceCode = $curNSCode;
				}
			}
			// If that didn't find anything, and we're in a content 
			// language other than English, check English as well.
			if ( $matchingNamespaceCode === null && $this->languageCode !== "en" ) {
				foreach ( $allEnglishNamespaces as $curNSCode => $curNSName ) {
					if ( $curNSName == $namespaceName ) {
						$matchingNamespaceCode = $curNSCode;
					}
				}
			}
			if ( $matchingNamespaceCode === null ) {
				// Silently ignore
				continue;
			}
			$namespaceIndexes[] = $matchingNamespaceCode;
		}

		// Now merge names, indexes if necessary
		if ( isset( $contentNamespaceNames ) ) {
			$namespaceNames = array_merge( $namespaceNames, $contentNamespaceNames );
			array_unique( $namespaceNames );
		}
		if ( isset( $contentNamespaceIndexes ) ) {
			$namespaceIndexes = array_merge( $namespaceIndexes, $contentNamespaceIndexes );
			array_unique( $namespaceIndexes );
		}

		return [ $namespaceNames, $namespaceIndexes ];
	}

	/**
	 * Get content namespaces
	 * @return array
	 */
	public function getContentNamespaceInfoForQuery() {
		$namespaceIndexes = $this->mainConfig->get( MainConfigNames::ContentNamespaces );
		$namespaceNames = [];
		foreach( $namespaceIndexes as $index ) {			
			$name = $this->getNamespaceNameFromIndex( $index, $this->englishLanguage );
			if ( $name !== null ) {
				$namespaceNames[] = $name;
			}
		}
		return [ $namespaceNames, $namespaceIndexes ];
	}

	/**
	 * Get the exact canonical namespace string, given a user-created string
	 * 
	 * @param string $namespaceStr
	 * @return string
	 */
	public static function standardizeNamespace( $namespaceStr ) {
		// Switch to blank for the string 'Main'.
		if ( strtolower( $namespaceStr ) == "main" ) {
			return "";
		}
		// @todo Feels like a hack
		$dummyTitle = Title::newFromText( "$namespaceStr:ABC" );
		return $dummyTitle ? $dummyTitle->getNsText() : $namespaceStr;
	}

	/**
	 * Get the name of the namespace for a given namespace index
	 * If no language is provided, default to site's content language.
	 * 
	 * @param mixed $index
	 * @param mixed $language
	 */
	public function getNamespaceNameFromIndex( $index, $language = null ) {
		if ( $language == null ) {
			$contentLanguage = MediaWikiServices::getInstance()->getContentLanguage();
			return $contentLanguage->getFormattedNsText( $index );
		}
		return $language->getFormattedNsText( $index );
	}

}
