<?php

/**
 * @todo Sort out overlap with MWUtils regarding namespaces.
 */

namespace Recon\Localisation;

use \MediaWiki\MediaWikiServices;

class ReconLocalisation {

	/**
	 * Get namespace name in given language or content language
	 * @param mixed $ns = index
	 * @param mixed $languageCode
	 * @return mixed
	 */
	public static function getNamespaceName( $ns, $languageCode = null ) {
		if ( $languageCode !== null ) {
			$language = MediaWikiServices::getInstance()->getLanguageFactory()->getLanguage( $languageCode );
			if ( $language == null ) {
				return self::getNamespaceName( $ns, null );
			}
		} else {
			$language = self::getSiteLanguage();
		}
		$localNamespaceName = $language->getFormattedNsText( $ns );
		return $localNamespaceName;
	}

	/**
	 * Get the canonical namespace name
	 * @param mixed $ns
	 * @param mixed $language @todo
	 * @return mixed
	 */
	public static function getCanonicalNamespaceName( $ns, $language = null ) {
		$namespaceInfo = MediaWikiServices::getInstance()->getNamespaceInfo(); 
		return $namespaceInfo->getCanonicalName( $ns );
	}

	public static function getSiteLanguage() {
		return $contentLanguage = MediaWikiServices::getInstance()->getContentLanguage();
	}

}
