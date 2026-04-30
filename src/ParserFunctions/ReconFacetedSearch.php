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
			// wiki template:
			"template" => null,
			// #ask parameters
			"format" => "plainlist",
			"valuesep" => ";",
			"limit" => "10",
			"sort" => null,
			"order" => null,
			"userparam" => null,
			// pagination
			"maxpages" => "5",
			// active if "true":
			"scrollmargintop" => "0px",
			"debug" => null
		];
		[ $profile, $profileId, $template, $format, $valueSep, $limit, $sort, $order, $userParam, $maxPages, $scrollMarginTop, $debug ] = array_values( ParserFunctionUtils::extractParams( $frame, $args, $paramsAllowed ) );
		$parser->getOutput()->addModuleStyles( [ 
			"recon.general.styles"
		] );
		$parser->getOutput()->addModules( [
			"ext.recon.facetedsearch"
		] );

		global $smwgDefaultStore;
		global $smwgEnabledFulltextSearch;
		global $smwgFulltextSearchMinTokenSize;

		$attributes = [
			"id" => "recon-faceted-search-" . rand(10000,99999),
			"class" => "recon-faceted-search-widget",
			"data-smw-fts" => $smwgEnabledFulltextSearch ? "1" : "0",
			"data-smw-elastic" => $smwgDefaultStore == "SMW\Elastic\ElasticStore" ? "1" : "0",
			"data-smw-fts-mintokensize" => $smwgFulltextSearchMinTokenSize,
			"data-result-format" => $format,
			"data-template" => $template,
			"data-value-sep" => $valueSep,
			"data-limit" => $limit,
			"data-sort" => $sort,
			"data-order" => $order,
			"data-userparam" => $userParam,
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
