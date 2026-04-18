<?php

namespace Recon\ParserFunctions;

class ParserFunctionUtils {

	public static function extractParams( $frame, array $params, $paramsAllowed ) {
		$incomingParams = [];
		foreach ( $params as $param) {
			$paramExpanded = $frame->expand( $param );
			$keyValPair = explode('=', $paramExpanded, 2);
			$paramName = trim( $keyValPair[0] );
			$value = ( array_key_exists( 1, $keyValPair) ) ? trim( $keyValPair[1] ) : "";
			$incomingParams[$paramName] = $value;
		}
		$params = [];
		foreach ( $paramsAllowed as $paramName => $default ) {
			$params[$paramName] = ( array_key_exists( $paramName, $incomingParams ) ) ? $incomingParams[$paramName] : $default;
		}
		return $params;
	}

	/*
	private function isParserFunctionUsed( Parser $parser, string $name ): bool {
		$extData = $parser->getOutput()->getExtensionData( $name );
		if ( $extData !== null && array_key_exists( $name, $extData ) ) {
			$counter = $extData[$name];
			$pfIsUsed = ( $counter === 1 ) ? true : false;
			return $pfIsUsed;
		}
		return false;
	}
	*/

}
