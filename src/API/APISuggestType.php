<?php

/**
 * API Action module 'suggest-type'
 * Supports MW and SMW.
 * @link https://www.w3.org/community/reports/reconciliation/CG-FINAL-specs-0.2-20230410/#suggest-types-response-json-schema
 * 
 * @todo Maybe support profiles
 */

namespace Recon\API;

use Wikimedia\ParamValidator\ParamValidator;
use Recon\MW\MWSuggestType;
use Recon\SMW\SMWSuggestType;
use Recon\SMW\SMWUtils;

class APISuggestType extends \ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		$profileID = $params["profile"] ?? false;

		//prefix
		$substr = $params["substr"] ?? $params['prefix'] ?? "";
		$substrpattern = $params["substrpattern"] ?? "stringprefix";
		$category = $params["cat"] ?? null;
		$offset = intval( $params["offset"] );
		$limit = intval( $params["limit"] );
		$typeGroup = $params["typegroup"] ?? null;

		$res = [];
		// For now. The following may be retrieved from a profile instead
		$source = $params["source"] ?? "smw";
		if ( $source == "smw" && SMWUtils::isSMWStoreAvailable() ) {
			$suggester = new SMWSuggestType();
			$res = $suggester->run( $substr, $typeGroup, $profileID, $offset, $limit );
		} elseif( $source == "mw" ) {
			// type = Category
			$suggester = new MWSuggestType();
			$res = $suggester->run( $substr, $substrpattern, null, $category, $offset, $limit );
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
			"profile" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false				
			],
			"cat" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false	
			],
			"substr" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			"prefix" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			"substrpattern" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			"typegroup" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			"offset" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => '0'
			],
			"limit" => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => $this->getConfig()->get( "ReconAPIMaxResults" )
			],
			// @todo not implemented
			"displaytitle" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			]
		];
	}

}
