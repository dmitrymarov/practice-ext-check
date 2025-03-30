<?php

namespace MediaWiki\Extension\SupportSystem;

use OutputPage;
use Skin;

/**
 * Hooks for SupportSystem extension
 */
class Hooks
{
	/**
	 * Handler for BeforePageDisplay hook
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public static function onBeforePageDisplay(OutputPage $out, Skin $skin): void
	{
		// Add global styles
		$out->addModuleStyles('ext.supportSystem.styles');

		// Check if we're on a support system special page
		$title = $out->getTitle();

		if ($title->isSpecial('UnifiedSupport')) {
			// Load the unified interface module
			$out->addModules('ext.supportSystem.unified');

			// Make sure we load all the messages needed for this page
			$out->addJsConfigVars([
				'supportsystemConfig' => [
					'messages' => [
						'error_loading_node' => wfMessage('supportsystem-dt-error-loading-node')->text(),
						'error_creating_ticket' => wfMessage('supportsystem-dt-error-creating-ticket')->text(),
						'ai_error' => wfMessage('supportsystem-dt-ai-error')->text(),
						'ai_loading' => wfMessage('supportsystem-dt-ai-loading')->text(),
						'search_loading' => wfMessage('supportsystem-search-loading')->text(),
						'search_empty' => wfMessage('supportsystem-search-empty-query')->text(),
						'search_error' => wfMessage('supportsystem-search-error')->text(),
						'ticket_created' => wfMessage('supportsystem-dt-ticket-created')->text(),
					]
				]
			]);
		} elseif ($title->isSpecial('DecisionGraphAdmin')) {
			// Load the graph administration module
			$out->addModules('ext.supportSystem.graphAdmin');
		}
	}
}