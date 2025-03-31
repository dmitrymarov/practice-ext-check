<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\Extension\SupportSystem\SearchModule;
use ApiUsageException;
use Exception;

/**
 * API module for searching solutions
 */
class ApiSupportSearch extends ApiBase
{
    /**
     * Execute the API module
     */
    public function execute()
    {
        try {
            $params = $this->extractRequestParams();
            $query = $params['query'];
            $sources = $params['sources'];
            $useAi = $params['use_ai'];
            $context = $params['context'] ? json_decode($params['context'], true) : [];

            $searchModule = new SearchModule();
            $results = [];

            // Подробное логирование для отладки
            wfDebugLog('SupportSystem', "API Search request: Query=$query, Sources=" . implode(',', $sources) . ", UseAI=" . ($useAi ? 'true' : 'false'));

            // Regular search
            if (!$useAi) {
                // Search in OpenSearch if enabled
                if (in_array('opensearch', $sources)) {
                    try {
                        $opensearchResults = $searchModule->search($query);
                        $results = array_merge($results, $opensearchResults);
                        wfDebugLog('SupportSystem', "OpenSearch returned " . count($opensearchResults) . " results");
                    } catch (Exception $e) {
                        wfDebugLog('SupportSystem', "OpenSearch error: " . $e->getMessage());
                        // Продолжаем выполнение даже при ошибке OpenSearch
                    }
                }

                // Search in MediaWiki if enabled
                if (in_array('mediawiki', $sources)) {
                    try {
                        $mediawikiResults = $searchModule->searchMediaWiki($query);
                        $results = array_merge($results, $mediawikiResults);
                        wfDebugLog('SupportSystem', "MediaWiki search returned " . count($mediawikiResults) . " results");
                    } catch (Exception $e) {
                        wfDebugLog('SupportSystem', "MediaWiki search error: " . $e->getMessage());
                        // Продолжаем выполнение даже при ошибке поиска MediaWiki
                    }
                }

                // Sort by score
                usort($results, function ($a, $b) {
                    return $b['score'] <=> $a['score'];
                });

                $this->getResult()->addValue(null, 'results', $results);
                // Добавляем информацию для отладки
                $this->getResult()->addValue(null, 'debuginfo', [
                    'query' => $query,
                    'sources' => $sources,
                    'resultCount' => count($results),
                    'mwVersion' => MW_VERSION,
                    'phpEngine' => 'PHP',
                    'phpVersion' => PHP_VERSION,
                    'time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
                    'log' => ['Search completed successfully']
                ]);
            } else {
                // AI search
                // Get user ID for tracking context
                $userId = null;
                $user = $this->getUser();
                if ($user && !$user->isAnon()) {
                    $userId = 'user_' . $user->getId();
                } else {
                    // For anonymous users, use session ID if available
                    $session = $this->getRequest()->getSession();
                    if ($session) {
                        $userId = 'anon_' . $session->getId();
                    }
                }

                try {
                    $aiResult = $searchModule->searchAI($query, $context, $userId);
                    $this->getResult()->addValue(null, 'ai_result', $aiResult);

                    // Добавляем информацию для отладки
                    $this->getResult()->addValue(null, 'debuginfo', [
                        'query' => $query,
                        'context' => $context,
                        'userId' => $userId,
                        'mwVersion' => MW_VERSION,
                        'phpEngine' => 'PHP',
                        'phpVersion' => PHP_VERSION,
                        'time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
                        'log' => ['AI search completed successfully']
                    ]);
                } catch (Exception $e) {
                    wfDebugLog('SupportSystem', "AI search error: " . $e->getMessage());

                    // Возвращаем объект ошибки вместо выбрасывания исключения
                    $this->getResult()->addValue(null, 'ai_result', [
                        'answer' => 'К сожалению, произошла ошибка при выполнении поиска. Пожалуйста, попробуйте позже.',
                        'sources' => [],
                        'success' => false
                    ]);

                    // Добавляем информацию для отладки
                    $this->getResult()->addValue(null, 'debuginfo', [
                        'error' => $e->getMessage(),
                        'mwVersion' => MW_VERSION,
                        'phpEngine' => 'PHP',
                        'phpVersion' => PHP_VERSION,
                        'time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
                        'log' => ['AI search encountered an error']
                    ]);
                }
            }
        } catch (Exception $e) {
            wfDebugLog('SupportSystem', "API Search exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());

            // Возвращаем структурированный ответ вместо исключения
            $this->getResult()->addValue(null, 'error', [
                'code' => 'search_error',
                'info' => $e->getMessage()
            ]);

            // Добавляем пустые результаты для совместимости
            if (!$useAi) {
                $this->getResult()->addValue(null, 'results', []);
            } else {
                $this->getResult()->addValue(null, 'ai_result', [
                    'answer' => 'К сожалению, произошла ошибка при выполнении поиска. Пожалуйста, попробуйте позже.',
                    'sources' => [],
                    'success' => false
                ]);
            }

            // Добавляем информацию для отладки
            $this->getResult()->addValue(null, 'debuginfo', [
                'error' => $e->getMessage(),
                'mwVersion' => MW_VERSION,
                'phpEngine' => 'PHP',
                'phpVersion' => PHP_VERSION,
                'time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
                'log' => ['API Search encountered an error']
            ]);
        }
    }

    /**
     * Get allowed parameters
     * @return array
     */
    public function getAllowedParams()
    {
        return [
            'query' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_REQUIRED => true,
            ],
            'sources' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_ISMULTI => true,
                ApiBase::PARAM_DFLT => 'opensearch|mediawiki',
            ],
            'use_ai' => [
                ApiBase::PARAM_TYPE => 'boolean',
                ApiBase::PARAM_DFLT => false,
            ],
            'context' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_REQUIRED => false,
            ],
        ];
    }

    /**
     * Examples for the API documentation
     * @return array
     */
    public function getExamplesMessages()
    {
        return [
            'action=supportsearch&query=wifi' => 'apihelp-supportsearch-example-1',
            'action=supportsearch&query=wifi&sources=opensearch' => 'apihelp-supportsearch-example-2',
            'action=supportsearch&query=wifi&use_ai=1' => 'apihelp-supportsearch-example-3',
        ];
    }
}