<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\MediaWikiServices;

/**
 * 
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
            $results = [
                'cirrus' => []
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
            $this->getResult()->addValue(null, 'results', $results);
        } catch (\Exception $e) {
            $this->getResult()->addValue(null, 'error', [
                'code' => 'search_error',
                'info' => $e->getMessage()
            ]);
            $this->getResult()->addValue(null, 'results', [
                'cirrus' => []
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