<?php

/**
 * Helper class for the Full-Text Search implementation of SMW.
 * 
 * A challenge of FTS is that it only works conditionally:
 * (a) select data types ($smwgFulltextSearchIndexableDataTypes)
 * (b) possible exemptions ($smwgFulltextSearchPropertyExemptionList)
 * (c) minimum token size ($smwgFulltextSearchMinTokenSize)
 * (d) MATCH/AGAINST only if tilde (~) operator is used.
 * 
 * If all of the above checks out, use boolean operator +
 * 
 * SMW may throw RuntimeException errors
 * https://github.com/SemanticMediaWiki/SemanticMediaWiki/issues/6129
 * 
 * @note: there is no check yet to work out if the property used
 * is of the appropriate data type or is exempted.
 * Users should use 'like:' instead of '~'
 */

namespace Recon\SMW;

use Recon\StringModification\StringModifier;
use Onoi\Tesa\SanitizerFactory;
use SMW\SQLStore\QueryEngine\Fulltext\TextSanitizer;

class SMWQueryHelperForFTS {

	private $smwgFulltextSearchMinTokenSize;
	private $smwgFulltextSearchIndexableDataTypes;
	private $enabledForTypeText = true;
	private $enabledForTypeUrl = true;
	private $enabledForTypePage = false;
	private $smwgFulltextSearchPropertyExemptionList;

	public function __construct() {
		global $smwgFulltextSearchMinTokenSize;
		$this->smwgFulltextSearchMinTokenSize = intval( $smwgFulltextSearchMinTokenSize );
		global $smwgFulltextSearchIndexableDataTypes;
		$this->smwgFulltextSearchIndexableDataTypes = $smwgFulltextSearchIndexableDataTypes;
		global $smwgFulltextSearchPropertyExemptionList;		
		$this->smwgFulltextSearchPropertyExemptionList = $smwgFulltextSearchPropertyExemptionList;
		$this->enabledForTypeText = ( $smwgFulltextSearchIndexableDataTypes & SMW_FT_BLOB ) ? true : false;
		$this->enabledForTypeUrl = ( $smwgFulltextSearchIndexableDataTypes & SMW_FT_URI ) ? true : false;
		$this->enabledForTypePage = ( $smwgFulltextSearchIndexableDataTypes & SMW_FT_WIKIPAGE ) ? true : false;
	}

	/**
	 * Dedicated handler for Full-Text Search (FTS)
	 * Tokens, shorter strings, double quotes, boolean operators, etc.
	 * SMW comes with its own tokenizer
	 * 
	 * @return string
	 */
	public function getReplacementStringForFTS(
		string $substring,
		bool $isSinglePageRestriction = false
	) {
		// Replace foll. characters with special meaning in FTS
		$substring = str_replace( [ "+", "-", "*" ], " ", $substring);

		// Important! Non-tokens can throw RuntimeExceptions
		// e.g. with MATCH (*) IN BOOLEAN MODE
		// Return empty string
		$sanitizerFactory = new SanitizerFactory();
		$textSanitizer = new TextSanitizer( $sanitizerFactory );

		// Separate all phrases between double quotes ("...") from the substring
		$pattern = '`"([^"]*)"`';
		$phrasesInQuotes = [];
		$substring = preg_replace_callback(
			$pattern,
			function ($match) use (&$phrasesInQuotes, $isSinglePageRestriction) {
				// Guard against RuntimeException with short strings
				if( $isSinglePageRestriction == true ) {
					// Single page restriction does not support boolean operators
					// And from experience, string length does not matter (@todo unconfirmed)
					$phrasesInQuotes[] = $match[0];
				} elseif( iconv_strlen( $match[0] ) >= $this->smwgFulltextSearchMinTokenSize + 2 ) {
					$phrasesInQuotes[] = "+" . $match[0];
				} else {
					// Avoid RuntimeException
					$phrasesInQuotes[] = $match[0];
				}
				return "";
			},
			$substring
		);

		// Apply syntax to tokens
		$tokens = explode( " ", trim( $substring ) );

		$phraseCount = count( $phrasesInQuotes ) + count( $tokens );
		$newTokens = [];
		// @todo should we look at entire substring length, too?
		// e.g. [[~Ab*]] - might work better if switching back to like:
		foreach( $tokens as $token) {
			// @todo support apostrophes like O'B, O'Br => internal error
			// beware of multi-byte characters
			//$countableToken = StringModifier::flattenString( trim( $token ) );
			$countableToken = $textSanitizer->sanitize( $token, false );
			//$countableToken = trim( $token );
			if ( $isSinglePageRestriction ) {
				// e.g. [[~Aa* Ba*]] may be fine but tokenisation is limited.
				// @todo consider "*{$token}*", which IS possible in this case
				$newTokens[] = "{$token}*";
			} elseif( iconv_strlen( $countableToken ) >= $this->smwgFulltextSearchMinTokenSize ) {
				// Add + boolean operator to each individual token
				// to create an AND relationship (unsafe for non-tokens)
				if ( $token !== "..." ) {
					$newTokens[] = "+{$token}*";
				}
			} elseif( $countableToken !== "" && $token !== "..." ) {
				// No boolean '+' for shorter strings
				// and append asterisk only if a single token is used
				// or else we'll get a fatal error RunTimeException
				$newTokens[] = ( $phraseCount === 1 ) ? "{$token}*" : $token;
			} else {				
				$newTokens[] = $token;
			}
		}

		// Finally return merged
		$merged = array_merge( $phrasesInQuotes, $newTokens );
		return implode( " ", $merged );
	}

}
