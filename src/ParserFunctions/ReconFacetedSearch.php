<?php

namespace Recon\ParserFunctions;

use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;
//use MediaWiki\MediaWikiServices;
//use MediaWiki\MainConfigNames;
use MediaWiki\Html\Html;
use Recon\ParserFunctions\ParserFunctionUtils;

class ReconFacetedSearch {
	/**
	 * Parser function #recon-faceted-search
	 */
	public function run( Parser $parser, $frame, $args ) {
		$paramsAllowed = [
			// Either profile or profileid is mandatory
			"profile" => null,
			"profileid" => null,
			"output" => "ask",

			// #ask parameters if 
			"format" => null,
			// ! template used for both 'ask' and 'template'
			"template" => null,
			"limit" => "10",
			"sort" => null,
			"order" => null,

			// additional when output = 'template'
			"resultformats" => "",

			// pagination
			"maxpages" => "5",
			// active if "true":
			"scrollmargintop" => "0px",
			"debug" => null
		];
		[ $profile, $profileId, $output, $format, $template, $limit, $sort, $order, $resultFormats, $maxPages, $scrollMarginTop, $debug ] = array_values( ParserFunctionUtils::extractParams( $frame, $args, $paramsAllowed ) );
		if ( $output === "ask" && $format === null && $template === null ) {
			// reset
			$output = "basic";
		}

		// SMW parameters 
		$askParams = [];
		foreach( $args as $k => $arg ) {
			$paramExpanded = $frame->expand( $arg );
			$keyValPair = explode('=', $paramExpanded, 2);
			$paramName = trim( $keyValPair[0] );
			$paramValue = trim( $keyValPair[1] ?? "" );
			// Skip allowed parameters and empty keys
			if ( array_key_exists( $paramName, $paramsAllowed ) || $paramValue == "" ) {
				continue;
			}
			$askParams[$paramName] = trim( $keyValPair[1] );
		}
		// Force searchlabel to empty value (easily overlooked)
		$askParams["searchlabel"] = "";

		// Load RL modules
		$parser->getOutput()->addModuleStyles( [ 
			"recon.general.styles"
		] );
		$rlModules = [ "ext.recon.facetedsearch" ];
		if ( $format && $format === "iiif-canvas-viewer" ) {
			$rlModules[] = "ext.iiif.ace";
		} elseif( $format && $format === "gallery" ) {
			// MMV buggy - needs to know images upfront?
			//$rlModules[] = "mmv";
			//$rlModules[] = "mmv.carousel";
			//$rlModules[] = "mmv.ui.beta";
		}
		$parser->getOutput()->addModules( $rlModules );

		global $smwgDefaultStore;
		global $smwgEnabledFulltextSearch;
		global $smwgFulltextSearchMinTokenSize;

		$attributes = [
			"id" => "recon-faceted-search-" . rand(10000,99999),
			"class" => "recon-faceted-search-widget",
			"data-smw-fts" => $smwgEnabledFulltextSearch ? "1" : "0",
			"data-smw-elastic" => $smwgDefaultStore == "SMW\Elastic\ElasticStore" ? "1" : "0",
			"data-smw-fts-mintokensize" => $smwgFulltextSearchMinTokenSize,

			"data-output" => $output,

			// smw
			"data-result-format" => $format,
			"data-template" => $template,
			"data-limit" => $limit,
			"data-sort" => $sort,
			"data-order" => $order,
			"data-ask-params" => json_encode( $askParams, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),

			"data-result-formats" => $resultFormats,

			"data-maxpages" => $maxPages,
			"data-scrollmargintop" => $scrollMarginTop,
			"data-debug" => $debug
		];
		if ( $profile !== null && $profile !== "" ) {
			$profileId = Title::newFromText( $profile )->getId();
			$attributes["data-profile"] = $profile;
			$attributes["data-profile-id"] = $profileId;
		} elseif( $profileId !== null && $profileId !== "" ) {
			$profile = Title::newFromID( $profileId )->getPrefixedText();
			$attributes["data-profile"] = $profile;
			$attributes["data-profile-id"] = $profileId;
		}

		return Html::rawElement( "div", $attributes, "" );
	}

}
