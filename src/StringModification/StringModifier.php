<?php

/**
 * Filters and other modifiers
 * 
 */

namespace Recon\StringModification;

class StringModifier {

	/**
	 * Converts ('flattens') string to a lowercase string 
	 * stripped of diacritics and tags
	 * @param string $str
	 * @return string
	 */
	public static function flattenString( string $str ): string {
		$str = strtolower( $str );
		// remove some diacritics
		$str = html_entity_decode( $str );
		if ( method_exists( "Transliterator", "createFromRules" ) ) {
			$transliterator = \Transliterator::createFromRules(
				""
					. ":: Latin-ASCII;"
					//  . " [:White_Space:]+ > ' ';"
					. ":: NFD;"
					. ":: [:Nonspacing Mark:] Remove;"
					. ":: NFC;"
					,
				\Transliterator::FORWARD
			);
			$str = $transliterator->transliterate( $str );
		} else {
			// Fall back to method that does not work for Greek, Coptic, etc.
			$str = iconv( 'UTF-8', 'US-ASCII//TRANSLIT//IGNORE', $str );
		}
		// Strip HTML tags
		$str = strip_tags( $str );
		// remove punctuation
		//$str = preg_replace("/[^a-zA-Z0-9]+/", " ", $str );
		$str = preg_replace("#[[:punct:]]#", "", $str );	
		return trim( $str );
	}

	/**
	 * Strip tags and do other semi-cleanup actions on the string.
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
	 * Return a phrase with a substring highlighted in bold (strong)
	 * Case-insensitive though not diacritic-insensitive.
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
	 * Remove namespace prefix (if any) from label
	 * @todo consolidate with similar method in ReconUtils
	 * @param string $label
	 * @return string
	 */
	private static function removeNSPrefixFromLabel( string $label ) {
		$labelArr = explode( ':',  trim( $label ) );
		if ( count( $labelArr ) > 1 ) {
			$prefix = array_shift( $labelArr );
			$res = implode( ':', $labelArr);
		} else {
			$res = $label;
		}
		return $res;
	}

}
