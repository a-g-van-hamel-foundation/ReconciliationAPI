<?php

/**
 * Class for formatting a QueryResult in the appropriate output.
 */

namespace Recon\SMW;

use \ApiResult;
use \Title;
use \SMW\Query\QueryResult;
use Recon\ReconUtils;
use Recon\MW\MWUtils;
use Recon\MW\MWNamespaceUtils;
use Recon\StringModification\StringModifier;
use Recon\MW\ExtPageImages;
use Recon\API\APIReconQueryHandler;

class SMWResultFormatter {

	private $queryResult;
	private $substring;
	private $printoutProperties = [];
	private $beforeOperatorclassProperty;
	private $classProperty = null;
	private $labelProperty = null;
	private $labelPropertyDataType;
	private $stripLabel = false;
	private $descriptionProperty = null;
	private $stripDescription = false;
	private $imageProperty = null;
	// @todo - set through profile; false by default:
	private $usePageImages = false;
	private $formattedResult = [];
	// Can be changed to "type" for the Suggest Type service
	private $outputFormat = "entity";
	private $localCategoryName;
	private $localConceptName;
	private $localClassName;

	// properties holding 'broader' types
	public $smwBroaderClassProp = null;
	public $smwBroaderConceptProp = null;

	// Options
	private $hideNamespacePrefix;
	private $stripTags;

	public function __construct(
		QueryResult $queryResult,
		$substring = null,
		$hideNamespacePrefix = false
	) {
		$this->queryResult = $queryResult;
		$this->substring = $substring;
		// default:
		$this->hideNamespacePrefix = $hideNamespacePrefix;
		// Localised names of namespaces according to content language:
		$this->localConceptName = MWUtils::getNamespaceNameFromIndex( SMW_NS_CONCEPT );
		$this->localCategoryName = MWUtils::getNamespaceNameFromIndex( NS_CATEGORY );
		// @todo make configurable
		$this->localClassName = "Class";
	}

	/**
	 * Set which printout properties to include in our results
	 * for common uses cases: label, description, image
	 * To be called prior to doFormat() if required.
	 * @todo move $stripTags to setOptions
	 * @return void
	 */
	public function setPrintoutProperties(
		mixed $labelProperty = null,
		mixed $descriptionProperty = false,
		mixed $imageProperty = false,
		bool $stripTags = false,
		mixed $classProperty = false
	) {
		if ( $classProperty ) {
			$this->printoutProperties[] = $this->classProperty = $classProperty;
		}
		if ( $labelProperty !== null ) {
			$this->printoutProperties[] = $this->labelProperty = $labelProperty;
			$this->labelPropertyDataType = SMWUtils::getDataTypeOfProperty( $labelProperty );
		}
		if ( $descriptionProperty ) {
			$this->printoutProperties[] = $this->descriptionProperty = $descriptionProperty;
		}
		if ( $imageProperty ) {
			$this->printoutProperties[] = $this->imageProperty = $imageProperty;
		}
		$this->stripLabel = $stripTags;
		$this->stripDescription = $stripTags;
	}

	/**
	 * Add custom properties
	 * To be called before doFormat().
	 * @return void
	 */
	public function addPrintoutProperties( $props ): void {
		foreach( $props as $prop ) {
			$this->printoutProperties[] = $prop;
		}
	}

	/**
	 * Change the default output format ("entity").
	 * Allowed values: "entity", "type"
	 * To be called before doFormat().
	 */
	public function setOutputFormat( $outputFormat ): void {
		$this->outputFormat = $outputFormat;
		if( $outputFormat == "type" ) {
			$this->hideNamespacePrefix = true;
		}
	}

	public function setOptions( $outputFormat = null, $hideNamespacePrefix = null, $stripTags = null ) {
		if ( $hideNamespacePrefix !== null ) {
			$this->hideNamespacePrefix = $hideNamespacePrefix;
		}
		if ( $outputFormat !== null ) {
			$this->outputFormat = $outputFormat;
			$this->hideNamespacePrefix = true;
		}
		if ( $stripTags !== null ) {
			// ???
			$this->stripTags = $stripTags;
		}
	}

