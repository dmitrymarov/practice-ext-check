<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\MediaWikiServices;
use Http;
use MWException;

/**
 * API module for ticket management
 */
class ApiSupportTicket extends ApiBase
{
    /**
     * Execute the API module
     */
    public function execute()
    {
        $params = $this->extractRequestParams();
        $operation = $params['operation'];

        try {
            $config = MediaWikiServices::getInstance()->getMainConfig();
            $redmineUrl = $config->get('SupportSystemRedmineURL');
            $apiKey = $config->get('SupportSystemRedmineAPIKey');

            switch ($operation) {
                case 'create':
                    $this->requirePostedParameters(['subject', 'description']);

                    $subject = $params['subject'];
                    $description = $params['description'];
                    $priority = $params['priority'];
                    $assignedTo = $params['assigned_to'] ?? null;
                    $priorityId = $this->getPriorityId($priority);
                    $issueData = [
                        'issue' => [
                            'subject' => $subject,
                            'description' => $description,
                            'project_id' => 1,  // Project ID for 'support-system'
                            'priority_id' => $priorityId,
                            'tracker_id' => 1,
                            'status_id' => 1 
                        ]
                    ];

                    if ($assignedTo) {
                        $issueData['issue']['assigned_to_id'] = $assignedTo;
                    }

                    $url = rtrim($redmineUrl, '/') . "/issues.json";

                    $options = [
                        'method' => 'POST',
                        'timeout' => 30,
                        'postData' => json_encode($issueData),
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'X-Redmine-API-Key' => $apiKey
                        ]
                    ];

                    $response = Http::request($url, $options);

                    if ($response === false) {
                        $this->dieWithError('Error connecting to Redmine');
                    }

                    $statusCode = Http::getLastStatusCode();
                    if ($statusCode >= 400) {
                        $this->dieWithError("Redmine API returned error status: $statusCode");
                    }

                    $data = json_decode($response, true);
                    if (!isset($data['issue'])) {
                        $this->dieWithError('Invalid response format from Redmine');
                    }

                    $this->getResult()->addValue(null, 'ticket', $data['issue']);
                    break;

                case 'get':
                    $ticketId = $params['ticket_id'];

                    if (!$ticketId) {
                        $this->dieWithError(['apierror-invalidparameter', 'ticket_id']);
                    }

                    $url = rtrim($redmineUrl, '/') . "/issues/{$ticketId}.json";
                    $options = [
                        'method' => 'GET',
                        'timeout' => 30,
                        'headers' => [
                            'X-Redmine-API-Key' => $apiKey
                        ]
                    ];

                    $response = Http::request($url, $options);

                    if ($response === false) {
                        $this->dieWithError('Error connecting to Redmine');
                    }

                    $statusCode = Http::getLastStatusCode();
                    if ($statusCode >= 400) {
                        $this->dieWithError('supportsystem-error-ticket-not-found');
                    }

                    $data = json_decode($response, true);
                    if (!isset($data['issue'])) {
                        $this->dieWithError('Invalid response format from Redmine');
                    }

                    $this->getResult()->addValue(null, 'ticket', $data['issue']);
                    break;

                case 'list':
                    $url = rtrim($redmineUrl, '/') . "/issues.json";
                    $options = [
                        'method' => 'GET',
                        'timeout' => 30,
                        'headers' => [
                            'X-Redmine-API-Key' => $apiKey
                        ]
                    ];

                    $response = Http::request($url, $options);

                    if ($response === false) {
                        $this->dieWithError('Error connecting to Redmine');
                    }

                    $data = json_decode($response, true);
                    if (!isset($data['issues'])) {
                        $this->dieWithError('Invalid response format from Redmine');
                    }

                    $this->getResult()->addValue(null, 'tickets', $data['issues']);
                    break;

                case 'comment':
                    $this->requirePostedParameters(['ticket_id', 'comment']);

                    $ticketId = $params['ticket_id'];
                    $comment = $params['comment'];

                    if (!$ticketId) {
                        $this->dieWithError(['apierror-invalidparameter', 'ticket_id']);
                    }

                    $issueData = [
                        'issue' => [
                            'notes' => $comment
                        ]
                    ];

                    $url = rtrim($redmineUrl, '/') . "/issues/{$ticketId}.json";

                    $options = [
                        'method' => 'PUT',
                        'timeout' => 30,
                        'postData' => json_encode($issueData),
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'X-Redmine-API-Key' => $apiKey
                        ]
                    ];

                    $response = Http::request($url, $options);

                    if ($response === false) {
                        $this->dieWithError('supportsystem-error-add-comment-failed');
                    }

                    $statusCode = Http::getLastStatusCode();
                    if ($statusCode >= 400) {
                        $this->dieWithError('supportsystem-error-add-comment-failed');
                    }

                    $this->getResult()->addValue(null, 'result', 'success');
                    break;

                case 'solution':
                    $this->requirePostedParameters(['ticket_id', 'solution']);

                    $ticketId = $params['ticket_id'];
                    $solution = $params['solution'];
                    $source = $params['source'];

                    if (!$ticketId) {
                        $this->dieWithError(['apierror-invalidparameter', 'ticket_id']);
                    }

                    $comment = "Найденное решение: {$solution}\n\nИсточник: {$source}";

                    $issueData = [
                        'issue' => [
                            'notes' => $comment
                        ]
                    ];

                    $url = rtrim($redmineUrl, '/') . "/issues/{$ticketId}.json";

                    $options = [
                        'method' => 'PUT',
                        'timeout' => 30,
                        'postData' => json_encode($issueData),
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'X-Redmine-API-Key' => $apiKey
                        ]
                    ];

