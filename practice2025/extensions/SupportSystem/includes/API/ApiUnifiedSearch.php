<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\MediaWikiServices;

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
            $sources = $params['sources'];
            $results = [];
            if (in_array('mediawiki', $sources)) {
                try {
                    $results['mediawiki'] = $this->searchMediaWiki($query, $limit);
                } catch (\Exception $e) {
                    $this->addWarning('mediawiki_search_error', $e->getMessage());
                    $results['mediawiki'] = [];
                }
            }
            if (in_array('opensearch', $sources)) {
                try {
                    $results['opensearch'] = $this->searchOpenSearch($query, $limit);
                } catch (\Exception $e) {
                    $this->addWarning('opensearch_error', $e->getMessage());
                    $results['opensearch'] = [];
                }
            }
            $this->getResult()->addValue(null, 'results', $results);
        } catch (\Exception $e) {
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
        $searchEngineFactory = MediaWikiServices::getInstance()->getSearchEngineFactory();
        $searchEngine = $searchEngineFactory->create();
        $useCirrus = class_exists('CirrusSearch\CirrusSearch') &&
            ($searchEngine instanceof \CirrusSearch\CirrusSearch ||
                is_subclass_of($searchEngine, 'CirrusSearch\CirrusSearch'));
        if ($useCirrus) {
            $matches = $searchEngine->searchText($query, ['limit' => $limit]);
            if ($matches) {
                foreach ($matches as $match) {
                    $title = $match->getTitle();
                    $snippet = $match->getTextSnippet();
                    $searchResults[] = [
                        'id' => $title->getArticleID(),
                        'title' => $title->getText(),
                        'content' => $snippet,
                        'score' => $match->getScore(),
                        'source' => 'mediawiki',
                        'url' => $title->getFullURL()
                    ];
                }
            }
        } else {
            $matches = $searchEngine->searchTitle($query, $limit);
            $matches = array_merge($matches, $searchEngine->searchText($query, $limit));
            $seenIds = [];
            foreach ($matches as $match) {
                $title = $match->getTitle();
                $id = $title->getArticleID();
                if (!isset($seenIds[$id])) {
                    $seenIds[$id] = true;
                    $searchResults[] = [
                        'id' => $id,
                        'title' => $title->getText(),
                        'content' => $match->getTextSnippet() ?: $this->getPageExcerpt($id),
                        'score' => $match->getScore(),
                        'source' => 'mediawiki',
                        'url' => $title->getFullURL()
                    ];
                }
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
        $page = \WikiPage::newFromID($pageId);
        if (!$page) { return ''; }
        $content = $page->getContent();
        if (!$content) { return ''; }
        $text = $content->getTextForSummary(300);
        return $text;
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
        $config = MediaWikiServices::getInstance()->getMainConfig();
        $host = $config->get('SupportSystemOpenSearchHost');
        $port = $config->get('SupportSystemOpenSearchPort');
        $index = $config->get('SupportSystemOpenSearchIndex');
        $url = "http://{$host}:{$port}/{$index}/_search";
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
        $command = "curl -s -X POST -H \"Content-Type: application/json\" " .
            "-d " . escapeshellarg($jsonData) . " \"$url\"";
        $response = shell_exec($command);
        if ($response === false || empty($response)) {
            throw new \Exception("Error connecting to OpenSearch");
        }
        $data = json_decode($response, true);
        $results = [];
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
                if (isset($hit['highlight']['content'])) { $result['highlight'] = $hit['highlight']['content'][0]; }
                if (isset($source['tags'])) { $result['tags'] = $source['tags']; }
                $results[] = $result;
            }
        }
        return $results;
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
                ApiBase::PARAM_ISMULTI => true,
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