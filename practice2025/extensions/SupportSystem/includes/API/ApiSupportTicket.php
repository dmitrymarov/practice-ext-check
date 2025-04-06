<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\Extension\SupportSystem\ServiceDesk;
use MWException;

class ApiSupportTicket extends ApiBase
{
    public function execute()
    {
        $params = $this->extractRequestParams();
        $operation = $params['operation'];
        try {
            $serviceDesk = new ServiceDesk();
            switch ($operation) {
                case 'create':
                    $this->requirePostedParameters(['subject', 'description']);
                    $subject = $params['subject'];
                    $description = $params['description'];
                    $priority = $params['priority'];
                    $assignedTo = $params['assigned_to'] ?? null;
                    wfDebugLog('SupportSystem', "API: создание тикета: $subject, приоритет: $priority");
                    try {
                        $ticket = $serviceDesk->createTicket(
                            $subject,
                            $description,
                            $priority,
                            $assignedTo
                        );
                        $this->getResult()->addValue(null, 'ticket', $ticket);
                    } catch (MWException $e) {
                        wfDebugLog('SupportSystem', "API: ошибка создания тикета: " . $e->getMessage());
                        $this->dieWithError($e->getMessage());
                    }
                    break;
                case 'get':
                    $ticketId = $params['ticket_id'];
                    if (!$ticketId) {
                        $this->dieWithError(['apierror-invalidparameter', 'ticket_id']);
                    }

                    $ticket = $serviceDesk->getTicket($ticketId);
                    if ($ticket) {
                        $this->getResult()->addValue(null, 'ticket', $ticket);
                    } else {
                        $this->dieWithError('supportsystem-error-ticket-not-found');
                    }
                    break;
                case 'list':
                    $limit = $params['limit'];
                    $offset = $params['offset'];
                    $tickets = $serviceDesk->getAllTickets($limit, $offset);
                    $this->getResult()->addValue(null, 'tickets', $tickets);
                    break;
                case 'comment':
                    $this->requirePostedParameters(['ticket_id', 'comment']);
                    $ticketId = $params['ticket_id'];
                    $comment = $params['comment'];
                    if (!$ticketId) {
                        $this->dieWithError(['apierror-invalidparameter', 'ticket_id']);
                    }
                    $success = $serviceDesk->addComment($ticketId, $comment);
                    if ($success) {
                        $this->getResult()->addValue(null, 'result', 'success');
                    } else {
                        $this->dieWithError('supportsystem-error-add-comment-failed');
                    }
                    break;
                case 'solution':
                    $this->requirePostedParameters(['ticket_id', 'solution']);
                    $ticketId = $params['ticket_id'];
                    $solution = $params['solution'];
                    $source = $params['source'];
                    if (!$ticketId) {
                        $this->dieWithError(['apierror-invalidparameter', 'ticket_id']);
                    }
                    $success = $serviceDesk->attachSolution($ticketId, $solution, $source);
                    if ($success) {
                        $this->getResult()->addValue(null, 'result', 'success');
                    } else {
                        $this->dieWithError('supportsystem-error-attach-solution-failed');
                    }
                    break;
                default:
                    $this->dieWithError(['apierror-invalidparameter', 'operation']);
            }
        } catch (MWException $e) {
            wfDebugLog('SupportSystem', "API exception: " . $e->getMessage());
            $this->dieWithError($e->getMessage());
        }
    }
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
                ApiBase::PARAM_TYPE => ['green', 'yellow', 'orange', 'red'],
                ApiBase::PARAM_DFLT => 'yellow',
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
            'limit' => [
                ApiBase::PARAM_TYPE => 'integer',
                ApiBase::PARAM_DFLT => 25,
                ApiBase::PARAM_MIN => 1,
                ApiBase::PARAM_MAX => 100,
                ApiBase::PARAM_REQUIRED => false,
            ],
            'offset' => [
                ApiBase::PARAM_TYPE => 'integer',
                ApiBase::PARAM_DFLT => 0,
                ApiBase::PARAM_MIN => 0,
                ApiBase::PARAM_REQUIRED => false,
            ],
        ];
    }
}