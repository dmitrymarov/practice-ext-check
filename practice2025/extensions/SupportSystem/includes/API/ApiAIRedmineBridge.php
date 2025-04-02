<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\Extension\SupportSystem\RedmineBridge;
use MWException;

/**
 * API module for interacting with Redmine
 */
class ApiRedmineBridge extends ApiBase
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
                case 'create_ticket':
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
                        1  // Project ID for 'support-system'
                    );

                    $this->getResult()->addValue(null, 'redmine_result', [
                        'success' => true,
                        'ticket' => $ticket
                    ]);
                    break;

                case 'get_ticket':
                    $ticketId = $params['ticket_id'];

                    if (!$ticketId) {
                        $this->dieWithError(['apierror-invalidparameter', 'ticket_id']);
                    }

                    $ticket = $redmineBridge->getTicket($ticketId);

                    if ($ticket === null) {
                        $this->getResult()->addValue(null, 'redmine_result', [
                            'success' => false,
                            'error' => 'Ticket not found'
                        ]);
                    } else {
                        $this->getResult()->addValue(null, 'redmine_result', [
                            'success' => true,
                            'ticket' => $ticket
                        ]);
                    }
                    break;

                case 'list_tickets':
                    $tickets = $redmineBridge->getAllTickets();
                    $this->getResult()->addValue(null, 'redmine_result', [
                        'success' => true,
                        'tickets' => $tickets
                    ]);
                    break;

                case 'add_comment':
                    $this->requirePostedParameters(['ticket_id', 'comment']);

                    $ticketId = $params['ticket_id'];
                    $comment = $params['comment'];

                    if (!$ticketId) {
                        $this->dieWithError(['apierror-invalidparameter', 'ticket_id']);
                    }

                    $success = $redmineBridge->addComment($ticketId, $comment);

                    $this->getResult()->addValue(null, 'redmine_result', [
                        'success' => $success
                    ]);
                    break;

                case 'attach_solution':
                    $this->requirePostedParameters(['ticket_id', 'solution']);

                    $ticketId = $params['ticket_id'];
                    $solution = $params['solution'];
                    $source = $params['source'];

                    if (!$ticketId) {
                        $this->dieWithError(['apierror-invalidparameter', 'ticket_id']);
                    }

                    $success = $redmineBridge->attachSolution($ticketId, $solution, $source);

                    $this->getResult()->addValue(null, 'redmine_result', [
                        'success' => $success
                    ]);
                    break;

                default:
                    $this->dieWithError(['apierror-invalidparameter', 'operation']);
            }
        } catch (MWException $e) {
            $this->getResult()->addValue(null, 'redmine_result', [
                'success' => false,
                'error' => $e->getMessage()
            ]);
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
                ApiBase::PARAM_TYPE => ['create_ticket', 'get_ticket', 'list_tickets', 'add_comment', 'attach_solution'],
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
     * Indicates this module requires write mode for certain operations
     * @return bool
     */
    public function isWriteMode()
    {
        $params = $this->extractRequestParams();
        return isset($params['operation']) && in_array(
            $params['operation'],
            ['create_ticket', 'add_comment', 'attach_solution']
        );
    }
}