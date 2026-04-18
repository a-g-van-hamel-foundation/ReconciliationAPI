<?php

namespace Recon\ParserFunctions;

use MediaWiki\Parser\Parser;
use MediaWiki\MediaWikiServices;
use MediaWiki\MainConfigNames;
use MediaWiki\Html\Html;
use Recon\ParserFunctions\ParserFunctionUtils;

class ReconSearch {

	/**
	 * Parser function #recon-search
	 */
	public function run( Parser $parser, $frame, $args ) {
		$random = rand(10000,99999);
		$paramsAllowed = [
			"apiurl" => false,
			"apiurlparams" => false,
			"targeturl" => false,
			"footerurl" => false,
			"id" => "recon-widget-sitesearch-$random",
			"class" => "recon-search-widget",
			"placeholder" => "Search the website",
			"internal" => false,
			"dev" => "false"
		];
		list( $apiUrl, $apiUrlParams, $targetUrl, $footerUrl, $id, $class, $placeholder, $internal, $dev ) = array_values( ParserFunctionUtils::extractParams( $frame, $args, $paramsAllowed ) );
		$showDevInfo = ( $dev == "false" ) ? false : true;

		if ( $targetUrl == false || $footerUrl == false ) {
			$canonServer = MediaWikiServices::getInstance()->getUrlUtils()->getCanonicalServer();
			$scriptPath = MediaWikiServices::getInstance()->getMainConfig()->get( MainConfigNames::ScriptPath );
			$server = $canonServer . $scriptPath;
			if ( $targetUrl == false ) {
				// Assuming present website is intended
				$targetUrl = $server . "/index.php?title=";
			}
			if ( $footerUrl == false ) {
				// Assuming present website is intended
				$footerUrl = $server . "/index.php?title=Special:Search&fulltext=1&search=";
			}	
		}

		if ( $apiUrl !== false && $apiUrlParams == false ) {
			// Using full URL with query params already included
			$apiParts = explode( '?', $apiUrl );
			$apiUrlBase = $apiParts[0];
			$apiUrlParams = parse_url( $apiUrl, PHP_URL_QUERY );
		} elseif( $apiUrl !== false ) {
			$apiParts = explode( '?', $apiUrl );
			$apiUrlBase = $apiParts[0];
			$apiUrlParams = $this->convertToUrlQueryString( $apiUrlParams, "/n" );
		} else {
			return "";
		}
		parse_str( $apiUrlParams, $parsed);
		$queryParamsJson = json_encode($parsed, JSON_UNESCAPED_UNICODE );
		$apiUrl = "{$apiUrlBase}?{$apiUrlParams}";

		// @note Our Vue code converts names as variables
		// e.g. data-api-base-url => apiBaseUrl
		$attributes = [
			"id" => $id,
			"class" => $class,
			"data-widget-type" => "sitesearch",
			"data-api-url" => $apiUrl,
			"data-api-base-url" => $apiUrlBase,
			"data-api-url-params" => $queryParamsJson,
			"data-target-url" => $targetUrl,
			"data-footer-url" => $footerUrl,
			"data-random" => "sitesearch-$random",
			"data-placeholder" => $placeholder,
			"data-internal" => $internal
		];
		$res = Html::rawElement( "div", $attributes, "" );

		// Show additional info intended for development only
		if ( $showDevInfo ) {
			$res .= "<div class='alert alert-warning mt-4'>Dev info. API: <a href='$apiUrl'>$apiUrl</a> <br>Target URL: <a href='$targetUrl'>$targetUrl</a> <br>Footer URL: $footerUrl</div>";
		}

		// Add module only if/when first instance of parser function
		// is detected
		$parserOutput = $parser->getOutput();
		$extData = $parserOutput->getExtensionData( "recon-search-used" );
		if ( $extData == null ) {
			$uuid = sha1( $random );
			$parserOutput->appendExtensionData( "recon-search-used", $uuid );
			$parserOutput->addModules( [
				'ext.recon.base'
			] );
		}

		return [ $res, 'noparse' => true, 'isHTML' => true ];
	}

	private function convertToUrlQueryString(
		string $str,
		string $delimiter = "\n"
	) {
		// params = "prop=val"
		$declarations = explode( PHP_EOL, $str );
		$data = [];
		foreach( $declarations as $declarationStr ) {
			 $declaration = explode( "=", $declarationStr );
			 $data[ $declaration[0] ] = $declaration[1] ?? "";
		}
		return http_build_query( $data );
	}

}
