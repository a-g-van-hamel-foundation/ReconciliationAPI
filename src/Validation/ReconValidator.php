<?php

/**
 * JSON validator using https://github.com/jsonrainbow/json-schema
 * Entry point: validateProfile()
 */

namespace Recon\Validation;

use \JsonSchema\SchemaStorage;
use \JsonSchema\Validator;
use \JsonSchema\Constraints\Factory;
use \JsonSchema\Constraints\Constraint;
use Recon\ReconUtils;

class ReconValidator {

	private $validator = null;
	private $profileSchema = null;
	// Reconciliation API schemas:
	private $schemaDirectory = "/src/Validation/schemas-0.2";
	private $doesValidate;

	public function __construct() {
		// $data = json_decode(file_get_contents( 'profile.json' ));
		$this->validator = new Validator;
		$path = ReconUtils::getExtensionPath();
		$this->profileSchema = $path . "/src/Validation/profile.json";
	}

	public function validateProfile( $profileObj ) {
		$profileSchemaObj = json_decode( file_get_contents( $this->profileSchema ), false );
		$this->validator->validate( $profileObj, $profileSchemaObj );
		$message = $this->errorHandler( $this->validator );
		return $message;
	}

	/**
	 * Returns validation message
	 * 
	 * @param mixed $validator
	 * @return string
	 */
	private function errorHandler( $validator ) {
		if ( $validator->isValid() ) {
			$this->doesValidate = true;
			$str = "The supplied JSON validates against the schema.";
		} else {
			$this->doesValidate = false;
			$str = "JSON does not validate. Violations:\n";
			foreach ( $validator->getErrors() as $error ) {
				// printf("[%s] %s\n", $error['property'], $error['message']);
				$str .= "<div><code>{$error['property']}</code> - {$error['message']}</div>";
			}
		}
		return $str;
	}

}
