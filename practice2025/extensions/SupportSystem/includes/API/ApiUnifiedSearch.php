<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\Extension\SupportSystem\AISearchBridge;
use MediaWiki\Extension\SupportSystem\SearchModule;
use Exception;

/**
 * Единый API-модуль для всех типов поиска (OpenSearch, CirrusSearch, AI Search)
 */
class ApiUnifiedSearch extends ApiBase
{
    public function execute()
    {
        try {
            $params = $this->extractRequestParams();
            $query = $params['query'];
            $useAI = $params['use_ai'];
            $context = $params['context'] ? json_decode($params['context'], true) : [];
            $limit = $params['limit'];
            $sources = $params['sources'];
            $searchMethods = [];
            if (in_array('opensearch', $sources)) {
                $searchMethods[] = 'opensearch';
            }
            if (in_array('mediawiki', $sources)) {
                $searchMethods[] = 'cirrus';
            }
            $results = [
                'cirrus' => [],
                'opensearch' => [],
                'ai' => null
            ];
            $userId = null;
            if ($useAI) {
                $user = $this->getUser();
                if ($user && !$user->isAnon()) {
                    $userId = 'user_' . $user->getId();
                } else {
                    $session = $this->getRequest()->getSession();
                    if ($session) {
                        $userId = 'anon_' . $session->getId();
                    }
                }
            }
            $searchModule = new SearchModule();
            $searchResult = $searchModule->comprehensiveSearch($query, $searchMethods, $context, $userId);
            foreach ($searchResult['results'] as $result) {
                if ($result['source'] === 'cirrus') {
                    $results['cirrus'][] = $result;
                } elseif ($result['source'] === 'opensearch') {
                    $results['opensearch'][] = $result;
                }
            }
            if ($useAI) {
                try {
                    $aiBridge = new AISearchBridge();
                    $results['ai'] = $aiBridge->search($query, $context, $userId);
                } catch (Exception $e) {
                    $this->addWarning('ai_search_error', $e->getMessage());
                    $results['ai'] = [
                        'answer' => 'Произошла ошибка при выполнении интеллектуального поиска. Пожалуйста, попробуйте снова позже.',
                        'sources' => [],
                        'success' => false
                    ];
                }
            }
            $debugInfo = [
                'query' => $query,
                'searchMethods' => $searchMethods,
                'resultCount' => [
                    'cirrus' => count($results['cirrus']),
                    'opensearch' => count($results['opensearch']),
                    'ai' => $useAI ? 1 : 0
                ],
                'searchTime' => $searchResult['debug']['totalTime'] ?? 0
            ];
            $this->getResult()->addValue(null, 'results', $results);
            $this->getResult()->addValue(null, 'debug', $debugInfo);

        } catch (Exception $e) {
            $this->getResult()->addValue(null, 'error', [
                'code' => 'search_error',
                'info' => $e->getMessage()
            ]);
            $this->getResult()->addValue(null, 'results', [
                'cirrus' => [],
                'opensearch' => [],
                'ai' => null
            ]);
        }
    }
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
            'sources' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_ISMULTI => true,
                ApiBase::PARAM_DFLT => 'opensearch|mediawiki',
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
    public function getExamplesMessages()
    {
        return [
            'action=unifiedsearch&query=wifi' => 'apihelp-unifiedsearch-example-1',
            'action=unifiedsearch&query=wifi&use_ai=1' => 'apihelp-unifiedsearch-example-2',
            'action=unifiedsearch&query=wifi&sources=opensearch' => 'apihelp-unifiedsearch-example-3',
        ];
    }
}