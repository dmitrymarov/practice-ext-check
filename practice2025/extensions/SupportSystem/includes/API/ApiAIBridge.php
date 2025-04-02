<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\Extension\SupportSystem\AISearchBridge;

/**
 * API module for interacting with AI service
 */
class ApiAIBridge extends ApiBase
{
    /**
     * Execute the API module
     */
    public function execute()
    {
        $params = $this->extractRequestParams();
        $query = $params['query'];
        $context = $params['context'] ? json_decode($params['context'], true) : [];

        // Get user ID for context tracking
        $userId = null;
        $user = $this->getUser();
        if ($user && !$user->isAnon()) {
            $userId = 'user_' . $user->getId();
        } else {
            // For anonymous users, use session ID if available
            $session = $this->getRequest()->getSession();
            if ($session) {
                $userId = 'anon_' . $session->getId();
            }
        }

        try {
            $aiBridge = new AISearchBridge();
            $result = $aiBridge->search($query, $context, $userId);

            $this->getResult()->addValue(null, 'ai_result', $result);
            $this->getResult()->addValue(null, 'success', true);
        } catch (\Exception $e) {
            $this->getResult()->addValue(null, 'error', [
                'code' => 'ai_search_error',
                'info' => $e->getMessage()
            ]);

            $this->getResult()->addValue(null, 'ai_result', [
                'answer' => 'Произошла ошибка при выполнении интеллектуального поиска. Пожалуйста, попробуйте снова позже.',
                'sources' => [],
                'success' => false
            ]);

            $this->getResult()->addValue(null, 'success', false);
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
            'action=aibridge&query=How to reset password' => 'apihelp-aibridge-example-1',
        ];
    }
}