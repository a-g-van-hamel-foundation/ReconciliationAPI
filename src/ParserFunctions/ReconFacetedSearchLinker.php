<?php

namespace Recon\ParserFunctions;
//use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;
//use MediaWiki\Html\Html;
use Recon\ParserFunctions\ParserFunctionUtils;

class ReconFacetedSearchLinker {

	/**
	 * Parser function #recon-faceted-search-link
	 * Generates a link to a faceted search page with 
	 * pre-filled filters based on the provided parameters.
	 */
	public function run( $parser, $frame, $args ) {
		// Standard parameters for the link
		$standardParams = [
			// "search" or "fragment"
			"page" => null,
			"updateurl" => "search",
			"valuesep" => ";"
		];
		[ $page, $updateUrl, $valuesep ] = array_values( ParserFunctionUtils::extractParams( $frame, $args, $standardParams ) );

		// 'Free' parameters for filters to be added to the URL
		$filterParams = [];
		foreach( $args as $k => $arg ) {
			$paramExpanded = $frame->expand( $arg );
			$keyValPair = explode('=', $paramExpanded, 2);
			$paramName = trim( $keyValPair[0] );
			$paramValue = trim( $keyValPair[1] ?? "" );
			// Skip allowed parameters and empty keys
			if ( array_key_exists( $paramName, $standardParams ) || $paramValue == "" ) {
				continue;
			}
			//
			$filterParams[$paramName] = trim( $keyValPair[1] );
		}

		// Create url filter string from filter parameters
		$urlFilterStrings = [];
		foreach ( $filterParams as $n => $v ) {
			if ( str_ends_with($n, "[]" ) ) {
				// special handling for multiple values
				$vals = explode( $valuesep, $v );
				foreach( $vals as $val ) { 
					$urlFilterStrings[] = urlencode($n) . "=" . urlencode( trim($val) );
				}
			} else {
				$urlFilterStrings[] = urlencode($n) . "=" . urlencode( $v );
			}
		}
		$urlFilterString = implode( "&", $urlFilterStrings );

		$title = $this->getTitle( $parser, $page );
		$titleUrl = "";
		if ( $title == null ) {
			return "";
		} elseif ( $updateUrl === "search" ) {
			$titleUrl = $title->getFullURL( $urlFilterString );
		} elseif ( $updateUrl === "fragment" ) {
			$titleUrl = $title->getFullURL() . "#" . $urlFilterString;
		}
		return $titleUrl;
	}

	/**
     * 
     */
    private function getTitle( $parser, $page = null ) {
        if ( $page !== null ) {
			$title = Title::newFromText( $page );
		} else {
            // current page
			$title = $parser->getPage();
		}
		if ( $title instanceof Title && $title->canExist() ) {
            return $title;
		}
        return null;
    }
	
}
