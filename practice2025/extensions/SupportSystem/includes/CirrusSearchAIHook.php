<?php

namespace MediaWiki\Extension\SupportSystem;

use CirrusSearch\Query\Builder\FullTextQueryBuilder;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\Search\ResultSet;

class CirrusSearchAIHook
{
    /**
     * Hook handler for CirrusSearchAlterQueryBuilder
     * @param FullTextQueryBuilder $builder
     * @param SearchContext $context
     * @return bool
     */
    public static function onCirrusSearchAlterQueryBuilder(FullTextQueryBuilder $builder, SearchContext $context): bool
    {
        $term = $context->getSearchQuery();
        return true;
    }
    /**
     * Hook handler for CirrusSearchResults
     * @param ResultSet $resultSet
     * @return bool
     */
    public static function onCirrusSearchResults(ResultSet $resultSet): bool
    {
        $term = $resultSet->getSearchQuery();
        if (strlen($term) < 5) { return true; }
        try {
            $aiBridge = new AISearchBridge();
            if ($aiBridge->isAvailable()) {
                wfRunHooks('SupportSystemBgProcessAISearch', [$term]);
            }
        } catch (\Exception $e){}
        return true;
    }
}