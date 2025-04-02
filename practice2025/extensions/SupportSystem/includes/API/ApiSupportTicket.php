<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\Extension\SupportSystem\RedmineBridge;

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

        $redmineBridge = new RedmineBridge();

        try {
            switch ($operation) {
                case 'create':
                    $this->requirePostedParameters(['subject', 'description']);

                    $subject = $params['subject'];
                    $description = $params['description'];
                    $priority = $params['priority'];
                    $assignedTo = $params['assigned_to'] ?? null;

                    $ticket = $redmineBridge->createTicket(
                        $subject,
                        $description,
                        $priority,
                        $assignedTo,
                        1
                    );
                    $this->getResult()->addValue(null, 'ticket', $ticket);
                    break;
                case 'get':
                    $ticketId = $params['ticket_id'];
                    if (!$ticketId) {
                        $this->dieWithError(['apierror-invalidparameter', 'ticket_id']);
                    }
                    $ticket = $redmineBridge->getTicket($ticketId);
                    if ($ticket === null) {
                        $this->dieWithError('supportsystem-error-ticket-not-found');
                    }
                    $this->getResult()->addValue(null, 'ticket', $ticket);
                    break;
                case 'list':
                    $tickets = $redmineBridge->getAllTickets();
                    $this->getResult()->addValue(null, 'tickets', $tickets);
                    break;
                case 'comment':
                    $this->requirePostedParameters(['ticket_id', 'comment']);
                    $ticketId = $params['ticket_id'];
                    $comment = $params['comment'];
                    if (!$ticketId) {
                        $this->dieWithError(['apierror-invalidparameter', 'ticket_id']);
                    }
                    $success = $redmineBridge->addComment($ticketId, $comment);
                    if (!$success) {
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
                    $success = $redmineBridge->attachSolution($ticketId, $solution, $source);
                    if (!$success) {
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
}