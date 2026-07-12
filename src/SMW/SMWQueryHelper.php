<?php

/**
 * Utility class for SMW's query syntax.
 * More specialised classes are to be found elsewhere.
 * To be called with ReconServices::getInstance()->getSMWQueryHelper()
 */

namespace Recon\SMW;

use MediaWiki\MediaWikiServices;
use Recon\SMW\SMWQueryHelperForFTS;
use Recon\StringModification\StringModifier;

class SMWQueryHelper {

	private $smwqueryPlaceholder = "@@@";
	// extension config vars
	private $smwgEnabledFulltextSearch;
	private $smwgFulltextSearchMinTokenSize;
	private $wgReconAPIQueryTrigger;

	public function __construct(
		$mainConfig = null,
		$smwConfig = null
	) {
		// main config
		if ( $mainConfig == null ) {
			$mainConfig = MediaWikiServices::getInstance()->getMainConfig();
		}
		$this->wgReconAPIQueryTrigger = $mainConfig->get( "ReconAPIQueryTrigger" );
		// smw config
		if ( $smwConfig !== null ) {
			$this->smwgFulltextSearchMinTokenSize = $smwConfig->get( "FulltextSearchMinTokenSize" );
			$this->smwgEnabledFulltextSearch =  $smwConfig->get( "EnabledFulltextSearch" );
		} else {
			global $smwgFulltextSearchMinTokenSize, $smwgEnabledFulltextSearch;
			$this->smwgFulltextSearchMinTokenSize = $smwgFulltextSearchMinTokenSize;
			$this->smwgEnabledFulltextSearch = $smwgEnabledFulltextSearch;
		}
	}

	/**
	 * Prepare substring for query if it must be modified or sanitised
	 * Caters for different meaning when fulltext search is enabled.
	 * Changes: Added asterisk to tokens
	 * Uses ~ not LIKE
	 * @todo There is some overlap between this and
	 * constructQueryFromConfigProfile()
	 * @return string
	 */
	public function prepareSubstring( $substring ): mixed {
		if ( strlen($substring) == 0 ) {
			// No checks necessary
			return "";
		}

		if ( $this->smwgEnabledFulltextSearch ) {
			// check if the substring occurs between double quotes
			if( $substring[0] == "\"" && $substring[strlen($substring) - 1] == "\"" ) {
				return $substring;
			}
			// Add boolean operator to each token of the right length
			// @todo except single page restriction
			$substringArr = explode( " ", $substring );
			$newSubstringArr = [];
			foreach( $substringArr as $token ) {
				if ( strlen( $token ) >= $this->smwgFulltextSearchMinTokenSize ) {
					$newSubstringArr[] = "+{$token}*";
				} else {
					// Important! Guards against Runtime Exception.
					$newSubstringArr[] = $token;
				}
			}
			$substring = implode( " ", $newSubstringArr );
		} else {
			// @todo !
			// at least append an asterisk*
		}
		return $substring;
	}

	/**
	 * Construct a query statement from config data
	 * @todo sort out overlap with prepareSubstring
	 * $substring and $substringPattern added to new version
	 * 
	 * @return array|string
	 */
	public function constructQueryFromConfigProfile(
		mixed $query,
		string $substring,
		string $substringPattern
	) {
		$rawQuery = "";
		if ( gettype( $query ) == "string" && $query !== "" ) {
			// @todo wildcards? - it depends!
			// @todo can we use this for 6.4 ?
			$usesLikeSyntax = $this->usesLikeSyntax( $query );			
			$replacement = $this->getReplacementString(
				$substring,
				$substringPattern,
				$usesLikeSyntax,
				$this->isSinglePageRestriction( $query, $usesLikeSyntax )
			);
			$rawQuery = str_replace( $this->smwqueryPlaceholder, $replacement, $query );
		} elseif( gettype( $query ) == "array" ) {
			// The preferred approach
			//$subStrings = explode( " ", $this->substring );
			$newStatements = [];
			foreach( $query as $statement ) {
				$base = $statement["from"] ?? "";
				$condition = $statement["where"] ?? "";

				// Use '6.4' instead! A departure because we are
				// replacing the 'where' condition wholesale
				// (no LIKE/MATCH)
				if ( $substringPattern === "exactpagename" ) {
					$newStatements[] = "$base [[{$substring}]]";
					continue;
				}

				// @todo Remove substringpattern once camelCase is used consistently
				$substringPattern = $statement["substringPattern"] ?? $statement["substringpattern"] ?? $substringPattern;
				$conditionsReplaced = [];
				//$substring = $this->substring;
				if ( isset( $statement["preprocessSubstring"] ) ) {
					foreach ( $statement["preprocessSubstring"] as $v ) {
						switch( $v ) {
							case "flatten":
								$substring = StringModifier::flattenString( $substring );
								break;
						}
					}
				}

				// @todo getReplacementString should handle multiple subStrings
				$usesLikeSyntax = $this->usesLikeSyntax( $condition );
				$isSinglePageRestriction = $this->isSinglePageRestriction( $condition, $usesLikeSyntax );
				$replacement = $this->getReplacementString(
					$substring,
					$substringPattern,
					$this->usesLikeSyntax( $condition ),
					$isSinglePageRestriction
				);
				$conditionsReplaced[] = str_replace( $this->smwqueryPlaceholder, $replacement, $condition );
				/* No, let getReplacementString split the string
				foreach( $subStrings as $subString ) {
					// @todo wildcard, for now:
					$replacement = $this->getReplacementString(
						$subString,
						$this->substringPattern,
						$this->usesLikeSyntax( $condition )
					);
					$conditionsReplaced[] = str_replace( $this->smwqueryPlaceholder, $replacement, $condition );
				}
				*/
				$newStatements[] = ( $substring === "" && $this->wgReconAPIQueryTrigger === "always" )
					? ( $base !== "" ? $base : "[[Creation date::+]]" )
					: $base . " " . implode( " ", $conditionsReplaced );
			}
			$rawQuery = implode( " OR ", $newStatements );
		}
		return $rawQuery;
	}

