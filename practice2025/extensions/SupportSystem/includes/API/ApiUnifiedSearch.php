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

            // Добавляем журналирование для отладки
            wfDebugLog('supportSystem', 'Search request: query=' . $query . ', sources=' . implode(',', $sources));

            $results = [];

            if (in_array('opensearch', $sources)) {
                try {
                    $results['opensearch'] = $this->searchOpenSearch($query, $limit);
                    wfDebugLog('supportSystem', 'OpenSearch returned ' . count($results['opensearch']) . ' results');
                } catch (\Exception $e) {
                    wfDebugLog('supportSystem', 'OpenSearch error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                    $results['opensearch'] = [];
                }
            }

            if (in_array('mediawiki', $sources)) {
                try {
                    // Используем базовый поиск MediaWiki с защитой от ошибок
                    $results['mediawiki'] = $this->searchMediaWiki($query, $limit);
                    wfDebugLog('supportSystem', 'MediaWiki search returned ' . count($results['mediawiki']) . ' results');
                } catch (\Exception $e) {
                    wfDebugLog('supportSystem', 'MediaWiki search error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                    $results['mediawiki'] = [];
                }
            }

            $this->getResult()->addValue(null, 'results', $results);

        } catch (\Exception $e) {
            wfDebugLog('supportSystem', 'API error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->dieWithError(
                ['apierror-unifiedsearch-error', $e->getMessage()],
                'search_error'
            );
        }
    }

    /**
     * Предварительная обработка запроса для улучшения контекстного поиска
     * Извлекает ключевые слова из запросов в естественной форме
     * 
     * @param string $query Исходный запрос
     * @return string Обработанный запрос
     */
    private function preprocessQuery($query)
    {
        // Удаляем вопросительные знаки
        $query = str_replace('?', '', $query);

        // Преобразуем запросы вида "не работает X" в "X проблема ошибка"
        if (preg_match('/не работает\s+([^\s,\.]+)/iu', $query, $matches)) {
            $subject = $matches[1];
            return "$subject проблема ошибка " . $query;
        }

        // Преобразуем запросы вида "как починить X" в "X исправить починить решение"
        if (preg_match('/как\s+(починить|исправить|настроить)\s+([^\s,\.]+)/iu', $query, $matches)) {
            $action = $matches[1];
            $subject = $matches[2];
            return "$subject $action решение " . $query;
        }

        // Преобразуем "что делать" в поисковые термины
        $query = preg_replace('/что\s+делать/iu', 'решение проблема', $query);

        // Добавляем дополнительные ключевые слова для повышения релевантности
        if (stripos($query, 'ошибк') !== false) {
            $query .= ' решение проблема';
        }

        return $query;
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
            wfDebugLog('supportSystem', 'Starting MediaWiki search for: ' . $query);

            // Очистка и подготовка запроса
            $query = trim($query);
            if (empty($query)) {
                return [];
            }

            $searchEngineFactory = MediaWikiServices::getInstance()->getSearchEngineFactory();
            $searchEngine = $searchEngineFactory->create();
            $searchEngine->setNamespaces([NS_MAIN]); // Ограничиваем основным пространством имен
            $searchEngine->setLimitOffset($limit);

            // Поиск по заголовкам
            $titleResults = $searchEngine->searchTitle($query);
            wfDebugLog('supportSystem', 'Title search completed');

            // Поиск по тексту
            $textResults = $searchEngine->searchText($query);
            wfDebugLog('supportSystem', 'Text search completed');

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
                wfDebugLog('supportSystem', 'No results from regular search, trying fallback search');
                $searchResults = $this->fallbackSearch($query, $limit);
            }

            wfDebugLog('supportSystem', 'MediaWiki search completed with ' . count($searchResults) . ' results');
            return $searchResults;
        } catch (\Exception $e) {
            wfDebugLog('supportSystem', 'Search error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return [];
        }
    }

    /**
     * Разбивает запрос на поисковые термины, удаляя стоп-слова
     * 
     * @param string $query Поисковый запрос
     * @return array Массив поисковых терминов
     */
    private function getSearchTerms($query)
    {
        // Список стоп-слов, которые не несут смысловой нагрузки
        $stopWords = [
            'не',
            'как',
            'что',
            'где',
            'когда',
            'почему',
            'зачем',
            'и',
            'или',
            'но',
            'а',
            'в',
            'на',
            'под',
            'за',
            'из',
            'с',
            'по',
            'к',
            'у',
            'о',
            'об',
            'от',
            'для',
            'до',
            'при',
            'делать',
            'сделать',
            'можно',
            'нужно',
            'надо'
        ];

        // Разбиваем строку на слова
        $words = preg_split('/\s+/', mb_strtolower($query));

        // Фильтруем стоп-слова и слова короче 3 символов
        $terms = [];
        foreach ($words as $word) {
            if (mb_strlen($word) >= 3 && !in_array($word, $stopWords)) {
                $terms[] = $word;
            }
        }

        return $terms;
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
        $queryTerms = $this->getSearchTerms($query);
        $likeConditions = [];

        foreach ($queryTerms as $term) {
            if (strlen($term) < 3)
                continue;
            $escapedTerm = $dbr->escapeLike($term);
            $likeConditions[] = "page_title LIKE '%" . $escapedTerm . "%'";
        }

        // Если нет подходящих условий, пробуем искать по всему тексту запроса
        if (empty($likeConditions)) {
            $fullQuery = $dbr->escapeLike(trim($query));
            if (strlen($fullQuery) >= 3) {
                $likeConditions[] = "page_title LIKE '%" . $fullQuery . "%'";
            } else {
                return [];
            }
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

            // Ищем совпадения в тексте
            if ($excerpt) {
                foreach ($queryTerms as $term) {
                    if (strlen($term) < 3)
                        continue;
                    if (stripos($excerpt, $term) !== false) {
                        $contentMatch = true;
                        break;
                    }
                }

                // Если не нашли по отдельным словам, пробуем по всему запросу
                if (!$contentMatch && stripos($excerpt, $query) !== false) {
                    $contentMatch = true;
                }
            }

            if ($contentMatch || empty($queryTerms)) {
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

            // Получаем настройки OpenSearch из конфигурации
            $host = $config->get('SupportSystemOpenSearchHost');
            $port = $config->get('SupportSystemOpenSearchPort');
            $index = $config->get('SupportSystemOpenSearchIndex');

            // Проверка наличия настроек
            if (empty($host) || empty($port) || empty($index)) {
                wfDebugLog('supportSystem', 'OpenSearch configuration is incomplete: host=' . $host . ', port=' . $port . ', index=' . $index);
                return [];
            }

            $url = "http://{$host}:{$port}/{$index}/_search";

            // Логирование запроса
            wfDebugLog('supportSystem', "OpenSearch URL: $url");

            // Подготавливаем запрос
            $queryTerms = preg_split('/\s+/', $query);
            $processedTerms = [];

            foreach ($queryTerms as $term) {
                if (strlen($term) >= 3) {
                    $processedTerms[] = $term;
                }
            }

            // Убедимся, что у нас есть хотя бы один термин для поиска
            $processedQuery = !empty($processedTerms) ? implode(' ', $processedTerms) : $query;

            // Упрощенный запрос поиска для улучшения стабильности
            $requestData = [
                'query' => [
                    'multi_match' => [
                        'query' => $processedQuery,
                        'fields' => ['title^3', 'content^2', 'tags'],
                        'type' => 'best_fields',
                        'operator' => 'or'
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

            $jsonData = json_encode($requestData);

            // Логирование запроса
            wfDebugLog('supportSystem', 'OpenSearch query: ' . substr($jsonData, 0, 500) . (strlen($jsonData) > 500 ? '...' : ''));

            // Используем curl через функцию cURL вместо shell_exec для большей надёжности
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlError) {
                wfDebugLog('supportSystem', "cURL error: $curlError");
                return [];
            }

            if ($httpCode >= 400) {
                wfDebugLog('supportSystem', "HTTP error: $httpCode, Response: " . substr($response, 0, 500));
                return [];
            }

            if ($response === false || empty($response)) {
                wfDebugLog('supportSystem', 'Empty response from OpenSearch');
                return [];
            }

            $data = json_decode($response, true);
            if (!$data) {
                wfDebugLog('supportSystem', 'Invalid JSON response from OpenSearch: ' . substr($response, 0, 500));
                return [];
            }

            // Проверка на ошибки в ответе OpenSearch
            if (isset($data['error'])) {
                $errorMsg = is_array($data['error']) ?
                    (isset($data['error']['reason']) ? $data['error']['reason'] : json_encode($data['error'])) :
                    $data['error'];
                wfDebugLog('supportSystem', 'OpenSearch error: ' . $errorMsg);
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

            wfDebugLog('supportSystem', 'Successfully processed OpenSearch results: ' . count($results));
            return $results;
        } catch (\Exception $e) {
            wfDebugLog('supportSystem', 'OpenSearch exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
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