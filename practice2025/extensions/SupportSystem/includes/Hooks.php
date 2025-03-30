<?php

namespace MediaWiki\Extension\SupportSystem;

use OutputPage;
use Skin;

/**
 * Hooks for SupportSystem extension
 */
class Hooks {
	/**
	 * Handler for BeforePageDisplay hook
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ): void {
		// Add global styles
		$out->addModuleStyles( 'ext.supportSystem.styles' );
		
		// Check if we're on a support system special page
		$title = $out->getTitle();
		if ( $title->isSpecial( 'DecisionTree' ) ) {
			$out->addModules( 'ext.supportSystem.decisionTree' );
		} elseif ( $title->isSpecial( 'SearchSolutions' ) ) {
			$out->addModules( 'ext.supportSystem.search' );
		} elseif ( $title->isSpecial( 'ServiceDesk' ) ) {
			$out->addModules( 'ext.supportSystem.serviceDesk' );
		}
	}
}