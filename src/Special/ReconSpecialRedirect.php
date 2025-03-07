<?php

namespace Recon\Special;

use \Title;
use \MediaWiki\MediaWikiServices;
use Recon\Config\ReconConfig;
use Recon\SMW\SMWQueryBuilder;
use Recon\SMW\SMWResultFormatter;

class ReconSpecialRedirect extends \SpecialPage {

	private $mainConfig;
	private $queryPageFrom = null;
	private $queryPage = null;
	// Array for URL query string
	private $query = [];
	private $profileID = null;
	private $redirectProfile = null;
	private $redirectConditions = false;

	public function __construct( $name = 'ReconRedirect' ) {
		parent::__construct( $name );
		$this->mainConfig = MediaWikiServices::getInstance()->getMainConfig();
	}

	function isExpensive() {
		return false;
	}

	function isSyndicated() {
		return false;
	}

	/**
	 * reads subpage as profile ID if subpage is numeric string
	 * else reads subpage as query page
	 * reads absence of a subpage as hint we're happy with the default
	 */
	public function execute( $subPage ) {
		$out = $this->getContext()->getOutput();
		$this->setHeaders();

		// Get profile data if ID is provided:
		$q = null;
		if ( isset( $subPage ) && is_numeric( $subPage ) ) {
			$this->queryPageFrom = "profile";
			// Special:ReconRedirect/34234
			$this->profileID = intval( $subPage );
			$profiler = new ReconConfig( $this->profileID );
			$profile = $profiler->getProfile();
			if ( isset( $profile["redirect"] ) ) {
				$this->redirectProfile = $profile["redirect"];
				$this->redirectConditions = isset( $profile["redirect"]["smwcondition"] )
					? $profile["redirect"]["smwcondition"]
					: false;
			}
			foreach( $_GET as $key => $value ){
				if ( $key == "q" ) {
					$q = htmlspecialchars( $value );
				}
			}
		} elseif( isset( $subPage ) ) {
			// The query page and its query string are part
			// of the URL, eg Special:ReconRedirect/MyQueryPage?...
			$this->queryPageFrom = "subpage";
			$this->queryPage = $subPage;
			if ( !empty( $_GET ) ) {
				$this->query = $_GET;
				$q = $this->query[ array_key_last( $this->query ) ];
			}
		} else {
			$this->queryPageFrom = "config";
			$this->queryPage = $this->mainConfig->get( 'ReconRedirectDefaultQueryPage' );
			if ( !empty( $_GET ) ) {
				$this->query = $_GET;
				$q = $this->query[ array_key_last( $this->query ) ];
			}
		}

		$phrase = $q ?? "";
		if ( $phrase !== "" ) {
			// if "" then ...
			$title = Title::newFromText( $phrase );
			// check that title isn't null
			// Check 1: does the page exist?
			$isKnown = $title->isKnown();
			// Check 2: can the present user access it?
			$isAccessible = $this->userCanAccess( $title );
			// Check 3 - default
			$isAvailable = true;
		} else {
			$isKnown = $isAccessible = $isAvailable = false;
		}

		// Check 3 - if profile asks for it, check property
		if ( $isKnown && $isAccessible && $this->redirectConditions ) {
			// Get the property that determines if page is available for viewing
			$conditionProp = $this->redirectConditions["smwproperty"] ?? false;
			$passValues = $this->redirectConditions["pass"] ?? [];
			$failValues = $this->redirectConditions["fail"] ?? [];
			$isAvailable = $this->checkAvailabilityAgainstSMWQuery( $phrase, $conditionProp, $passValues, $failValues );
		}

		if ( $isKnown && $isAccessible && $isAvailable ) {
			// Send to page
			$newUrl = $title->getLocalURL();
		} else {
			// Send to query page
			$newUrl = $this->getQueryPageUrl( $phrase );
			// @todo allow for configurable alt. query page
		}

		$this->redirectToUrl( $newUrl );
		//$res = "Redirect to: " . $newUrl;
		//$out->addWikiTextAsContent( $res );
	}

	private function userCanAccess( $title ) {
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		$action = "read";
		
		$currentUser = $this->getContext()->getUser();

		// or use userCan()
		$userCanAccess = $permissionManager->quickUserCan( $action, $currentUser, $title );
		return $userCanAccess;
	}

	private function getQueryPageUrl( $phrase ) {
		// @todo get searchpage default
		// @todo get from URL

		if ( $this->queryPageFrom == "profile" && $this->redirectProfile !== null ) {
			// Special:ReconRedirect/34234
			[ $queryPage, $query ] = $this->getQueryPageDataFromProfile( $this->redirectProfile, $phrase );
		} else {
			$queryPage = $this->queryPage;
			$query = $this->query;
		}

		$queryPageTitle = Title::newFromText( $queryPage );		
		return $queryPageTitle->getLocalUrl( $query );
	}

	private function getQueryPageDataFromProfile( array $profile, string $phrase ) {
		$queryPage = $profile["queryPage"] ?? null;
		$query = $profile["query"] ?? [];
		$queryKeyLast = array_key_last( $query );
		$query[ $queryKeyLast ] = $phrase;
		return [ $queryPage, $query ];
	}

	/**
	 * Summary of checkSMWQuery
	 * @param mixed $page
	 * @param string $propName property name
	 * @param array $passValues e.g. "Yes", "true"
	 * @param array $failValues e.g. "No", "false"
	 * @return bool
	 */
	private function checkAvailabilityAgainstSMWQuery(
		string $page,
		mixed $propName = false,
		array $passValues = [],
		array $failValues = []
	) {
		if ( !$propName ) {
			return true;
		}
		$rawQuery = "[[$page]]";
		$smwQueryBuilder = new SMWQueryBuilder();

		$smwQueryBuilder->addPrintoutProperties( [ $propName ] );
		$queryRes = $smwQueryBuilder->getResultForQuery( $rawQuery );

		$smwResultFormatter = new SMWResultFormatter( $queryRes, "" );
		$smwResultFormatter->addPrintoutProperties( [ $propName ] );
		$rawRes = $smwResultFormatter->getRawResult();
		// check if printouts exist first
		$results = $rawRes["results"];
		$printouts = $results[ array_key_first( $results ) ]["printouts"];
		$isAvailableArr = [];
		foreach ( $printouts as $k => $v ) {
			if( $k == $propName ) {
				$isAvailableArr = $v;
			}
		}
		foreach ( $isAvailableArr as $v ) {
			if ( in_array( $v, $failValues ) ) {
				return false;	
			}
			if ( in_array( $v, $passValues ) ) {
				return true;	
			}
			// Default assumption
			return false;
		}
		// No pass/vail values set, assuming true.
		return true;
	}


	/**
	 * Redirect to the given URL
	 * @param mixed $url
	 * @return void
	 */
	private function redirectToUrl( $url ) {
		$this->getContext()->getOutput()->redirect( $url );
	}

}

		// Get URL search params
		// Defaults
		/*
		$q = $sparam = null;
		$queryPageParam = "search";
		foreach( $_GET as $key => $value ){
			//echo $key . " : " . $value . "<br />\r\n";
			switch( $key ) {
				case "q": $q = htmlspecialchars( $value );
					break;
				case "s": $queryPage = $value;
					break;
				case "sparam": $queryPageParam = urlencode( $value);
					break;
				default:
					// maybe reproduce as given?
			}
		}
		*/
