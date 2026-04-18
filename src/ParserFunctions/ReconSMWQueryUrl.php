<?php

namespace Recon\ParserFunctions;

use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\MediaWikiServices;
use Recon\SMW\SMWQueryHelperForUrl;
use Recon\ParserFunctions\ParserFunctionUtils;

class ReconSMWQueryUrl {

	/**
	 * Parser function #recon-smwquery-url
	 * Creates URL for the recon-suggest-entity API module
	 */
	public function run( Parser $parser, PPFrame $frame, $args ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$paramsAllowed = [
			"query" => "[[Creation date::+]]",
			"searchprop" => $config->get( "ReconAPISearchableLabelProp" ) ?? $config->get( "ReconAPILabelProp" ),
			"labelprop" => $config->get( "ReconAPILabelProp" ),
			"descriptionprop" => $config->get( "ReconAPIDescriptionProp" ),
			// unused
			"sort" => $config->get( "ReconAPILabelProp" ),
			"order" => "asc"
		];
		[ $queryCondition, $searchProp, $labelProp, $descriptionProp, $sortProp, $order ] = array_values( ParserFunctionUtils::extractParams( $frame, $args, $paramsAllowed ) );

		$res = SMWQueryHelperForUrl::convertQueryToJSONObject( $queryCondition, $searchProp, $labelProp, $descriptionProp );

		return [ $res, "noparse" => false, "isHTML" => false ];
	}

}
