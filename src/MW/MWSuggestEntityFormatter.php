<?php

/**
 * @todo option to add a description
 * maybe from TextExtracts, maybe through SMW mapping
 * @todo maybe option to decide whether to show/hide namespace prefix (hidden by default)
 * @todo Handling redirects should probably be optional
 */

namespace Recon\MW;

use ApiResult;
use \MediaWiki\MediaWikiServices;
use \Title;
use Recon\ReconUtils;
use Recon\StringModification\StringModifier;
use Recon\MW\ExtPageImages;
use Recon\MW\MWCategoryUtils;
use Recon\API\APIReconQueryHandler;

class MWSuggestEntityFormatter {

	private $useDisplayTitle;
	// Whether to retrieve thumbnails from PageImages extension
	private $usePageImages = false;
	private $substring = "";
	private $consolidateRedirects;
	private $pageIDs = [];
	private $pageItems = [];
	private $targetPageIDs = [];
	private $targetPageItems = [];
	private $hideNamespacePrefix;
	// @todo:
	private $hideNamespacePrefixUnlessResultsMixed;
	private $namespaceIndexes;

	public function __construct(
		$usePageImages = false,
		$useDisplayTitle = true,
		$substring = "",
		$consolidateRedirects = false,
		$hideNamespacePrefix = null
	) {
		$this->usePageImages = $usePageImages;
		$this->useDisplayTitle = $useDisplayTitle;
		$this->substring = $substring;
		$this->consolidateRedirects = $consolidateRedirects;
		$this->hideNamespacePrefix = ( $hideNamespacePrefix !== null )
			? $hideNamespacePrefix
			: true;
	}

	/**
	 * Format a search result
	 * 
	 * @param mixed $pageName
	 * @param mixed $displayTitle
	 * @param string $substring
	 * @return array
	 */
    public function formatResultItem(
		mixed $pageName,
		mixed $displayTitle = null,
		string $substring = ""
	) {
		// Get display title, page ID, etc.
		$title = Title::newFromText( $pageName );
		if ( !$title->canExist() ) {
			return [];
		}
		$pageID = $title->getArticleID();

		if ( $this->useDisplayTitle && $displayTitle !== null ) {
			$name = $displayTitle;
		} elseif( $this->useDisplayTitle ) {
			$name = ReconUtils::getDisplayTitle(
				$title,
				$pageID,
				$this->hideNamespacePrefix ? $pageName : $title->getPrefixedText()
			);
		} else {
			$name = $this->hideNamespacePrefix ? $pageName : $title->getPrefixedText();
		}
		$namespaceIndex = $title->getNamespace();

		$redirects = [];
		if ( $title->isRedirect() ) {
			$isRedirect = 1;
			//$redirectStore = MediaWikiServices::getService()->getRedirectStore();
			//$mainWikiPage = $redirectStore->getRedirectTarget( $title );
			$wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
			$mainTitle = $wikiPageFactory->newFromTitle( $title )->getRedirectTarget();
			$mainPageName = $mainTitle->getPrefixedText();

			// @todo - for now but need to strategise
			$pageName = $mainPageName; // go straight to page
			$displayTitle = "{$displayTitle} → $mainPageName";
			$namespaceIndex = $mainTitle->getNamespace();
		} else {
			$isRedirect = 0;
			//$namespaceIndex = $title->getNamespace();
		}

		// Get image thumbnail with Page Images extension
		// @todo More testing!
		$thumb = $this->getThumb( $title, $pageName, $namespaceIndex );

		// @todo Actually required only for Reconcile API, not Suggest Entity
		[ $isFullMatch, $isLowerCaseMatch, $score ] = APIReconQueryHandler::getRelevancyDataForCandidate( $substring, $pageName, $displayTitle );

		// @todo
		$type = MWCategoryUtils::getCategoriesFromTitle( $title, "idname" );

		$res = [
			ApiResult::META_BC_BOOLS => [ "match" ],
			"id" => $pageName,
			"name" => $name,
			//"description" => false,
			"thumbnail" => $thumb,
			"match" => $isFullMatch,
			"score" => $score,
			"type" => $type,
			// "notable" => [],
			"other" => [
				"pageid" => $pageID,
				"ns" => $namespaceIndex,
				"highlighted" => StringModifier::createHighlightedString( $displayTitle, $substring ),
				"isRedirect" => $isRedirect
			]
		];
		return $res;
	}

