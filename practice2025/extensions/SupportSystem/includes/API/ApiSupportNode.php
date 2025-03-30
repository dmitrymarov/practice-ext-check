<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\Extension\SupportSystem\DecisionGraph;

/**
 * API module for getting decision tree nodes
 */
class ApiSupportNode extends ApiBase
{
    /**
     * Execute the API module
     */
    public function execute()
    {
        $params = $this->extractRequestParams();
        $nodeId = $params['node_id'];

        $graph = new DecisionGraph();

        if ($nodeId === 'root') {
            $nodeId = $graph->getRootNode();
        }

        $node = $graph->getNode($nodeId);

        if ($node === null) {
            $this->dieWithError(['apierror-invalidparameter', 'node_id']);
        }

        $children = $graph->getChildren($nodeId);

        $result = [
            'id' => $nodeId,
            'content' => $node['content'],
            'type' => $node['type'],
            'children' => $children
        ];

        $this->getResult()->addValue(null, $this->getModuleName(), $result);
    }

    /**
     * Get allowed parameters
     * @return array
     */
    public function getAllowedParams()
    {
        return [
            'node_id' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_REQUIRED => true,
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
            'action=supportnode&node_id=root' => 'apihelp-supportnode-example-1',
            'action=supportnode&node_id=q1' => 'apihelp-supportnode-example-2',
        ];
    }
}