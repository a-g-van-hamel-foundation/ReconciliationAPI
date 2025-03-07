<?php

/**
 * Suggest property values based on given property and substring.
 * Supports SMW.
 * Not part of the Reconciliation Service API
 * but useful for autocompletion.
 */

namespace Recon\API;

use Wikimedia\ParamValidator\ParamValidator;
use Recon\SMW\SMWSuggestPropertyValue;
use Recon\SMW\SMWUtils;

class APISuggestPropertyValue extends \ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		//default smw:
		$source = $params['source'];
		$prefix = $params['prefix'] ?? "";
		$substring = $params['substr'] ?? $prefix;
		$substringPattern = $params['substrpattern'];
		$property = $params['property'] ?? null;
		$offset = $params['offset'] ?? 0;
		$limit = $params['limit'];

		$res = [];
		if ( $source == "smw" && SMWUtils::isSMWStoreAvailable() ) {
			$smwSuggestPropertyValue = new SMWSuggestPropertyValue();
			$res = $smwSuggestPropertyValue->run( $property, $substring, $substringPattern, true, intval($offset), intval($limit) );
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
			'api.php?action=recon-suggest-propvalue&source=smw&property=Display_title_of&substr=T&offset=5&limit=10',
			'api.php?action=recon-suggest-propvalue&source=smw&property=Class&substr=Dis&limit=5&offset=0&limit=5'
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
			"substr" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			"substrpattern" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => "tokenprefix"
			],
			"property" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			"offset" => [
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
