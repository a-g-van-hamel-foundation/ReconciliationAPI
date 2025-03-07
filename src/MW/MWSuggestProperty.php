<?php

/**
 * Suggest a 'property' for the Reconciliation service.
 * In the context of MediaWiki core, a property is understood
 * simply to refer to either a category or namespace.
 */

namespace Recon\MW;

class MWSuggestProperty {

	public function __construct() {
		//
	}

	public function run( $substring ) {		
		$pseudoProps = $this->findPseudoProperties( $substring );
		return [
			"result" => $pseudoProps,
			// Not suggested by API:
			"meta" => [
				"service" => "Suggest MW properties",
				"description" => "Suggest MediaWiki 'properties', i.e. category or namespace, whose names match on '{$substring}' or return both.",
				"source" => "mw",
				"substring" => $substring
			]
		];
	}

	/**
	 * Summary of findPseudoProperties
	 * @param mixed $substring
	 * @return array
	 */
	private function findPseudoProperties( $substring = "" ) {
		$vals = [ "category", "namespace" ];
		// @todo localisation
		// ReconLocalisation::getNamespaceName( )
		if ( $substring !== "" ) {
			$matches = [];
			foreach ( $vals as $val ) {
				if ( str_contains( $val, trim($substring) ) ) {
					$matches[] = $val;
				}
			}
		} else {
			$matches = $vals;
		}
		
		$res = [];
		foreach ( $matches as $match ) {
			$res[] = [
				"id" => "$match",
				"name" => $match,
				"description" => $match
			];
		}
		return $res;
	}

}
