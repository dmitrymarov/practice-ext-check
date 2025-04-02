<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\Extension\SupportSystem\ServiceDesk;
use MWException;

/**
 * API module for ticket management
 * 
 * Этот модуль предоставляет API для работы с тикетами через MediaWiki.
 * Он является тонкой оболочкой вокруг класса ServiceDesk,
 * добавляя проверку параметров и обработку ошибок в стиле MediaWiki.
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
            // Создаем экземпляр ServiceDesk для работы с Redmine
            $serviceDesk = new ServiceDesk();

            // Обрабатываем разные операции
            switch ($operation) {
                case 'create':
                    $this->requirePostedParameters(['subject', 'description']);
                    $subject = $params['subject'];
                    $description = $params['description'];
                    $priority = $params['priority'];
                    $assignedTo = $params['assigned_to'] ?? null;
                    wfDebugLog('SupportSystem', "API: создание тикета: $subject");
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

    /**
     * Examples for the API documentation
     * @return array
     */
    public function getExamplesMessages()
    {
        return [
            'action=supportticket&operation=create&subject=Test%20subject&description=Test%20description&priority=normal'
            => 'apihelp-supportticket-example-1',
            'action=supportticket&operation=get&ticket_id=1'
            => 'apihelp-supportticket-example-2',
            'action=supportticket&operation=list&limit=10'
            => 'apihelp-supportticket-example-3',
            'action=supportticket&operation=comment&ticket_id=1&comment=Test%20comment'
            => 'apihelp-supportticket-example-4',
            'action=supportticket&operation=solution&ticket_id=1&solution=Problem%20solved&source=Knowledge%20Base'
            => 'apihelp-supportticket-example-5',
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