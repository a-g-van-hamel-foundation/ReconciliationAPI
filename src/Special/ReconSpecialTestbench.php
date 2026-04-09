<?php

namespace Recon\Special;

use MediaWiki\SpecialPage\SpecialPage;
//use MediaWiki\Registration\ExtensionRegistry;

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
		// @todo add check for availability of SMW
		$res = "<div class='recon-testbench'></div>";
		$output->addModules( [ 'ext.recon.testbench' ] );
		$output->addWikiTextAsContent( $res );
	}

}
