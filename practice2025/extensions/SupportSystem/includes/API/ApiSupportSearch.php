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
            $searchMethods = [];

            // Определяем методы поиска на основе параметров
            if (in_array('opensearch', $sources)) {
                $searchMethods[] = 'opensearch';
            }

            if (in_array('mediawiki', $sources)) {
                $searchMethods[] = 'cirrus';
            }

            if ($useAi) {
                $searchMethods[] = 'ai';
            }

            // Подробное логирование для отладки
            wfDebugLog('SupportSystem', "API Search request: Query=$query, Methods=" . implode(',', $searchMethods));

            // Get user ID for tracking context if using AI
            $userId = null;
            if ($useAi) {
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
            }

            // Выполняем комплексный поиск
            $searchResult = $searchModule->comprehensiveSearch($query, $searchMethods, $context, $userId);

            // Добавляем результаты поиска в ответ API
            $this->getResult()->addValue(null, 'results', $searchResult['results']);

            // Если был запрошен AI поиск и есть AI результат
            if ($useAi) {
                // Ищем AI-результат среди результатов поиска
                foreach ($searchResult['results'] as $result) {
                    if ($result['source'] === 'ai' && isset($result['ai_result'])) {
                        $this->getResult()->addValue(null, 'ai_result', $result['ai_result']);
                        break;
                    }
                }
            }

            // Добавляем отладочную информацию
            $this->getResult()->addValue(null, 'debuginfo', [
                'query' => $query,
                'searchMethods' => $searchMethods,
                'resultCount' => count($searchResult['results']),
                'searchTime' => $searchResult['debug']['totalTime'],
                'mwVersion' => MW_VERSION,
                'phpVersion' => PHP_VERSION
            ]);

        } catch (Exception $e) {
            wfDebugLog('SupportSystem', "API Search exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());

            // Возвращаем структурированный ответ вместо исключения
            $this->getResult()->addValue(null, 'error', [
                'code' => 'search_error',
                'info' => $e->getMessage()
            ]);

            // Добавляем пустые результаты для совместимости
            $this->getResult()->addValue(null, 'results', []);

            if ($useAi) {
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
                'phpVersion' => PHP_VERSION
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