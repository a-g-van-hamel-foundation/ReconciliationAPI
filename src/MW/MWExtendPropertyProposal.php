<?php

/**
 * MW implementation of the Data Extension Property Proposal service.
 * Returns properties for a given 'type' identifier.
 * In the core MediaWiki context, (pseudo-)properties that can be used 
 * with any 'type' are categories and namespaces.
 * @todo formalisation of namespaces
 * @link https://www.w3.org/community/reports/reconciliation/CG-FINAL-specs-0.2-20230410/#data-extension-property-proposals
 */

namespace Recon\MW;

use Recon\MW\MWUtils;

class MWExtendPropertyProposal {

	public function __construct() {}

	public function run( $class ) {
		$categoryName = MWUtils::getNamespaceNameFromIndex( NS_CATEGORY );
		$results = [
			[
				"id" => "category",
				"name" => $categoryName
			],
			[
				"id" => "namespace",
				"name" => "namespace"
			]
		];
		$res = [
			"type" => $class,
			"properties" => $results,
			"meta" => [
				"service" => "Implementation of the Data Extension Property Proposal service based on core MediaWiki functionality."
			]
		];
		return $res;
	}

}
