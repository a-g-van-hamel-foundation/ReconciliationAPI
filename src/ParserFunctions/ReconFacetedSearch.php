<?php

namespace Recon\ParserFunctions;

use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;
use MediaWiki\MediaWikiServices;
//use MediaWiki\MainConfigNames;
use MediaWiki\Html\Html;
use Recon\ParserFunctions\ParserFunctionUtils;

class ReconFacetedSearch {
	/**
	 * Parser function #recon-faceted-search
	 */
	public function run( Parser $parser, $frame, $args ) {
		//$random = rand(10000,99999);
		$paramsAllowed = [
			// Either profile or profileid is mandatory
			"profile" => null,
			"profileid" => null
		];
		[ $profile, $profileId ] = array_values( ParserFunctionUtils::extractParams( $frame, $args, $paramsAllowed ) );

		$parser->getOutput()->addModuleStyles( [ "recon.general.styles" ] );
		$parser->getOutput()->addModules( [ "ext.recon.facetedsearch" ] );

		// not possible to get these globals through $config = MediaWikiServices::getInstance()->getMainConfig();
		global $smwgDefaultStore;
		global $smwgEnabledFulltextSearch;
		global $smwgFulltextSearchMinTokenSize;

		$attributes = [
			"class" => "recon-faceted-search-widget",
			"data-smw-fts" => $smwgEnabledFulltextSearch ? "1" : "0",
			"data-smw-elastic" => $smwgDefaultStore == "SMW\Elastic\ElasticStore" ? "1" : "0",
			"data-smw-fts-mintokensize" => $smwgFulltextSearchMinTokenSize
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
		$res = Html::rawElement( "div", $attributes, "..." );
		return $res;
	}

}
