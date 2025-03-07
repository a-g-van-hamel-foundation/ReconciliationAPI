<?php

/**
 * API module 'recon-query-request'
 * An exact equivalent of the 'recon' module when used with the
 * `extend` parameter (see there).
 * It is not required as a separate module by the Data Extension 
 * service.
 */

namespace Recon\API;

use Wikimedia\ParamValidator\ParamValidator;
use Recon\MW\MWExtendQueryRequest;
use Recon\SMW\SMWExtendQueryRequest;
use Recon\SMW\SMWUtils;

class APIExtendQueryRequest extends \ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		$queryRequest = $params["extend"];
		$source = $params["source"];
		$res = [];
		if ( $queryRequest !== "" ) {
			if ( $source == "mw" ) {
				$mwExtendQueryRequest = new MWExtendQueryRequest();
				$res = $mwExtendQueryRequest->run( $queryRequest );
			} elseif( $source == "smw" && SMWUtils::isSMWStoreAvailable() ) {
				$smwExtendQueryRequest = new SMWExtendQueryRequest();
				$res = $smwExtendQueryRequest->run( $queryRequest );
			}
		}

		$apiResult = $this->getResult();		
		foreach( $res as $key => $val ) {
			$apiResult->addValue( null, $key, $val );
		}
	}

	public function getAllowedParams() : array {
		return [
			"source" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => "smw"
			],
			"extend" =>  [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			// Unused
			"limit" => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => $this->getConfig()->get( "ReconAPIMaxResults" )
			]
		];
	}

}
