<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\Extension\SupportSystem\SearchModule;

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
        $params = $this->extractRequestParams();
        $query = $params['query'];
        $sources = $params['sources'];
        $useAi = $params['use_ai'];
        $context = $params['context'] ? json_decode($params['context'], true) : [];

        $searchModule = new SearchModule();
        $results = [];

        // Regular search
        if (!$useAi) {
            // Search in OpenSearch if enabled
            if (in_array('opensearch', $sources)) {
                $opensearchResults = $searchModule->search($query);
                $results = array_merge($results, $opensearchResults);
            }

            // Search in MediaWiki if enabled
            if (in_array('mediawiki', $sources)) {
                $mediawikiResults = $searchModule->searchMediaWiki($query);
                $results = array_merge($results, $mediawikiResults);
            }

            // Sort by score
            usort($results, function ($a, $b) {
                return $b['score'] <=> $a['score'];
            });

            $this->getResult()->addValue(null, 'results', $results);
        } else {
            // AI search
            $aiResult = $searchModule->searchAI($query, $context);
            $this->getResult()->addValue(null, 'ai_result', $aiResult);
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