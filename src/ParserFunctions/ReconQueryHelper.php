<?php

namespace Recon\ParserFunctions;

use MediaWiki\Parser\Parser;
use MediaWiki\Html\Html;
use Recon\ParserFunctions\ParserFunctionUtils;

class ReconQueryHelper {

	/**
	 * #recon-query-helper - development only
	 * 
	 * @param Parser $parser
	 * @param mixed $frame
	 * @param mixed $args
	 */
	public function run( Parser $parser, $frame, $args ) {
		//$random = rand(10000,99999);
		$paramsAllowed = [
			"query" => false,
			"type" => false,
			"limit" => 25,
			"properties" => false,
			"type_strict" => "should"
		];
		list( $query, $type, $limit, $properties, $typeStrict ) = array_values( ParserFunctionUtils::extractParams( $frame, $args, $paramsAllowed  ) );

		$propArr = json_decode( html_entity_decode( $properties ) );
		$queryArr = [];
		$queryArr["q0"] = [
			"query" => $query,
			"type" => $type,
			"limit" => intval($limit),
			"properties" => $propArr,
			"type_strict" => $typeStrict
		];
		$jsonStr = json_encode( $queryArr, JSON_PRETTY_PRINT );

		$attributes = [
			"class" => "recon-query-helper"
		];
		$res = Html::rawElement( "pre", $attributes, $jsonStr );
		return [ $res, 'noparse' => true, 'isHTML' => true ];
	}

}
