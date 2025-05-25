<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\MediaWikiServices;
use Title;

/**
 * API module for unified search across multiple sources
 */
class ApiUnifiedSearch extends ApiBase
{
    public function execute()
    {
        $params = $this->extractRequestParams();
        $query = $params['query'];
        $limit = $params['limit'];
        $sources = explode('|', $params['sources']);

        wfDebugLog('supportSystem', 'Search request: query=' . $query . ', sources=' . implode(',', $sources));

        $results = [];

        if (in_array('opensearch', $sources)) {
            try {
                $results['opensearch'] = $this->searchOpenSearch($query, $limit);
                wfDebugLog('supportSystem', 'OpenSearch returned ' . count($results['opensearch']) . ' results');
            } catch (\Exception $e) {
                wfDebugLog('supportSystem', 'OpenSearch error: ' . $e->getMessage());
                // Не прерываем выполнение, продолжаем с другими источниками
                $results['opensearch'] = [];
            }
        }

        if (in_array('mediawiki', $sources)) {
            try {
                $results['mediawiki'] = $this->searchMediaWiki($query, $limit);
                wfDebugLog('supportSystem', 'MediaWiki search returned ' . count($results['mediawiki']) . ' results');
            } catch (\Exception $e) {
                wfDebugLog('supportSystem', 'MediaWiki search error: ' . $e->getMessage());
                $results['mediawiki'] = [];
            }
        }

        $this->getResult()->addValue(null, 'results', $results);
    }

    /**
     * Search OpenSearch with improved error handling
     */
    private function searchOpenSearch($query, $limit)
    {
        try {
            $config = MediaWikiServices::getInstance()->getMainConfig();
            $host = $config->get('SupportSystemOpenSearchHost');
            $port = $config->get('SupportSystemOpenSearchPort');
            $index = $config->get('SupportSystemOpenSearchIndex');

            if (empty($host) || empty($port) || empty($index)) {
                wfDebugLog('supportSystem', 'OpenSearch configuration is incomplete');
                return [];
            }

            // Исправляем URL для работы из контейнера
            $url = "http://{$host}:{$port}/{$index}/_search";
            wfDebugLog('supportSystem', "OpenSearch URL: $url");

            // Простой поисковый запрос
            $searchQuery = [
                'query' => [
                    'multi_match' => [
                        'query' => $query,
                        'fields' => ['title^3', 'content^2', 'tags'],
                        'type' => 'best_fields',
                        'operator' => 'or',
                        'fuzziness' => 'AUTO'
                    ]
                ],
                'highlight' => [
                    'pre_tags' => ['<strong>'],
                    'post_tags' => ['</strong>'],
                    'fields' => [
                        'content' => [
                            'fragment_size' => 150,
                            'number_of_fragments' => 2
                        ],
                        'title' => new \stdClass()
                    ]
                ],
                'size' => $limit
            ];

            $jsonData = json_encode($searchQuery);

            // Используем file_get_contents вместо curl для упрощения
            $options = [
                'http' => [
                    'header' => "Content-Type: application/json\r\n",
                    'method' => 'POST',
                    'content' => $jsonData,
                    'timeout' => 10.0
                ]
            ];

            $context = stream_context_create($options);
            $response = @file_get_contents($url, false, $context);

            if ($response === false) {
                $error = error_get_last();
                wfDebugLog('supportSystem', 'OpenSearch connection failed: ' . ($error['message'] ?? 'Unknown error'));
                return [];
            }

            $data = json_decode($response, true);
            if (!$data || isset($data['error'])) {
                $errorMsg = isset($data['error']) ? json_encode($data['error']) : 'Invalid response';
                wfDebugLog('supportSystem', 'OpenSearch error: ' . $errorMsg);
                return [];
            }

            $results = [];
            if (isset($data['hits']['hits'])) {
                foreach ($data['hits']['hits'] as $hit) {
                    $source = $hit['_source'] ?? [];

                    $result = [
                        'id' => $source['id'] ?? $hit['_id'],
                        'title' => $source['title'] ?? 'Без названия',
                        'content' => $source['content'] ?? '',
                        'score' => $hit['_score'] ?? 1.0,
                        'source' => 'opensearch',
                        'url' => $source['url'] ?? ''
                    ];

                    // Добавляем подсветку
                    if (isset($hit['highlight'])) {
                        if (isset($hit['highlight']['content'])) {
                            $result['highlight'] = implode(' ... ', $hit['highlight']['content']);
                        } elseif (isset($hit['highlight']['title'])) {
                            $result['highlight'] = $hit['highlight']['title'][0];
                        }
                    }

                    if (isset($source['tags'])) {
                        $result['tags'] = $source['tags'];
                    }

                    $results[] = $result;
                }
            }

            return $results;
        } catch (\Exception $e) {
            wfDebugLog('supportSystem', 'OpenSearch exception: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Search MediaWiki with simplified logic
     */
    private function searchMediaWiki($query, $limit)
    {
        try {
            $searchResults = [];

            // Используем встроенный поиск MediaWiki
            $searchEngine = MediaWikiServices::getInstance()->getSearchEngineFactory()->create();
            $searchEngine->setNamespaces([NS_MAIN]);
            $searchEngine->setLimitOffset($limit);

            // Поиск по заголовкам
            $titleMatches = $searchEngine->searchTitle($query);
            if ($titleMatches && $titleMatches->numRows() > 0) {
                foreach ($titleMatches as $match) {
                    if (!$match)
                        continue;

                    $title = $match->getTitle();
                    if (!$title || !$title->exists())
                        continue;

                    $searchResults[] = [
                        'id' => $title->getArticleID(),
                        'title' => $title->getText(),
                        'content' => $this->getPageExcerpt($title->getArticleID()),
                        'score' => $match->getScore() ?: 1.0,
                        'source' => 'mediawiki',
                        'url' => $title->getFullURL()
                    ];
                }
            }

            // Поиск по тексту
            $textMatches = $searchEngine->searchText($query);
            if ($textMatches && $textMatches->numRows() > 0) {
                $existingIds = array_column($searchResults, 'id');

                foreach ($textMatches as $match) {
                    if (!$match)
                        continue;

                    $title = $match->getTitle();
                    if (!$title || !$title->exists())
                        continue;

                    $id = $title->getArticleID();
                    if (in_array($id, $existingIds))
                        continue;

                    $searchResults[] = [
                        'id' => $id,
                        'title' => $title->getText(),
                        'content' => $match->getTextSnippet() ?: $this->getPageExcerpt($id),
                        'score' => $match->getScore() ?: 0.5,
                        'source' => 'mediawiki',
                        'url' => $title->getFullURL()
                    ];
                }
            }

            return $searchResults;
        } catch (\Exception $e) {
            wfDebugLog('supportSystem', 'MediaWiki search error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get page excerpt
     */
    private function getPageExcerpt($pageId)
    {
        try {
            $page = \WikiPage::newFromID($pageId);
            if (!$page)
                return '';

            $content = $page->getContent();
            if (!$content)
                return '';

            $text = $content->getTextForSummary(300);
            return $text ?: '';
        } catch (\Exception $e) {
            return '';
        }
    }

    public function getAllowedParams()
    {
        return [
            'query' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_REQUIRED => true,
            ],
            'sources' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_DFLT => 'opensearch|mediawiki',
            ],
            'limit' => [
                ApiBase::PARAM_TYPE => 'integer',
                ApiBase::PARAM_DFLT => 10,
                ApiBase::PARAM_MIN => 1,
                ApiBase::PARAM_MAX => 50,
            ],
        ];
    }
}