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

use MediaWiki\Config\GlobalVarConfig;
use SMW\SQLStore\QueryEngine\FulltextSearchTableFactory;
#use Recon\StringModification\StringModifier;

class SMWQueryHelperForFTS {

	private $textSanitizer;
	private $smwConfig = null;
	private $smwgFulltextSearchMinTokenSize;
	private $smwgFulltextSearchIndexableDataTypes;
	private bool $enabledForTypeText = true;
	private bool $enabledForTypeUrl = true;
	private bool $enabledForTypePage = false;
	private $smwgFulltextSearchPropertyExemptionList;

	public function __construct(
		$textSanitizer = null,
		$smwConfig = null
	) {
		// Classes and configs
		if ( $textSanitizer == null ) {
			$fulltextSearchTableFactory = new FulltextSearchTableFactory();
			$this->textSanitizer = $fulltextSearchTableFactory->newTextSanitizer();
		} else {
			$this->textSanitizer = $textSanitizer;
		}
		$this->smwConfig = $smwConfig ?? new GlobalVarConfig( "smwg" );

		// Config variables
		$this->smwgFulltextSearchMinTokenSize = intval( $this->smwConfig->get( "FulltextSearchMinTokenSize" ) );
		$this->smwgFulltextSearchIndexableDataTypes = $this->smwConfig->get( "FulltextSearchIndexableDataTypes" );
		$this->smwgFulltextSearchPropertyExemptionList = $this->smwConfig->get( "FulltextSearchPropertyExemptionList" );

		$this->enabledForTypeText = ( $this->smwgFulltextSearchIndexableDataTypes & SMW_FT_BLOB ) ? true : false;
		$this->enabledForTypeUrl = ( $this->smwgFulltextSearchIndexableDataTypes & SMW_FT_URI ) ? true : false;
		$this->enabledForTypePage = ( $this->smwgFulltextSearchIndexableDataTypes & SMW_FT_WIKIPAGE ) ? true : false;
	}

	/**
	 * Dedicated handler for Full-Text Search (FTS)
	 * Tokens, shorter strings, double quotes, boolean operators, etc.
	 * SMW comes with its own tokenizer
	 * 
	 * @param string|null substrPattern
	 * 
	 * @return string
	 */
	public function getReplacementStringForFTS(
		string $substring,
		bool $isSinglePageRestriction = false,
		mixed $substrPattern = "tokenprefix"
	): string {
		// Replace foll. characters with special meaning in
		// FTS (+, -, *) and others that can cause a
		// RuntimeException ( :, ' ) with a space
		$substring = str_replace( [ "+", "-", "*", ":", "'" ], " ", $substring );

		// Important! Non-tokens can throw RuntimeExceptions
		// e.g. with MATCH (*) IN BOOLEAN MODE
		// Return empty string

		// Prepare string for division into units, i.e. 
		// phrases in quotes and tokens

		// Phrases between double quotes ("...") from the substring
		// get special treatment and should be separated first.
		$pattern = '`"([^"]*)"`';
		$phrasesInQuotes = [];
		$substring = preg_replace_callback(
			$pattern,
			function ($match) use (&$phrasesInQuotes, $isSinglePageRestriction) {
				// Guard against RuntimeException with short strings
				if( $isSinglePageRestriction === true ) {
					// Single page restriction does not support boolean operators
					// And from experience, string length does not matter (@todo unconfirmed)
					$phrasesInQuotes[] = $match[0];
				} elseif( iconv_strlen( $match[0] ) >= $this->smwgFulltextSearchMinTokenSize + 2 ) {
					$phrasesInQuotes[] = "+" . $match[0];
				} else {
					// Guard against RuntimeException
					$phrasesInQuotes[] = $match[0];
				}
				return "";
			},
			$substring
		);

		// Apply syntax to tokens
		$newTokens = [];
		$tokens = explode( " ", trim( $substring ) );
		$phraseCount = count( $phrasesInQuotes ) + count( $tokens );

		// @todo should we look at entire substring length, too?
		// e.g. [[~Ab*]] - might work better if switching back to 'like:'
		foreach( $tokens as $token) {
			// Get string for countable bytes
			// @todo support apostrophes like O'B, O'Br => internal error
			// beware of multi-byte characters
			//$countableToken = StringModifier::flattenString( trim( $token ) );
			$countableToken = $this->textSanitizer->sanitize( $token, false );

			if ( $isSinglePageRestriction ) {
				// e.g. [[~Aa* Ba*]] may be fine but tokenisation is limited.
				// stringprefix: ...*; tokenprefix: fall back to stringprefix; allchars: *...*
				$newTokens[] = $substrPattern === "allchars" ? "*{$token}*" : "{$token}*";
			} elseif( iconv_strlen($countableToken) >= $this->smwgFulltextSearchMinTokenSize ) {
				// Add + boolean operator to each individual token
				// to create an AND relationship
				// Unsafe for non-tokens
				if ( $token !== "..." ) {
					$newTokens[] = "+{$token}*";
				}
			} elseif( $countableToken !== "" && $token !== "..." ) {
				// No boolean '+' for shorter strings
				// and append asterisk only if a single token is used
				// or else we'll get a fatal error RunTimeException
				$newTokens[] = $phraseCount === 1 ? "{$token}*" : $token;
			} else {
				if( count($tokens) == 1 && count( $phrasesInQuotes ) == 0 ) {
					// Standard SQLStore asterisk. Safe to add
					// (1) if we have a single unit
					// (2) if phrase quotes are unused
					$newTokens[] = "{$token}*";
				} else {
					$newTokens[] = "{$token}";
				}
			}
		}

		// Finally return merged
		$merged = array_merge( $phrasesInQuotes, $newTokens );
		return implode( " ", $merged );
	}

}
