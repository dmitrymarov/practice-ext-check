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
        try {
            $params = $this->extractRequestParams();
            $query = $params['query'];
            $limit = $params['limit'];
            $sources = explode('|', $params['sources']);

            $results = [];

            if (in_array('opensearch', $sources)) {
                try {
                    $results['opensearch'] = $this->searchOpenSearch($query, $limit);
                } catch (\Exception $e) {
                    wfDebugLog('supportSystem', 'OpenSearch error: ' . $e->getMessage());
                    $results['opensearch'] = [];
                }
            }

            if (in_array('mediawiki', $sources)) {
                try {
                    // Используем базовый поиск MediaWiki
                    $results['mediawiki'] = $this->searchMediaWiki($query, $limit);
                } catch (\Exception $e) {
                    wfDebugLog('supportSystem', 'MediaWiki search error: ' . $e->getMessage());
                    $results['mediawiki'] = [];
                }
            }

            $this->getResult()->addValue(null, 'results', $results);

        } catch (\Exception $e) {
            wfDebugLog('supportSystem', 'API error: ' . $e->getMessage());
            $this->getResult()->addValue(null, 'error', [
                'code' => 'search_error',
                'info' => $e->getMessage()
            ]);
            $this->getResult()->addValue(null, 'results', []);
        }
    }

    /**
     * Search MediaWiki using the search engine
     * 
     * @param string $query Search query
     * @param int $limit Maximum number of results
     * @return array Search results
     */
    private function searchMediaWiki($query, $limit)
    {
        $searchResults = [];

        try {
            $searchEngineFactory = MediaWikiServices::getInstance()->getSearchEngineFactory();
            $searchEngine = $searchEngineFactory->create();
            $searchEngine->setNamespaces([NS_MAIN]); // Ограничиваем основным пространством имен
            $searchEngine->setLimitOffset($limit);

            // Поиск по заголовкам
            $titleResults = $searchEngine->searchTitle($query);
            // Поиск по тексту
            $textResults = $searchEngine->searchText($query);

            $seenIds = [];

            // Обработка результатов поиска по заголовкам
            if ($titleResults && $titleResults->numRows() > 0) {
                foreach ($titleResults as $result) {
                    if (!$result)
                        continue;

                    $title = $result->getTitle();
                    if (!$title || !$title->exists())
                        continue;

                    $id = $title->getArticleID();
                    if (!$id || isset($seenIds[$id]))
                        continue;

                    $seenIds[$id] = true;
                    $searchResults[] = $this->formatSearchResult($result, $title, $id, 1.5);
                }
            }

            // Обработка результатов поиска по тексту
            if ($textResults && $textResults->numRows() > 0) {
                foreach ($textResults as $result) {
                    if (!$result)
                        continue;

                    $title = $result->getTitle();
                    if (!$title || !$title->exists())
                        continue;

                    $id = $title->getArticleID();
                    if (!$id || isset($seenIds[$id]))
                        continue;

                    $seenIds[$id] = true;
                    $searchResults[] = $this->formatSearchResult($result, $title, $id, 1.0);
                }
            }

            // Если нет результатов, попробуем использовать прямой поиск по базе данных
            if (empty($searchResults)) {
                $searchResults = $this->fallbackSearch($query, $limit);
            }

        } catch (\Exception $e) {
            wfDebugLog('supportSystem', 'Search error: ' . $e->getMessage());
        }

        return $searchResults;
    }

    /**
     * Format search result
     * 
     * @param \SearchResult $result Search result
     * @param Title $title Title object
     * @param int $id Page ID
     * @param float $scoreMultiplier Score multiplier
     * @return array Formatted result
     */
    private function formatSearchResult($result, $title, $id, $scoreMultiplier = 1.0)
    {
        $snippet = '';
        try {
            $snippet = $result->getTextSnippet();
        } catch (\Exception $e) {
            $snippet = '';
        }

        if (empty($snippet)) {
            $snippet = $this->getPageExcerpt($id);
        }

        return [
            'id' => $id,
            'title' => $title->getText(),
            'content' => $snippet ?: 'Описание недоступно',
            'score' => ($result->getScore() ?: 1.0) * $scoreMultiplier,
            'source' => 'mediawiki',
            'url' => $title->getFullURL()
        ];
    }

    /**
     * Fallback search using page titles and content
     * 
     * @param string $query Search query
     * @param int $limit Maximum number of results
     * @return array Search results
     */
    private function fallbackSearch($query, $limit)
    {
        $searchResults = [];
        $dbr = wfGetDB(DB_REPLICA);
        $queryTerms = preg_split('/\s+/', $query);
        $likeConditions = [];

        foreach ($queryTerms as $term) {
            if (strlen($term) < 3)
                continue;
            $escapedTerm = $dbr->escapeLike($term);
            $likeConditions[] = "page_title LIKE '%" . $escapedTerm . "%'";
        }
        if (empty($likeConditions)) {
            return [];
        }
        $conditions = 'page_namespace = ' . NS_MAIN . ' AND (' . implode(' OR ', $likeConditions) . ')';
        $res = $dbr->select(
            'page',
            ['page_id', 'page_title', 'page_namespace'],
            $conditions,
            __METHOD__,
            ['LIMIT' => $limit, 'ORDER BY' => 'page_title']
        );

        foreach ($res as $row) {
            $title = Title::newFromRow($row);
            if (!$title || !$title->exists())
                continue;

            $excerpt = $this->getPageExcerpt($row->page_id);
            $contentMatch = false;
            if ($excerpt) {
                foreach ($queryTerms as $term) {
                    if (strlen($term) < 3)
                        continue;
                    if (stripos($excerpt, $term) !== false) {
                        $contentMatch = true;
                        break;
                    }
                }
            }
            if ($contentMatch) {
                $searchResults[] = [
                    'id' => $row->page_id,
                    'title' => $title->getText(),
                    'content' => $excerpt ?: 'Описание недоступно',
                    'score' => 1.0,
                    'source' => 'mediawiki',
                    'url' => $title->getFullURL()
                ];
            }
        }
        return $searchResults;
    }

    /**
     * Get an excerpt from a wiki page
     * 
     * @param int $pageId Page ID
     * @return string Page excerpt
     */
    private function getPageExcerpt($pageId)
    {
        try {
            $page = \WikiPage::newFromID($pageId);
            if (!$page) {
                return '';
            }

            $content = $page->getContent();
            if (!$content) {
                return '';
            }

            $text = $content->getTextForSummary(300);
            return $text;
        } catch (\Exception $e) {
            wfDebugLog('supportSystem', 'Error getting page excerpt: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Search OpenSearch using curl
     * 
     * @param string $query Search query
     * @param int $limit Maximum number of results
     * @return array Search results
     */
    private function searchOpenSearch($query, $limit)
    {
        try {
            $config = MediaWikiServices::getInstance()->getMainConfig();
            $host = $config->get('SupportSystemOpenSearchHost');
            $port = $config->get('SupportSystemOpenSearchPort');
            $index = $config->get('SupportSystemOpenSearchIndex');

            $url = "http://{$host}:{$port}/{$index}/_search";
            $queryTerms = preg_split('/\s+/', $query);
            $processedTerms = [];

            foreach ($queryTerms as $term) {
                if (strlen($term) >= 3) {
                    $processedTerms[] = $term;
                }
            }
            $processedQuery = !empty($processedTerms) ? implode(' ', $processedTerms) : $query;

            $requestData = [
                'query' => [
                    'bool' => [
                        'should' => [
                            [
                                'multi_match' => [
                                    'query' => $processedQuery,
                                    'fields' => ['title^3', 'content^2', 'tags^1.5'],
                                    'type' => 'best_fields',
                                    'operator' => 'or',
                                    'fuzziness' => 'AUTO'
                                ]
                            ]
                        ]
                    ]
                ],
                'highlight' => [
                    'pre_tags' => ['<strong>'],
                    'post_tags' => ['</strong>'],
                    'fields' => [
                        'content' => [
                            'fragment_size' => 150,
                            'number_of_fragments' => 3
                        ],
                        'title' => new \stdClass()
                    ]
                ],
                'size' => $limit
            ];

            $jsonData = json_encode($requestData);

            $command = "curl -s -X POST -H \"Content-Type: application/json\" " .
                "-d " . escapeshellarg($jsonData) . " \"$url\"";

            $response = shell_exec($command);

            if ($response === false || empty($response)) {
                wfDebugLog('supportSystem', 'Empty response from OpenSearch');
                return [];
            }

            $data = json_decode($response, true);
            if (!$data) {
                wfDebugLog('supportSystem', 'Invalid JSON response from OpenSearch');
                return [];
            }

            $results = [];
            if (isset($data['hits']['hits']) && is_array($data['hits']['hits'])) {
                foreach ($data['hits']['hits'] as $hit) {
                    $source = isset($hit['_source']) ? $hit['_source'] : [];

                    $result = [
                        'id' => $source['id'] ?? $hit['_id'],
                        'title' => $source['title'] ?? 'Без названия',
                        'content' => $source['content'] ?? '',
                        'score' => $hit['_score'] ?? 1.0,
                        'source' => 'opensearch',
                        'url' => $source['url'] ?? ''
                    ];

                    if (isset($hit['highlight'])) {
                        if (isset($hit['highlight']['content'])) {
                            $result['highlight'] = implode('... ', $hit['highlight']['content']) . '...';
                        } elseif (isset($hit['highlight']['title'])) {
                            $result['highlight'] = $result['title'] . ': ' . ($result['content'] ? substr($result['content'], 0, 200) . '...' : '');
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