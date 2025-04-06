<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\MediaWikiServices;
use MWException;

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
            $results = [
                'cirrus' => [],
                'opensearch' => [],
                'ai' => null
            ];
            if (in_array('mediawiki', $sources)) {
                try {
                    $searchEngineFactory = MediaWikiServices::getInstance()->getSearchEngineFactory();
                    $searchEngine = $searchEngineFactory->create();
                    if (
                        class_exists('CirrusSearch\CirrusSearch') &&
                        ($searchEngine instanceof \CirrusSearch\CirrusSearch ||
                            is_subclass_of($searchEngine, 'CirrusSearch\CirrusSearch'))
                    ) {
                        $matches = $searchEngine->searchText($query, ['limit' => $limit]);
                        if ($matches) {
                            foreach ($matches as $match) {
                                $title = $match->getTitle();
                                $snippet = $match->getTextSnippet();
                                $results['cirrus'][] = [
                                    'id' => $title->getArticleID(),
                                    'title' => $title->getText(),
                                    'content' => $snippet,
                                    'score' => $match->getScore(),
                                    'source' => 'mediawiki',
                                    'url' => $title->getFullURL()
                                ];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $this->addWarning('cirrus_search_error', $e->getMessage());
                }
            }
            if (in_array('opensearch', $sources)) {
                try {
                    $config = MediaWikiServices::getInstance()->getMainConfig();
                    $host = $config->get('SupportSystemOpenSearchHost');
                    $port = $config->get('SupportSystemOpenSearchPort');
                    $indexName = $config->get('SupportSystemOpenSearchIndex');
                    $requestData = [
                        'query' => [
                            'multi_match' => [
                                'query' => $query,
                                'fields' => ['title^2', 'content', 'tags^1.5'],
                                'type' => 'best_fields'
                            ]
                        ],
                        'highlight' => [
                            'fields' => [
                                'content' => new \stdClass()
                            ]
                        ],
                        'size' => $limit
                    ];
                    $jsonData = json_encode($requestData);
                    $url = "http://{$host}:{$port}/{$indexName}/_search";
                    $command = "curl -s -X POST -H \"Content-Type: application/json\" " .
                        "-d " . escapeshellarg($jsonData) . " \"$url\"";
                    $response = shell_exec($command);
                    if ($response !== false) {
                        $data = json_decode($response, true);

                        if (isset($data['hits']['hits'])) {
                            foreach ($data['hits']['hits'] as $hit) {
                                $source = $hit['_source'];
                                $result = [
                                    'id' => $source['id'] ?? $hit['_id'],
                                    'title' => $source['title'] ?? 'Untitled',
                                    'content' => $source['content'] ?? '',
                                    'score' => $hit['_score'],
                                    'source' => 'opensearch',
                                    'url' => $source['url'] ?? ''
                                ];

                                if (isset($hit['highlight']['content'])) {
                                    $result['highlight'] = $hit['highlight']['content'][0];
                                }

                                if (isset($source['tags'])) {
                                    $result['tags'] = $source['tags'];
                                }

                                $results['opensearch'][] = $result;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $this->addWarning('opensearch_error', $e->getMessage());
                }
            }
            if ($useAI) {
                try {
                    $config = MediaWikiServices::getInstance()->getMainConfig();
                    $aiServiceUrl = $config->get('SupportSystemAIServiceURL');
                    $userId = null;
                    $user = $this->getUser();
                    if ($user && !$user->isAnon()) {
                        $userId = 'user_' . $user->getId();
                    } else {
                        $session = $this->getRequest()->getSession();
                        if ($session) {
                            $userId = 'anon_' . $session->getId();
                        }
                    }
                    $requestData = [
                        'query' => $query,
                        'context' => $context
                    ];
                    if ($userId) {
                        $requestData['user_id'] = $userId;
                    }
                    $jsonData = json_encode($requestData);
                    $url = rtrim($aiServiceUrl, '/') . "/api/search_ai";
                    $command = "curl -s -X POST -H \"Content-Type: application/json\" " .
                        "-d " . escapeshellarg($jsonData) . " \"$url\"";
                    $response = shell_exec($command);
                    if ($response !== false) {
                        $data = json_decode($response, true);
                        $results['ai'] = [
                            'answer' => $data['answer'] ?? 'Ответ не получен',
                            'sources' => $data['sources'] ?? [],
                            'success' => $data['success'] ?? false
                        ];
                    }
                } catch (\Exception $e) {
                    $this->addWarning('ai_search_error', $e->getMessage());
                    $results['ai'] = [
                        'answer' => 'Произошла ошибка при выполнении поиска',
                        'sources' => [],
                        'success' => false
                    ];
                }
            }
            $this->getResult()->addValue(null, 'results', $results);

        } catch (\Exception $e) {
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