	/**
	 * Variation on MWPageQueryBuilder::formatNamespaceResults()
	 * @todo Maybe add highlighted, per the above.
	 */
	public function formatResults( $res, $namespaceNames ) {
		if ( !$res ) {
			return [];
		}
		$pages = $sortkeys = [];
		$redirects = [];
		while ( $row = $res->fetchRow() ) {

			if ( array_key_exists( "page_namespace", $row ) ) {
				$title = Title::newFromText( $row["page_title"], $row["page_namespace"] );
				$pagename = $title->getText();
				$fullpagename = $title->getPrefixedText();
			} else {
				$title = Title::newFromText( $row["page_title"] );
				$pagename = str_replace( "_", " ", $row["page_title"] );
				$fullpagename = $pagename;
			}

			if ( $this->consolidateRedirects && $row["page_is_redirect"] === 1 ) {
				// We handle redirect pages at a later stage
				$redirects[] = [ $title, $fullpagename ];
				continue;
			} else {
				$this->pageIDs[] = $row["page_id"];
			}

			// Visible label
			if ( $this->useDisplayTitle
				&& array_key_exists( "pp_displaytitle_value", $row ) 
				&& ( $row["pp_displaytitle_value"] ) !== null
				&& trim( str_replace( "&#160;", "", strip_tags( $row["pp_displaytitle_value"] ) ) ) !== ""
			) {
				$name = htmlspecialchars_decode( $row["pp_displaytitle_value"], ENT_QUOTES );
			} else {
				$name = $this->hideNamespacePrefix ? $pagename : $fullpagename;
			}

			$thumb = $this->getThumb( $title, $pagename, $row["page_namespace"] );

			list( $isFullMatch, $isLowerCaseMatch, $score ) = APIReconQueryHandler::getRelevancyDataForCandidate( $this->substring, $pagename, $name );

			$sortableName = $row["pp_defaultsort_value"] ?? $name;
			$sortableKey = strip_tags( strtolower($sortableName) );
			$types = MWCategoryUtils::getCategoriesFromTitle( $title, "idname" );

			$pageItem = [
				ApiResult::META_BC_BOOLS => [ "match" ],
				"id" => $fullpagename,
				"name" => $name,
				// "description"
				"thumbnail" => $thumb,
				"match" => $isFullMatch,
				"score" => $score,
				"type" => $types,
				"other" => [
					"pageid" => $row["page_id"],
					"ns" => $row["page_namespace"],
					"pagename" => $pagename,
					"isRedirect" => intval( $row["page_is_redirect"] ),
					"defaultsort" => $sortableName
				]
			];
			if ( !$this->consolidateRedirects && $row["page_is_redirect"] === 1 ) {
				// @todo language
				$pageItem["description"] = "Redirect";
			};
			$this->pageItems[$sortableKey] = $pageItem;
		}
		$res->free();

		// Now handle redirects
		foreach( $redirects as $redirect ) {
			// populate $this->targetPageItems
			$this->createTargetItemForRedirect( $redirect[0], $redirect[1] );
		}

		// Now merge redirect target pages with $pages array
		if ( !empty( $this->targetPageItems ) ) {
			$res = array_merge( $this->pageItems, $this->targetPageItems );
		} else {
			$res = $this->pageItems;
		}

		ksort( $res, SORT_NATURAL );
		$res = array_values( $res );
		return $res;
	}

	/**
	 * Create target items to be stored as class variables
	 * Helper method for formatResults()
	 * Avoids duplicates if target is already included
	 * 
	 * @param \Title $title - Title of the redirect page
	 * @param string $name - full name of the redirect page
	 * @return void
	 */
	private function createTargetItemForRedirect( Title $title, string $name ) {
		$wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
		// target
		$targetTitle = $wikiPageFactory->newFromTitle( $title )->getRedirectTarget();		
		$targetPageID = $targetTitle->getId();

		// Check if target page is already included
		if( in_array( $targetPageID, $this->pageIDs ) ) {
			// target page already included among non-redirect pages
			foreach( $this->pageItems as $k => $pageItem ) {
				if ( $pageItem["other"]["pageid"] == $targetPageID ) {
					$this->pageItems[$k]["other"]["hasMatchingRedirect"][] = $name;
				}
			}
			return;
		} elseif ( in_array( $targetPageID, $this->targetPageIDs ) ) {
			// target page already included among target pages			
			foreach( $this->targetPageIDs as $k => $pageItem ) {
				if ( $pageItem["other"]["pageid"] == $targetPageID ) {
					$this->pageItems[$k]["other"]["hasMatchingRedirect"][] = $name;
				}
			}
			return;
		} else {
			$this->targetPageIDs[] = $targetPageID;
		}
		
		$targetPageName = $targetTitle->getPrefixedText();
		$targetNamespace = $targetTitle->getNamespace();		
		$targetName = $targetPageName;
		if ( $this->useDisplayTitle ) {
			$targetName = ReconUtils::getDisplayTitle( $targetTitle, $targetPageID, $targetPageName );
			$targetName = html_entity_decode( $targetName );
		} else {
			$targetName = $targetPageName;
		}

		$thumb = $this->getThumb( $targetTitle, $targetPageName, $targetPageName );

		// @todo - names of redirect pages may provide better matches
		// but are we really to understand them as alternative labels?
		// Sometimes a redirect is created to correct an error...
		list( $isFullMatch, $isLowerCaseMatch, $score ) = APIReconQueryHandler::getRelevancyDataForCandidate( $this->substring, $targetPageName, $targetName );

		$sortableKey = strip_tags( strtolower($targetName) );

		$this->targetPageItems[$sortableKey] = [
			ApiResult::META_BC_BOOLS => [ "match" ],
			"id" => $targetPageName,
			"name" => $targetName,
			"match" => $isFullMatch,
			"score" => $score,
			"type" => [],
			"other" => [
				"pageid" => $targetPageID,
				"ns" => $targetNamespace,
				"isRedirect" => 0,
				"hasMatchingRedirect" => [ $name ]
			]
		];
		return;
	}

	/**
	 * 
	 */
	private function getThumb( $title, $pageName, $namespaceIndex ) {
		if ( $this->usePageImages ) {
			$extPageImageHandler = new ExtPageImages();
			$thumb = $extPageImageHandler->getImage( $pageName, 50 );
		} elseif( $namespaceIndex == 6 ) {
			// 6 = NS_FILE
			$thumb = ReconUtils::getImageThumbnailUrlFromTitle( $title, 50 );
		} else {
			$thumb = false;
		}
		return $thumb;
	}

}
