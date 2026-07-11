<?php

/**
 * Class for wiring classes and services 
 * using a Service Container and Dependency Injection.
 * 
 */

namespace Recon\Services;

use MediaWiki\Config\GlobalVarConfig;
use MediaWiki\MediaWikiServices;
use Recon\SMW\SMWQueryBuilder;
use Recon\SMW\SMWQueryHelper;
//use SMW\Query\QueryResult;

class ReconServices {

	public function __construct( private MediaWikiServices $services ) {
	}

	private static $instance = null;

	public static function getInstance(): ReconServices {
		if ( self::$instance !== null ) {
			return self::$instance;
		}
		self::$instance = new self( MediaWikiServices::getInstance() );
		return self::$instance;
	}

	public static function getInstance2(): self {
		if ( self::$instance === null ) {
			$bootstrapConfig = new GlobalVarConfig();
			
			$newInstance = new self( $bootstrapConfig );
			/* Loading wiring files when we get there
			// MW has MainConfigNames::ServiceWiringFiles
			$wiringFiles = $bootstrapConfig->get( null );
			$newInstance->loadWiringFiles( $wiringFiles );			
			*/
			self::$instance = $newInstance;
			// For now don't add HookRunner
		}
		return self::$instance;
	}

	public function getSMWQueryBuilder(): SMWQueryBuilder {
		return MediaWikiServices::getInstance()->getService( "SMWQueryBuilder" );
		//return $this->instance->getService( "SMWQueryBuilder" );
	}

	public function getSMWQueryHelper(): SMWQueryHelper {
		return MediaWikiServices::getInstance()->getService( "SMWQueryHelper" );
	}

}
