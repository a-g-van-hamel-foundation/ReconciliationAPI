<?php

/**
 * Action API module 'recon-suggest-property'
 * @example api.php?action=recon-suggest-property&format=json&source=smw
 * Supports MW and SMW.
 * 
 * @link https://www.w3.org/community/reports/reconciliation/CG-FINAL-specs-0.2-20230410/#suggest-properties-response-json-schema
 */

namespace Recon\API;

use Wikimedia\ParamValidator\ParamValidator;
use Recon\SMW\SMWSuggestProperty;
use Recon\MW\MWSuggestProperty;
use Recon\SMW\SMWUtils;

class APISuggestProperty extends \ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		$source = $params['source'] ?? null;
		$prefix = $params['prefix'] ?? "";
		$substr = $params['substr'] ?? $prefix;
		$offset = $params['offset'] ?? "0";
		$limit = $params['limit'];

		// default
		$res = [];
		if ( strtolower( $source ) == "smw" && SMWUtils::isSMWStoreAvailable() ) {
			$smwSuggestProp = new SMWSuggestProperty();
			$res = $smwSuggestProp->getResults(
				$substr,
				intval( $offset ),
				intval( $limit )
			);
		} elseif( $source == "cargo" ) {
			// @todo unsupported currently
		} elseif( $source == "mw" ) {
			$mwSuggestProp = new MWSuggestProperty();
			$res = $mwSuggestProp->run( $substr );
		}

		$apiResult = $this->getResult();
		foreach( $res as $key => $val ) {
			$apiResult->addValue( null, $key, $val );
		}
	}

	/**
	 * @codeCoverageIgnore
	 * @return array
	 */
	protected function getExamples() {
		return [
			'api.php?action=recon-suggest-property&source=smw&substr=Has&limit=10&offset=0',
			'api.php?action=recon-suggest-property&source=smw&substr=Dis&limit=5&offset=0'
		];
	}

	public function getAllowedParams() : array {		
		return [
			"source" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => "smw"
			],
			"profile" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			"prefix" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			// alias of prefix
			"substr" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			"offset" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => "0"
			],
			"limit" => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => $this->getConfig()->get( "ReconAPIMaxResults" )
			]
		];
	}

}
