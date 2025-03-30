<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\Extension\SupportSystem\ServiceDesk;

/**
 * API module for ticket management
 */
class ApiSupportTicket extends ApiBase {
	/**
	 * Execute the API module
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$action = $params['action'];
		
		$serviceDesk = new ServiceDesk();
		
		switch ( $action ) {
            case 'create':
                $this->requirePostedParameters(['subject', 'description']);

                $subject = $params['subject'];
                $description = $params['description'];
                $priority = $params['priority'];
                $assignedTo = $params['assigned_to'];

                try {
                    $ticket = $serviceDesk->createTicket(
                        $subject,
                        $description,
                        $priority,
                        $assignedTo
                    );

                    $this->getResult()->addValue(null, 'ticket', $ticket);
                } catch (\Exception $e) {
                    $this->dieWithError($e->getMessage());
                }
                break;

            case 'get':
                $ticketId = $params['ticket_id'];

                if (!$ticketId) {
                    $this->dieWithError(['apierror-invalidparameter', 'ticket_id']);
                }

                $ticket = $serviceDesk->getTicket($ticketId);

                if ($ticket === null) {
                    $this->dieWithError('supportsystem-error-ticket-not-found');
                }

                $this->getResult()->addValue(null, 'ticket', $ticket);
                break;

            case 'list':
                $tickets = $serviceDesk->getAllTickets();
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

                $success = $serviceDesk->attachSolution($ticketId, $solution, $source);

                if (!$success) {
                    $this->dieWithError('supportsystem-error-attach-solution-failed');
                }

                $this->getResult()->addValue(null, 'result', 'success');
                break;

            default:
                $this->dieWithError(['apierror-invalidparameter', 'action']);
        }
    }

    /**
     * Get allowed parameters
     * @return array
     */
    public function getAllowedParams()
    {
        return [
            'action' => [
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
     * Examples for the API documentation
     * @return array
     */
    public function getExamplesMessages()
    {
        return [
            'action=supportticket&action=create&subject=Test&description=Test%20description&priority=normal' => 'apihelp-supportticket-example-1',
            'action=supportticket&action=get&ticket_id=1' => 'apihelp-supportticket-example-2',
            'action=supportticket&action=list' => 'apihelp-supportticket-example-3',
            'action=supportticket&action=comment&ticket_id=1&comment=Test%20comment' => 'apihelp-supportticket-example-4',
            'action=supportticket&action=solution&ticket_id=1&solution=Test%20solution&source=manual' => 'apihelp-supportticket-example-5',
        ];
    }

    /**
     * Indicates this module requires write mode
     * @return bool
     */
    public function isWriteMode()
    {
        $params = $this->extractRequestParams();
        return in_array($params['action'], ['create', 'comment', 'solution']);
    }
}