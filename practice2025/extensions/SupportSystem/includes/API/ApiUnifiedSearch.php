<?php
// Файл: extensions/SupportSystem/includes/API/ApiUnifiedSearch.php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use CirrusSearch\CirrusSearch;
use MediaWiki\Extension\SupportSystem\AISearchBridge;
use SearchEngineFactory;
use MediaWiki\MediaWikiServices;

/**
 * API-модуль для объединенного поиска (CirrusSearch + AI Search)
 */
class ApiUnifiedSearch extends ApiBase
{
    /**
     * Выполнение API-запроса
     */
    public function execute()
    {
        // Получение параметров
        $params = $this->extractRequestParams();
        $query = $params['query'];
        $useAI = $params['use_ai'];
        $context = $params['context'] ? json_decode($params['context'], true) : [];
        $limit = $params['limit'];

        // Подготовка результатов
        $results = [
            'cirrus' => [],
            'ai' => null
        ];

        // Выполнение поиска в CirrusSearch
        try {
            $searchEngineFactory = MediaWikiServices::getInstance()->getSearchEngineFactory();
            $searchEngine = $searchEngineFactory->create();

            // Проверка, что используется CirrusSearch
            if ($searchEngine instanceof CirrusSearch) {
                $matches = $searchEngine->searchText($query, ['limit' => $limit]);

                if ($matches) {
                    foreach ($matches as $match) {
                        $title = $match->getTitle();
                        $snippet = $match->getTextSnippet();

                        $results['cirrus'][] = [
                            'id' => $title->getArticleID(),
                            'title' => $title->getText(),
                            'snippet' => $snippet,
                            'url' => $title->getFullURL(),
                            'score' => $match->getScore()
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            $this->addWarning('cirrus_search_error', $e->getMessage());
        }

        // Выполнение AI-поиска, если требуется
        if ($useAI) {
            try {
                $userId = null;
                $user = $this->getUser();
                if ($user && !$user->isAnon()) {
                    $userId = 'user_' . $user->getId();
                }

                $aiBridge = new AISearchBridge();
                $results['ai'] = $aiBridge->search($query, $context, $userId);
            } catch (\Exception $e) {
                $this->addWarning('ai_search_error', $e->getMessage());
            }
        }

        // Возвращаем результаты
        $this->getResult()->addValue(null, 'results', $results);
    }

    /**
     * Получение разрешенных параметров
     */
    public function getAllowedParams()
    {
        return [
            'query' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_REQUIRED => true,
            ],
            'use_ai' => [
                ApiBase::PARAM_TYPE => 'boolean',
                ApiBase::PARAM_DFLT => false,
            ],
            'context' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_REQUIRED => false,
            ],
            'limit' => [
                ApiBase::PARAM_TYPE => 'integer',
                ApiBase::PARAM_DFLT => 10,
                ApiBase::PARAM_MIN => 1,
                ApiBase::PARAM_MAX => 50,
            ],
        ];
    }

    /**
     * Примеры для документации API
     */
    public function getExamplesMessages()
    {
        return [
            'action=unifiedsearch&query=wifi' => 'apihelp-unifiedsearch-example-1',
            'action=unifiedsearch&query=wifi&use_ai=1' => 'apihelp-unifiedsearch-example-2',
        ];
    }
}