	/**
	 * @deprecated use setPrintoutProperties
	 * @return void
	 */
	public function setPrintoutPropertyForLabel( $labelProperty, $stripTags = false ) {
		$this->labelProperty = $labelProperty;
		$this->stripLabel = $stripTags;
	}

	/**
	 * @deprecated use setPrintoutProperties
	 * @return void
	 */
	public function setPrintoutPropertyForDescription( $descriptionProperty, $stripTags = false, $maxChars = 50 ) {
		$this->descriptionProperty = $descriptionProperty;
		$this->stripDescription = $stripTags;
	}

	/**
	 * @deprecated use setPrintoutProperties
	 * @return void
	 */
	public function setPrintoutPropertyForImage( $imageProperty ) {
		// @todo check if property is valid
		$this->imageProperty = ( $imageProperty !== null ) ? $imageProperty : null;
	}

	/**
	 * Format the query result given parameters set.
	 * @return array|null
	 */
	public function doFormat() {
		if ( $this->queryResult->getErrors() !== [] ) {
			// Fail silently for now
			return [];
		}
		$this->formattedResult = $this->formatResults( $this->queryResult->toArray() );
		return $this->formattedResult;
	}

	/**
	 * Get unformatted result as an array
	 * @return mixed
	 */
	public function getRawResult() {
		if ( $this->queryResult->getErrors() !== [] ) {
			return [];
		}
		return $this->queryResult->toArray();
	}

