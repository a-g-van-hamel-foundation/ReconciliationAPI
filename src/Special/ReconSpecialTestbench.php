<?php

namespace Recon\Special;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;
use Recon\SMW\SMWUtils;

class ReconSpecialTestbench extends SpecialPage {

	public function __construct( $name = 'ReconTestbench' ) {
		parent::__construct( $name );
	}

	function isExpensive() {
		return false;
	}

	function isSyndicated() {
		return false;
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$output = $this->getContext()->getOutput();
		$output->setPageTitle( "Reconciliation API: Testbench" );
		$res = Html::rawElement(
			"div",
			[
				"class" => "recon-testbench",
				"data-smw" => SMWUtils::isSMWStoreAvailable(),
				"data-fts" => SMWUtils::isFTSEnabled()
			]
		);
		$output->addModules( [ 'ext.recon.testbench' ] );
		$output->addWikiTextAsContent( $res );
	}

}