	/**
	 * Create replacement string consisting of substring and additional
	 * syntax sugar.
	 * 
	 * @todo Elasticsearch
	 * 
	 * @param mixed $substring
	 * @return string
	 */
	public function getReplacementString(
		string $substring,
		string $substringPattern = "prefix",
		bool $usesLikeSyntax = false,
		bool $isSinglePageRestriction = false
	) {
		$substring = trim( $substring );
		if ( strlen($substring) == 0 ) {
			// No checks necessary.
			return "+";
		}

		$useFTS = ( $this->smwgEnabledFulltextSearch && !$usesLikeSyntax ) ? true : false;
		if ( $useFTS ) {
			// FTS with tilde prefix
			if ( !$isSinglePageRestriction ) {
				// Enforce "tokenprefix"
				// Does not support alternative substring patterns.
				$substringPattern = "tokenprefix";
			}
			//$isNonToken = ( strlen( $substring ) < $this->smwgFulltextSearchMinTokenSize ) ? true : false;
			$smwQueryHelperForFTS = new SMWQueryHelperForFTS();
			$replacement = $smwQueryHelperForFTS->getReplacementStringForFTS( $substring, $isSinglePageRestriction, $substringPattern );
		} else {
			// regular SQL
			// $subStrings = explode( " ", $substring );
			switch( $substringPattern ) {
				case "prefix":
				case "stringprefix":
				case "tokenprefix":
					// @todo shouldn't we use prepareSubstring?
					// [[prop::~Hello worl*]] (without FTS) or [[prop::like:Hello worl*]] (with FTS)						
					$replacement = "{$substring}*";
					break;
				case "allchars":
					// LIKE only
					$replacement = "*{$substring}*";
					break;
				case "suffix":
					// currently unused
					$replacement = "*{$substring}";
					break;
				case "exact":
				case "exactpagename":
					$replacement = $substring;
					break;
				default:
					$replacement = "{$substring}*";
			}
		}
		return $replacement;
	}

	/**
	 * Helper function to check if a tilde (`~`) or `like:` was used
	 * in front of the placeholder.
	 * @todo only necessary for FTS, so maybe move to SMWQueryHelperForFTS
	 */
	public function usesLikeSyntax( $str ) {
		// Find position
		$placeholderPos = strpos( $str, $this->smwqueryPlaceholder );
		// False if no placeholder was found
		if ( $placeholderPos == false ) {
			return false;
		}
		// like:, LIKE:, etc.
		if ( strtolower( substr( $str, $placeholderPos - 5, 5 ) ) == "like:" ) {
			return true;
		}
		// todo - maybe check a space was inserted?
		return false;
	}

	/**
	 * Checks if single page restriction is used rather than a property.
	 * e.g. [[~hello*]] not [[Has text::~hello*]]
	 * @todo Not waterproof: [[ ~hello*]], [[Has text :: ~hello*]]
	 * @link https://www.semantic-mediawiki.org/wiki/Help:Single_page_restriction
	 * @param mixed $str
	 * @return
	 */
	public function isSinglePageRestriction( string $substring, bool $usesLikeSyntax ): bool {
		$operator = $usesLikeSyntax ? "like:" : "~";
		// preprocess/sanitise eg. ':: ~', '[[ ~'
		$substring = str_replace(
			[ ":: $operator", " ::$operator", "[[ " ],
			[ "::$operator", "::$operator", "[[" ],
			trim( $substring )
		);
		$placeholderPos = strpos( $substring, $this->smwqueryPlaceholder );
		if ( $usesLikeSyntax ) {
			$beforeOperator = substr( $substring, $placeholderPos - 7, 2 );
		} else {
			$beforeOperator = substr( $substring, $placeholderPos - 3, 2 );
		}
		if ( $beforeOperator == "[[" ) {
			return true;
		}
		return false;
	}

}
