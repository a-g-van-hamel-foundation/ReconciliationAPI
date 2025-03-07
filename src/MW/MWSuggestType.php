<?php

/**
 * In the basic MediaWiki context, a 'type' is understood
 * to translate to a MW Category.
 * @todo $substringPattern, $useDisplayTitle
 */

namespace Recon\MW;

use Recon\MW\MWSuggestEntity;
use Recon\MW\MWCategoryUtils;
use Recon\Localisation\ReconLocalisation;

class MWSuggestType {

	private $substring = null;
	private $substringPattern = "stringprefix";
	private $useDisplayTitle = false;
	private $parentCategory = null;
	private $offset = 0;
	private $limit = 25;
	private $categoryNSNameLocal;

	public function __construct() {
		$this->categoryNSNameLocal = ReconLocalisation::getNamespaceName( NS_CATEGORY );
	}

	public function run(
		string $substring = null,
		mixed $substringPattern = "tokenprefix",
		mixed $useDisplayTitle = null,
		mixed $parentCategory = null,
		$offset = 0,
		$limit = 25
	) {
		$mwSuggestEntity = new MWSuggestEntity();
		$this->substring = $substring;
		$this->substringPattern = $substringPattern;
		$this->parentCategory = $parentCategory;
		$this->offset = $offset;
		$this->limit = $limit;

		if ( $this->parentCategory && $this->parentCategory !== "" ) {
			// search for subcategories
			$topCategories = explode( ";", $this->parentCategory );
			// @todo make depth configurable?
			$categoryDepth = 5;
			$cats = MWCategoryUtils::getSubCategories( $this->parentCategory, $categoryDepth, $this->substring, $this->substringPattern, $this->offset, $this->limit );
			$catRes = $cats !== false ? $this->formatCategoryResults( $cats ) : [];
		} else {
			// Get all categories
			$topCategories = false;
			$categoryDepth = false;
			$cats = MWCategoryUtils::getAllCategories( $this->substring, $this->substringPattern, $this->offset, $this->limit );
			$catRes = $this->formatCategoryResults( $cats );
			// should not be necessary here
			$catRes = array_slice( $catRes, $this->offset, $this->limit );
		}

		$res = [
			"result" => $catRes,
			"meta" => [
				"source" => "mw",
				"substring" => $this->substring,
				"substringPattern" => $this->substringPattern,				
				"topCategories" => $topCategories,
				"categoryDepth" => $categoryDepth,
				"useDisplayTitle" => $this->useDisplayTitle ? 1 : 0,
				"resultBatchCount" => count( $catRes ),
				"resultOffset" => $this->offset,
				"resultLimit" => $this->limit
			]
		];
		return $res;
	}

	private function formatCategoryResults( $cats ) {
		$res = [];
		foreach( $cats as $cat ) {
			$title = \Title::newFromText( $cat["name"], NS_CATEGORY );
			$types = [];
			foreach( $title->getParentCategories() as $k => $v ) {
				$k = str_replace( "_", " ", $k );
				$types[] = [
					"id" => $k,
					"name" => $k
				];
			}
			$res[] = [
				"id" => "{$this->categoryNSNameLocal}:" . $cat["name"],
				"name" => $cat["name"],
				"description" => $cat["description"] ?? false,
				"broader" => $types,
				"other" => $cat["other"]
			];
		}
		return $res;
	}

}