                    $response = Http::request($url, $options);

                    if ($response === false) {
                        $this->dieWithError('supportsystem-error-attach-solution-failed');
                    }

                    $statusCode = Http::getLastStatusCode();
                    if ($statusCode >= 400) {
                        $this->dieWithError('supportsystem-error-attach-solution-failed');
                    }

                    $this->getResult()->addValue(null, 'result', 'success');
                    break;

                default:
                    $this->dieWithError(['apierror-invalidparameter', 'operation']);
            }
        } catch (\Exception $e) {
            $this->dieWithError($e->getMessage());
        }
    }

    /**
     * Get allowed parameters
     * @return array
     */
    public function getAllowedParams()
    {
        return [
            'operation' => [
                ApiBase::PARAM_TYPE => ['create', 'get', 'list', 'comment', 'solution'],
                ApiBase::PARAM_REQUIRED => true,
            ],
            'ticket_id' => [
                ApiBase::PARAM_TYPE => 'integer',
                ApiBase::PARAM_REQUIRED => false,
            ],
            'subject' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_REQUIRED => false,
            ],
            'description' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_REQUIRED => false,
            ],
            'priority' => [
                ApiBase::PARAM_TYPE => ['low', 'normal', 'high', 'urgent'],
                ApiBase::PARAM_DFLT => 'normal',
                ApiBase::PARAM_REQUIRED => false,
            ],
            'assigned_to' => [
                ApiBase::PARAM_TYPE => 'integer',
                ApiBase::PARAM_REQUIRED => false,
            ],
            'comment' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_REQUIRED => false,
            ],
            'solution' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_REQUIRED => false,
            ],
            'source' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_DFLT => 'unknown',
                ApiBase::PARAM_REQUIRED => false,
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
        return isset($params['operation']) && in_array($params['operation'], ['create', 'comment', 'solution']);
    }

    /**
     * Get priority ID from name
     * @param string $priorityName Priority name
     * @return int Priority ID
     */
    private function getPriorityId(string $priorityName): int
    {
        $priorities = [
            'low' => 1,
            'normal' => 2,
            'high' => 3,
            'urgent' => 4
        ];

        return $priorities[strtolower($priorityName)] ?? 2;
    }
}