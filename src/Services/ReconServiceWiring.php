<?php

/**
 * Class for wiring classes and services
 */

namespace Recon\Services;

use MediaWiki\MediaWikiServices;
use MediaWiki\Config\GlobalVarConfig;
use Recon\SMW\SMWQueryBuilder;
use Recon\SMW\SMWQueryHelper;

// @codeCoverageIgnoreStart
/** @phpcs-require-sorted-array */
return [

	"SMWQueryBuilder" => static function ( MediaWikiServices $services ): SMWQueryBuilder {
        // @todo
		$mainConfig = $services->getMainConfig();
		$smwConfig = new GlobalVarConfig( 'smwg' );

		return new SMWQueryBuilder();
	},

	"SMWQueryHelper" => static function ( MediaWikiServices $services ): SMWQueryHelper {
		$mainConfig = $services->getMainConfig();
		$smwConfig = new GlobalVarConfig( 'smwg' );
		return new SMWQueryHelper(
			$mainConfig,
			$smwConfig
		);
	}

];
