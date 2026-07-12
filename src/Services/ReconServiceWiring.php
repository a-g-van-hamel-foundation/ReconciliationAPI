<?php

/**
 * Class for wiring classes and services
 */

namespace Recon\Services;

use MediaWiki\MediaWikiServices;
use MediaWiki\Config\GlobalVarConfig;
use SMW\SQLStore\QueryEngine\FulltextSearchTableFactory;
//use Recon\Services\ReconServices;
use Recon\SMW\SMWUtils;
use Recon\SMW\SMWQueryBuilder;
use Recon\SMW\SMWQueryHelper;
use Recon\SMW\SMWQueryHelperForFTS;

// @codeCoverageIgnoreStart
/** @phpcs-require-sorted-array */
return [

	"SMWQueryBuilder" => static function ( MediaWikiServices $services ): SMWQueryBuilder {
		$mainConfig = $services->getMainConfig();
		$smwConfig = new GlobalVarConfig( "smwg" );
		$smwQueryHelper = new SMWQueryHelper(
			$mainConfig,
			$smwConfig
		);
		$smwStore = SMWUtils::getSMWStore();

		return new SMWQueryBuilder(
			$smwStore,
			$smwQueryHelper,
			$mainConfig,
			$smwConfig
		);
	},

	"SMWQueryHelper" => static function ( MediaWikiServices $services ): SMWQueryHelper {
		// maybe load SMWQueryHelperForFTS if FTS is enabled
		// $smwQueryHelperForFTS = new SMWQueryHelperForFTS();
		$mainConfig = $services->getMainConfig();
		$smwConfig = new GlobalVarConfig( "smwg" );

		return new SMWQueryHelper(
			$mainConfig,
			$smwConfig
		);
	},

	"SMWQueryHelperForFTS" => static function ( MediaWikiServices $services ): SMWQueryHelperForFTS {
		$fulltextSearchTableFactory = new FulltextSearchTableFactory();
		$textSanitizer = $fulltextSearchTableFactory->newTextSanitizer();
		$smwConfig = new GlobalVarConfig( "smwg" );

		return new SMWQueryHelperForFTS(
			$textSanitizer,
			$smwConfig
		);
	}

];
