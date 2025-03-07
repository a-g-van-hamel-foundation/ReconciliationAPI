<?php

namespace Recon\Config;

class ReconJsonContent extends \JsonContent {

	public const CONTENT_MODEL_ID = 'reconjson';
	public const MODEL = 'reconjson';

	public function __construct( $text, $modelId = CONTENT_MODEL_RECON_JSON ) {
		parent::__construct( $text, $modelId );
	}

}
