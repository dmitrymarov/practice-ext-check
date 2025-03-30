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
		if ($title->isSpecial('DecisionTree')) {
			$out->addModules('ext.supportSystem.decisionTree');
			// Make sure we load all the messages needed for this page
			$out->addJsConfigVars([
				'dtMessages' => [
					'ai_error' => wfMessage('supportsystem-dt-ai-error')->text(),
					'ai_loading' => wfMessage('supportsystem-dt-ai-loading')->text(),
					'create_ticket' => wfMessage('supportsystem-dt-create-ticket')->text(),
					'ai_search' => wfMessage('supportsystem-dt-ai-search')->text(),
					'ticket_header' => wfMessage('supportsystem-dt-ticket-header')->text(),
					'error_loading_node' => wfMessage('supportsystem-dt-error-loading-node')->text(),
					'error_creating_ticket' => wfMessage('supportsystem-dt-error-creating-ticket')->text(),
					'ticket_created' => wfMessage('supportsystem-dt-ticket-created')->text(),
				]
			]);
		} elseif ($title->isSpecial('SearchSolutions')) {
			$out->addModules('ext.supportSystem.search');
		} elseif ($title->isSpecial('ServiceDesk')) {
			$out->addModules('ext.supportSystem.serviceDesk');
		} elseif ($title->isSpecial('DecisionGraphAdmin')) {
			$out->addModules('ext.supportSystem.graphAdmin');
		}
	}
}