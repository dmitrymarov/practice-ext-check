<?php

namespace MediaWiki\Extension\SupportSystem;

use OutputPage;
use Skin;

class Hooks
{
	/**
	 * Обработчик хука BeforePageDisplay
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public static function onBeforePageDisplay(OutputPage $out, Skin $skin): void
	{
		$out->addModuleStyles('ext.supportSystem.styles');
		$title = $out->getTitle();
		if ($title->isSpecial('UnifiedSupport')) {
			$out->addModules('ext.supportSystem.unified');
			$out->addJsConfigVars([
				'supportsystemConfig' => [
					'messages' => [
						'error_loading_node' => $out->msg('supportsystem-dt-error-loading-node')->text(),
						'error_creating_ticket' => $out->msg('supportsystem-dt-error-creating-ticket')->text(),
						'ai_error' => $out->msg('supportsystem-dt-ai-error')->text(),
						'ai_loading' => $out->msg('supportsystem-dt-ai-loading')->text(),
						'search_loading' => $out->msg('supportsystem-search-loading')->text(),
						'search_empty' => $out->msg('supportsystem-search-empty-query')->text(),
						'search_error' => $out->msg('supportsystem-search-error')->text(),
						'ticket_created' => $out->msg('supportsystem-dt-ticket-created')->text(),
					]
				]
			]);
		} elseif ($title->isSpecial('DecisionGraphAdmin')) { $out->addModules('ext.supportSystem.graphAdmin'); }
	}
}