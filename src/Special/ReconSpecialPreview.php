<?php

/**
 * Special page which outputs an embeddable HTML page to provide a preview
 * service for the Reconciliation API.
 * @todo MW core: eventually support suggestion by PageImages extension
 * @todo use Mustache instead
 * 
 * with generic profile
 * @example Special:ReconPreview/generic/id/234234
 * @example Special:ReconPreview/generic/page/Táin_bó_Cúailnge
 * 
 * with config profile id:
 * @example Special:ReconPreview/69915/id/12215
 * @example Special:ReconPreview/69915/page/M_(allograph)
 * Special:ReconPreview/69915/id/7397
 * 
 * @link https://www.w3.org/community/reports/reconciliation/CG-FINAL-specs-0.2-20230410/#preview-service
 * Cf. https://github.com/wikimedia/mediawiki-extensions-PageForms/blob/3c76deca51fea45dafc15320e07d3eb419332b0b/specials/PF_UploadWindow.php
 */

namespace Recon\Special;

use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use Recon\ReconUtils;
use Recon\SMW\SMWUtils;
use Recon\SMW\SMWQueryBuilder;
use Recon\SMW\SMWResultFormatter;
use Recon\Config\ReconConfig;

class ReconSpecialPreview extends \SpecialPage {

	private $profileID;
	// "smw", "mw":
	private $source;
	// pagename
	private $id;
	private $name = false;
	private $description = false;
	private $thumbnail = false;
	private $labelProperty;
	private $descriptionProperty;
	private $imageProperty;

	/** @var WebRequest|FauxRequest The request this form is supposed to handle */
	public $mRequest;
	
	public function __construct( $request = null ) {
		$pageName = "ReconPreview";
		parent::__construct( $pageName, "recon-preview", true );
		// $this->loadRequest( $request instanceof WebRequest ? $request : $this->getRequest() );
		//$mainConfig = $this->config;
		$config = $this->getConfig();
		$this->labelProperty = $config->get( "ReconAPILabelProp" ) ?? false;
		$this->descriptionProperty = $config->get( "ReconAPIDescriptionProp" ) ?? false;
		$this->imageProperty = $config->get( "ReconAPIThumbnailProp" ) ?? false;
	}

	/**
	 * Initialise instance variables from WebRequest and create preview 
	 *
	 * @param WebRequest $request The request to extract variables from
	 */
	//protected function loadRequest( WebRequest $request ) {
		// 
	//}

	/**
	 * Special page entry point
	 * @param string|null $par
	 */
	public function execute( $par ) {

		// Only output the body of the page.
		$output = $this->getOutput();
		$output->setArticleBodyOnly( true );
		if ( !$par ) {
			// Nothing to show
			return;
		}

		// This line is needed to get around Squid caching.
		$output->sendCacheControl();
		$this->setHeaders();
		$this->outputHeader();

		$urlParts = explode( "/", $par );

		if ( isset( $urlParts[0] ) && is_numeric( $urlParts[0] ) ) {
			// Using profile ID
			$this->profileID = $urlParts[0];
			$profile = new ReconConfig( $this->profileID );
			$this->source = $profile->getSource();
			if ( $this->source == "smw" ) {
				$props = $profile->getMappingPropertyInfo();
				$this->labelProperty = $props["label"] ?? false;
				$this->descriptionProperty = $props["description"] ?? false;
				$this->imageProperty = $props["image"] ?? false;
			}
		} else {
			// Using 'generic' profile
			$this->profileID = null;
			// @todo Is SMW a valid default if it is installed
			$this->source = SMWUtils::isSMWStoreAvailable() ? "smw" : "mw";
		}

		if ( isset( $urlParts[1] ) && $urlParts[1] == "id" && isset( $urlParts[2] ) ) {
			$title = \Title::newFromID( $urlParts[2] );
			if ( $title == false ) {
				$output->addHTML( "<div>No such page found</div>" );
				return;
			}
			$this->name = ReconUtils::getDisplayTitleElsePageName( $title, $urlParts[2], "" );
		} elseif( isset( $urlParts[1] ) && $urlParts[1] == "page" ) {
			$title = \Title::newFromText( $urlParts[2] );
			if ( $title == false ) {
				$output->addHTML( "<div>No such page found</div>" );
				return;
			}
			$this->name = ReconUtils::getDisplayTitleElsePageName( $title, null, "" );
		}

		$this->id = $title->getPrefixedText();
		$fullUrl = $title->getFullURL();
	
		if ( $this->source = "smw" ) {
			$pageRes = $this->getSMWResultForTitle();
			if ( isset( $pageRes[0] ) ) {
				$this->id = $pageRes[0]["id"];
				$this->name = $pageRes[0]["name"];
				$this->description = $pageRes[0]["description"];
				$this->thumbnail = $pageRes[0]["thumbnail"];
				//print_r( $pageRes[0] );
			}
		} else {
			// mw
		}

		// Frames to be allowed in X-Frame-Options
		$output->preventClickjacking( false );

		$res = $this->getHtmlSample( $this->id, $this->name, $this->description, $this->thumbnail, $fullUrl );
		$output->addHTML( $res );
	}

