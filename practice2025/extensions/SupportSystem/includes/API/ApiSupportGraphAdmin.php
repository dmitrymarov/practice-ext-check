<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\Extension\SupportSystem\DecisionGraph;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module for managing decision graph
 */
class ApiSupportGraphAdmin extends ApiBase
{
    public function execute()
    {
        if (!$this->getUser()->isAllowed('edit')) {
            $this->dieWithError('You do not have permission to edit the decision graph', 'permissiondenied');
            return;
        }
        $params = $this->extractRequestParams();
        $action = $params['graphaction'];
        $graph = new DecisionGraph();
        switch ($action) {
            case 'get':
                $graphData = $graph->getGraph();
                $this->getResult()->addValue(null, 'graph', $graphData);
                break;

            case 'save':
                $this->requirePostedParameters(['nodes', 'edges']);
                $nodes = json_decode($params['nodes'], true);
                $edges = json_decode($params['edges'], true);

                if (!is_array($nodes) || !is_array($edges)) {
                    $this->dieWithError('Invalid graph data format', 'invalidformat');
                    return;
                }

                $graph->setGraph([
                    'nodes' => $nodes,
                    'edges' => $edges
                ]);

                $success = $graph->saveGraph();

                if (!$success) {
                    $this->dieWithError('Failed to save graph', 'savefailed');
                    return;
                }

                $this->getResult()->addValue(null, 'result', 'success');
                break;

            default:
                $this->dieWithError(['apierror-invalidparameter', 'graphaction']);
        }
    }

    /**
     * Get allowed parameters
     * @return array
     */
    public function getAllowedParams()
    {
        return [
            'graphaction' => [
                ParamValidator::PARAM_TYPE => ['get', 'save'],
                ParamValidator::PARAM_REQUIRED => true,
            ],
            'nodes' => [
                ParamValidator::PARAM_TYPE => 'string',
                ParamValidator::PARAM_REQUIRED => false,
            ],
            'edges' => [
                ParamValidator::PARAM_TYPE => 'string',
                ParamValidator::PARAM_REQUIRED => false,
            ],
        ];
    }

    /**
     * Indicates this module requires write mode
     * @return bool
     */
    public function isWriteMode()
    {
        $params = $this->extractRequestParams();
        return isset($params['graphaction']) && $params['graphaction'] === 'save';
    }
}