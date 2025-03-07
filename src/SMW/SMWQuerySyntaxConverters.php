<?php

namespace Recon\SMW;

use MediaWiki\MediaWikiServices;
use Recon\Localisation\ReconLocalisation;

class SMWQuerySyntaxConverters {

	/**
	 * Translate property value pairs used for Suggest Property
	 * service to appropriate SMW syntax
	 * @param array $types
	 * @return string
	 */
	public static function translatePropValPairsToSMWSyntax( array $pairs ) {
		$qStrings = [];
		foreach( $pairs as $pair ) {
			// @todo check if $pair["pid"] and $pair["v"] are set
			$qStr = "";
			switch( $pair["pid"] ) {
				// @todo localisation
				case "category":
				case "Category":
					$method = "Category";
					//@todo
					$nameComponents = explode( ":", $pair["v"] );
					$qStr = $nameComponents[0] == "Category" ? "[[{$pair["v"]}]]" : "[[Category:{$pair["v"]}]]";
				break;
				case "concept":
				case "Concept":
					$method = "SMWConcept";
					//@todo SMW Concept
					$nameComponents = explode( ":", $pair["v"] );
					$qStr = $nameComponents[0] == "Concept" ? "[[{$pair["v"]}]]" : "[[Concept:{$pair["v"]}]]";
				break;
				case "namespace":
					$method = "Namespace";
					//@todo SMW Concept
					$qStr = "[[{$pair["v"]}:+]]";
				break;
				default:
					$method = "SMWProperty";
					$nameComponents = explode( ":", $pair["pid"] );
					// @todo localisation
					$propertyName = ( $nameComponents[0] == "Property" ) ? $nameComponents[1] : $pair["pid"];
					$qStr = "[[{$propertyName}::{$pair["v"]}]]";
			}
			print_r( $qStr );
			$qStrings[] = $qStr;
		}
		return implode( " ", $qStrings );
	}

	/**
	 * Translate 'types' to the appropriate SMW syntax.
	 * Types can be categories, concepts and 'class pages'
	 * Unlike method above, does not cater for namespaces.
	 * @param array $types
	 * @return string
	 */
	public static function translateTypesToSMWSyntax( array $types ) {
		$qStrings = [];
		foreach( $types as $type ) {
			$nameComponents = explode( ":", $type );
			// Get namespace prefixes in English and the local site language
			$specialNamespaces = [ "Category", "Concept" ];
			foreach( $specialNamespaces as $n ) {
				$localNamespaceName = ReconLocalisation::getNamespaceName( $n );
				if ( !in_array( $localNamespaceName, $specialNamespaces ) ) {
					$specialNamespaces[] = $localNamespaceName;
				}
			}
			if ( count($nameComponents) > 1 && in_array( $nameComponents[0], $specialNamespaces) ) {
				// e.g. [[Concept:Foo]] or [[Category:Bar]]
				$qStrings[] = "[[$type]]";
			} else {
				// else assuming a page represents a class
				// e.g. [[Class::vehicles]]
				$classProp = MediaWikiServices::getInstance()->getMainConfig()->get( 'ReconAPIClassProp' );
				if ( $classProp !== null && $classProp !== false ) {
					$qStrings[] = "[[{$classProp}::{$type}]]";
				}
			}
		}
		$res = implode( " ", $qStrings );
		return $res;
	}

}
