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
		$mainConfig = MediaWikiServices::getInstance()->getMainConfig();

		$random = rand(10000,99999);
		$paramsAllowed = [
			"apiurl" => false,
			"apiurlparams" => false,
			"targeturl" => false,
			"footerurl" => false,
			"searchaction" => $mainConfig->get( MainConfigNames::Script ),
			"searchpage" => "Special:Search",
			"searchpageparams" => false,
			"id" => "recon-widget-sitesearch-$random",
			"class" => "recon-search-widget",
			"placeholder" => "Search the website",
			"internal" => false,
			"showthumbnail" => "true",
			"dev" => "false"
		];
		list( $apiUrl, $apiUrlParams, $targetUrl, $footerUrl, $searchAction, $searchPage, $searchPageParams, $id, $class, $placeholder, $internal, $showThumbnail, $dev ) = array_values( ParserFunctionUtils::extractParams( $frame, $args, $paramsAllowed ) );
		$showDevInfo = ( $dev == "false" ) ? false : true;

		// Set default for searchpageparams
		if ( $searchPage == "Special:Search" && !$searchPageParams ) {
			$searchPageParams = "fulltext=1\nsearch=";
		} elseif ( str_starts_with( $searchPage, "Special:ReconRedirect" ) && !$searchPageParams ) {
			$searchPageParams = "skipcheck=true\nq=";
		}

		// targetUrl, footerUrl
		if ( $targetUrl == false || $footerUrl == false ) {
			$canonServer = MediaWikiServices::getInstance()->getUrlUtils()->getCanonicalServer();
			
			$scriptPath = $mainConfig->get( MainConfigNames::ScriptPath );
			$server = $canonServer . $scriptPath;
			// targeturl
			if( $targetUrl == false && str_starts_with( $searchPage, "Special:ReconRedirect" ) ) {
				$targetUrl = $searchAction . "?title={$searchPage}&q=";
			} elseif ( $targetUrl == false && $searchPage == "Special:Search" ) {
				// Assuming present website is intended
				$targetUrl = $searchAction . "?title=";
			}
			// footerurl
			if( $footerUrl == false && str_starts_with( $searchPage, "Special:ReconRedirect" ) ) {
				$footerUrl = $searchAction . "?title={$searchPage}&skipcheck=true&q=";
			} elseif ( $footerUrl == false && $searchPage == "Special:Search" ) {
				// Assuming present website is intended
				$footerUrl = $searchAction . "?title=Special:Search&fulltext=1&search=";
			}
		}
		$searchPageParamsStr = $this->convertToUrlQueryString( $searchPageParams );

		// Handle API url
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
		parse_str( $apiUrlParams, $parsed );
		$queryParamsJson = json_encode( $parsed, JSON_UNESCAPED_UNICODE );
		$apiUrl = "{$apiUrlBase}?{$apiUrlParams}";

		$attributes = [
			"id" => $id,
			"class" => $class,
			"data-widget-type" => "sitesearch",
			"data-api-url" => $apiUrl,
			"data-api-base-url" => $apiUrlBase,
			"data-api-url-params" => $queryParamsJson,
			"data-target-url" => $targetUrl,
			"data-footer-url" => $footerUrl,
			"data-search-action" => $searchAction,
			"data-search-page" => $searchPage,
			"data-search-page-params" => $searchPageParamsStr,
			"data-random" => "sitesearch-$random",
			"data-placeholder" => $placeholder,
			"data-internal" => $internal,
			"data-show-thumbnail" => $showThumbnail
		];
		// placeholder with this height serves to alleviate movement
		$placeholderEl = "<span style='display:block;height:32px;'></span>";
		$res = Html::rawElement( "div", $attributes, $placeholderEl );

		// Show additional info intended for development only
		if ( $showDevInfo ) {
			$res .= "<div class='alert alert-warning mt-4'>Dev info. API: <a href='$apiUrl'>$apiUrl</a> <br>Target URL: <a href='$targetUrl'>$targetUrl</a> <br>Footer URL: $footerUrl <br>search action: $searchAction <br>search page: $searchPage <br>search page params: $searchPageParamsStr</div>";
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