	public function getSMWResultForTitle() {

		$queryBuilder = new SMWQueryBuilder();
		
		$queryBuilder->setPrintoutProperties( $this->labelProperty, $this->descriptionProperty, $this->imageProperty );
		
		$rawQuery = "[[{$this->id}]]";
		$smwQueryRes = $queryBuilder->getResultForQuery( $rawQuery );

		$smwResultFormatter = new SMWResultFormatter( $smwQueryRes, null );
		[ $labelProp, $descriptionProp, $thumbnailProp ] = $queryBuilder->getPrintoutProperties();
		$smwResultFormatter->setPrintoutProperties( $labelProp, $descriptionProp, $thumbnailProp, true );
		$pages = $smwResultFormatter->doFormat();

		return $pages;
	}


	/**
	 * Checks if page has image associated with the wiki page.
	 * Profile needs to give clues how to get it. 
	 * UNUSED
	 * @return void
	 */
	private static function getImage() {
		//
	}

	private static function createThumbnail( \File $img, $width, $height = -1 ): string {
		$thumb = $img->createThumb( $width, $height );
		return $thumb;
	}

	/**
	 * 
	 * @param string $id
	 * @param mixed $name
	 * @param mixed $description
	 * @param mixed $thumbnail
	 * @return mixed
	 */
	public function getHtmlSample(
		string $id,
		$name = "Unknown entity",
		$description = false,
		$thumbnail = false,
		$fullUrl = null
	) {
		$title = html_entity_decode( $name );
		$htmlLabel = $fullUrl == null
			? "<div><strong>$title</strong></div>"
			: "<div><strong><a href='$fullUrl' target='_blank'>{$title}</a></strong></div>";

		$descriptionParagraph = $description ? "<p style='display: -webkit-box; text-overflow: ellipsis; overflow: hidden; line-clamp: 4; -webkit-line-clamp: 4; -webkit-box-orient: vertical;'>{$description}</p>" : "";
		if ( isset( $thumbnail["url"] ) ) {
			$src = $thumbnail["url"];
			$thumbnailWidth = $thumbnail["width"];
			$thumbnailHeight = $thumbnail["height"];			
		} else {
			$src = ReconUtils::getSiteLogo();
			$thumbnailWidth = 50;
			$thumbnailHeight = "auto";
		}

		$str = <<<WIKI
		<div style='display:flex; align-items:center; justify-content:center; width:100%; max-width:100px; overflow: hidden;'>
		<img src='{$src}' width='{$thumbnailWidth}px' height='$thumbnailHeight' >
		</div>
		<div style='display:flex; justify-content:center; flex-direction:column; margin-left:5px; width:100%;'>
		{$htmlLabel}
		{$descriptionParagraph}
		</div>
		WIKI;
		$res = \Html::rawElement(
			"div",
			[
				"style" => "display:flex; height: 100px; width: 320px; overflow: hidden; font-size: 0.8rem;"
			],
			$str
		);
		return $res;
	}

}