	/**
	 * Format a query result.
	 * 
	 * @param array $queryResult
	 * @return array
	 */
	private function formatResults( array $queryResult ) {
		$res = [];
		foreach ( $queryResult["results"] as $subjectName => $subject ) {
			$fullPageName = $subject["fulltext"];
			$pageID = MWUtils::getPageIDFromPagename( $fullPageName );

			$displayTitle = $this->labelProperty == false
				? $fullPageName
				: $subject["displaytitle"] ?? $fullPageName;
			$printouts = $subject["printouts"] ?? [];

			// defaults
			$description = $thumb = false;
			$types = $categories = $broaderTypes = [];

			foreach ( $printouts as $k => $printout ) {
				if ( $k == null ) {
					// class property may be null
					continue;
				}
				switch( $k ) {
					case $this->labelProperty;
						// @todo possibly preprocess
						$label = $printout[0] ?? $displayTitle;
						$label = $this->stripLabel ? StringModifier::stripTags( $label ) : $label;
					break;
					case $this->descriptionProperty;
						$description = $printout[0] ?? false;
						if ( $description !== false ) {
							$description = $this->stripDescription ? StringModifier::stripTags( $description ) : $description;
						}
					break;
					case $this->imageProperty;
						// Use first image only
						$image = $printout[0] ?? [];
						if ( isset( $image["fulltext"] ) ) {
							// Property of type Page
							$thumb = ( $image["exists"] == 1 )
								? ReconUtils::getImageThumbnailUrl( $image["fulltext"], 50, false )
								: false;
						} elseif( $image ) {
							$thumb = ReconUtils::getImageThumbnailUrl( $image, 50, false );
						} else {
							$thumb = false;
						}
						// @todo possibly preprocess, first image only
					break;
					case "Category":
						foreach( $printout as $cat ) {
							if ( isset( $cat["fulltext"] ) ) {
								$name = $cat["displaytitle"] !== "" ? $cat["displaytitle"] : ReconUtils::removeNamespacePrefixFromNames( [ $cat["fulltext"] ] )[0];
								$types[] = $categories[] = [
									"id" => $cat["fulltext"],
									"name" => $name //@todo
								];
							}
						}						
					break;
					case $this->classProperty:
						foreach( $printout as $class ) {
							if ( isset( $class["fulltext"] ) ) {
								$name = $class["displaytitle"] !== "" ? $class["displaytitle"] : ReconUtils::removeNamespacePrefixFromNames( [ $class["fulltext"] ] )[0];
								$types[] = [
									"id" => $class["fulltext"],
									"name" => $name //@todo
								];
							}
						}
					break;
				}
				$broaderTypes = array_merge( $broaderTypes, $this->getBroaderTypesForFormattedResult( $k, $printout ) );
			}

			// Additional, non-SMW ways to get associated image
			if ( !$thumb && $this->usePageImages ) {
				$extPageImageHandler = new ExtPageImages();
				$thumb = $extPageImageHandler->getImage( $fullPageName, 50 );
			}
			if ( !$thumb && $subject["namespace"] == NS_FILE ) {
				$title = Title::newFromText( $fullPageName );
				$thumb = ReconUtils::getImageThumbnailUrlFromTitle( $title, 50 );
			}

			if ( $label || $displayTitle ) {
				$name = $label ?? $displayTitle;
			} elseif ( $this->hideNamespacePrefix ) {
				$namespaceName = MWUtils::getNamespaceNameFromIndex( $subject["namespace"] );
				$name = str_starts_with( $fullPageName, $namespaceName )
					? str_replace( "{$namespaceName}:", "",  $fullPageName )
					: $fullPageName;
			}else {
				$name = $fullPageName;
			}

			if ( $this->outputFormat == "type" ) {
				// Presentation of types can benefit from disambiguation
				// @todo content language
				if ( $subject["namespace"] == NS_CATEGORY ) {
					$name .= " ($this->localCategoryName)";
				} elseif ( $subject["namespace"] == SMW_NS_CONCEPT ) {
					$name .= " ({$this->localConceptName})";
				} else {
					$name .= " ($this->localClassName})"; 
				}
			}

			$resItem = [
				"id" => $fullPageName,
				"name" => $name			
			];
			if ( $this->outputFormat == "type" ) {
				$resItem["broader"] = $broaderTypes;
			} else {
				if ( $description !== false ) {
					$resItem["description"] = $description;
				}
				if ( $thumb !== false ) {
					$resItem["thumbnail"] = $thumb;
				}

				$mwNamespaceUtils = new MWNamespaceUtils();
				$namespaceName = $mwNamespaceUtils->getNamespaceNameFromIndex( $subject["namespace"] );
				list( $isFullMatch, $isLowerCaseMatch, $score ) = APIReconQueryHandler::getRelevancyDataForCandidate( $this->substring, $fullPageName, $name, $namespaceName );
				$resItem[ApiResult::META_BC_BOOLS] = [ "match" ];
				$resItem["match"] = $isFullMatch;
				$resItem["score"] = $score;
				$resItem["type"] = $types;
			}
			$resItem["other"] = [
				//"highlighted" => StringModifier::createHighlightedString( $label ?? $displayTitle, $this->substring ),
				"namespace" => $subject["namespace"],
				"pageid" => $pageID,
				"exists" => $subject["exists"] ? 1 : 0,
				"fullurl" => $subject["fullurl"]
			];
			$res[] = $resItem;
		}
		return $res;
	}

	/**
	 * Helper function for formatResults()
	 * 
	 * @param mixed $k
	 * @param mixed $printout
	 * @return array
	 */
	private function getBroaderTypesForFormattedResult( $key, $printout ) {
		if ( $key == null ) {
			return [];
		}
		$broaderTypes = [];
		switch( $key ) {
			case $this->smwBroaderClassProp:
			case $this->smwBroaderConceptProp:
			case "Subcategory of":
				foreach( $printout as $type ) {
					$label = isset( $type["displaytitle"] ) && $type["displaytitle"] !== "" ? $type["displaytitle"] : $type["fulltext"];
					$broaderTypes[] = [
						"id" => $type["fulltext"],
						"name" => $label
					];
				}
			break;
		}
		return $broaderTypes;
	}

}
