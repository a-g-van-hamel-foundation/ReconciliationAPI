<?php

/**
 * Action API module 'recon-suggest-entity'
 * Intended for both reconciliation and autocompletion
 * @link https://www.w3.org/community/reports/reconciliation/CG-FINAL-specs-0.2-20230410/#suggest-entities-response-json-schema
 */

namespace Recon\API;

use Wikimedia\ParamValidator\ParamValidator;
use ApiResult;
use Recon\MW\MWSuggestEntity;
use Recon\SMW\SMWSuggestEntity;
use Recon\SMW\SMWUtils;
use Recon\Config\ReconConfig;
use Recon\ReconUtils;

class APISuggestEntity extends \ApiBase {

	private $useDisplayTitle = null;
	private $wgReconAPIMWUseDisplayTitle;

	public function execute() {
		$params = $this->extractRequestParams();

		global $wgDBname;
		$address = $_SERVER['REMOTE_ADDR'];
		$logMsg = "{$wgDBname} / API module recon-suggest-entity visited by $address";
		ReconUtils::log( $logMsg, $params );

		// TEMP
		//return;

		// prefix is an alias for substr
		$prefix = $params['prefix'] ?? "";
		$substr = $params['substr'] ?? $prefix;
		// match on 'tokenprefix', 'stringprefix', or 'allchars' (al. 'contains')
		$substrPattern = $params['substrpattern'] ?? null;

		$categories = $params['cat'] ?? null;
		$namespaces = $params['ns'] ?? null;
		$profileID = $params['profile'] ?? null;
		$concept = $params['concept'] ?? null;

		// To be considered, not implemented
		// $property = $params['property'] ?? null;
		// $query = $params['query'] ?? null;

		// Option to format result for Page Forms
		$formatForPageForms = $params['pfautocomplete'] ?? 0;
		
		$res = [
			"description" => "not yet implemented"
		];
		$offset = $params['offset'] ?? 0;
		$limit = $params['limit'];

		// source
		$source = $params['source'] ?? null;
		if ( $profileID !== null && $profileID !== "" ) {
			$reconConfig = new ReconConfig( $profileID );
			$source = $reconConfig->getSource();
		}

		// displaytitle
		if( isset( $params['displaytitle'] ) ) {
			$this->useDisplayTitle = $params['displaytitle'] == 1 ? true : false;
		} elseif( $source == "mw" ) {
			// Leave decision to default setting or profile.
			$this->useDisplayTitle = null;
		} elseif( $source == "smw" ) {
			// SMW - null?
			$this->useDisplayTitle = false;
		} else {
			// Leave the decision to profile or default setting
			$this->useDisplayTitle = null;
		}

		if ( $source == "mw" ) {
			$mwSuggestEntity = new MWSuggestEntity();
			// $mwSuggestEntity->setOptions( intval($offset), intval($limit) );
			if ( $categories !== null && $categories !== "" ) {
				$mwSuggestEntity->setCategories( $categories );
			}
			if ( $namespaces !== null && $namespaces !== "" ) {
				$mwSuggestEntity->setNamespaces( $namespaces );
			}
			$res = $mwSuggestEntity->run(
				$substr,
				$substrPattern,
				$this->useDisplayTitle,
				$profileID,
				intval($offset),
				intval($limit)
			);
		} elseif ( $source == "smw" && SMWUtils::isSMWStoreAvailable() ) {
			$smwSuggestEntity = new SMWSuggestEntity();
			$smwSuggestEntity->setOptions( intval($offset), intval($limit), $formatForPageForms );
			$res = $smwSuggestEntity->run(
				$substr,
				$substrPattern,
				$concept,
				$this->useDisplayTitle,
				$profileID
			);
		} elseif( $source == "cargo" ) {
			// Not implemented.
		} elseif( $source == "wikibase" ) {
			// Not implemented.
		}

		$apiResult = $this->getResult();
		// Note: all booleans must be supported for formatversion=1, too
		// e.g. ApiResult::META_BC_BOOLS => [ 'match' ]
		foreach( $res as $key => $val ) {
			$apiResult->addValue( null, $key, $val );
		}
		$this->setCache();
	}

	public function getAllowedParams() : array {		
		$arr = [
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
			"substrpattern" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			"concept" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			// @todo not implemented
			"property" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			// @todo maybe smwquery - not implemented
			"query" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			"displaytitle" => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false
			],
			// namespaces:
			"ns" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			// MW categories
			"cat" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false
			],
			"pfautocomplete" => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => 0,
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
		return $arr;
	}

	private function setCache() {
		$this->getMain()->setCacheMaxAge( 3600 );
		$this->getMain()->setCacheMode( 'private' );
	}

}
