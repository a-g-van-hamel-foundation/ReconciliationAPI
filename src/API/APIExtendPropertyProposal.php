<?php

/**
 * API module 'recon-propose-property'
 * Property proposals are part of the Data Extension service.
 * @link https://www.w3.org/community/reports/reconciliation/CG-FINAL-specs-0.2-20230410/#data-extension-property-proposals
 */

namespace Recon\API;

use Wikimedia\ParamValidator\ParamValidator;
use Recon\MW\MWExtendPropertyProposal;
use Recon\SMW\SMWExtendPropertyProposal;
use Recon\SMW\SMWUtils;

class APIExtendPropertyProposal extends \ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		$source = $params["source"];
		$class = $params["type"] ?? null;
		$resultLimit = $params["limit"] ?? false;

		$res = [];
		if ( $source == "mw" ) {
			$mwPropose = new MWExtendPropertyProposal();
			if ( $class !== null && $class !== "" ) {
				$res = $mwPropose->run( $class );
			}
		} elseif( $source = "smw" && SMWUtils::isSMWStoreAvailable() ) {
			$smwPropose = new SMWExtendPropertyProposal();
			if ( $class !== null && $class !== "" ) {
				$res = $smwPropose->run( $class, $resultLimit );
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
				ParamValidator::PARAM_DEFAULT => "mw"
			],
			"type" =>  [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			"limit" => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => $this->getConfig()->get( "ReconAPIMaxResults" )
			]
		];
	}

}
