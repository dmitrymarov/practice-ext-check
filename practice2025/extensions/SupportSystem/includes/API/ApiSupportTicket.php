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
                    try {
                        $attachments = [];
                        $request = $this->getRequest();
                        if ($request->wasPosted() && !empty($_FILES)) {
                            foreach ($_FILES as $fileField => $fileData) {
                                if (is_array($fileData['name'])) {
                                    $fileCount = count($fileData['name']);
                                    for ($i = 0; $i < $fileCount; $i++) {
                                        $fileSize = $fileData['size'][$i];
                                        if ($fileSize > 10 * 1024 * 1024) {
                                            $this->dieWithError("File is too large (max size: 10MB)", 'file_too_large');
                                        }
                                    }
                                } else {
                                    $fileSize = $fileData['size'];
                                    if ($fileSize > 10 * 1024 * 1024) {
                                        $this->dieWithError("File is too large (max size: 10MB)", 'file_too_large');
                                    }
                                }
                            }
                            $attachments = $serviceDesk->processUploadedFiles($_FILES);
                        }
                        $ticket = $serviceDesk->createTicket(
                            $subject,
                            $description,
                            $priority,
                            $assignedTo,
                            1,
                            $attachments
                        );
                        if (isset($params['solution']) && !empty($params['solution'])) {
                            $solution = $params['solution'];
                            $source = $params['source'] ?? 'unknown';
                            $serviceDesk->attachSolution($ticket['id'], $solution, $source);
                        }
                        $this->getResult()->addValue(null, 'ticket', $ticket);
                    } catch (MWException $e) { $this->dieWithError($e->getMessage()); }
                    break;

                case 'attachment':
                    $this->requirePostedParameters(['ticket_id']);
                    $ticketId = $params['ticket_id'];
                    $comment = $params['comment'] ?? '';
                    if (!$ticketId) { $this->dieWithError(['apierror-invalidparameter', 'ticket_id']); }
                    try {
                        $attachments = [];
                        $request = $this->getRequest();
                        if ($request->wasPosted() && !empty($_FILES)) {
                            foreach ($_FILES as $fileField => $fileData) {
                                if (is_array($fileData['name'])) {
                                    $fileCount = count($fileData['name']);
                                    for ($i = 0; $i < $fileCount; $i++) {
                                        $fileSize = $fileData['size'][$i];
                                        if ($fileSize > 10 * 1024 * 1024) {
                                            $this->dieWithError("File is too large (max size: 10MB)", 'file_too_large');
                                        }
                                    }
                                } else {
                                    $fileSize = $fileData['size'];
                                    if ($fileSize > 10 * 1024 * 1024) {
                                        $this->dieWithError("File is too large (max size: 10MB)", 'file_too_large');
                                    }
                                }
                            }
                            $attachments = $serviceDesk->processUploadedFiles($_FILES);
                            if (!empty($attachments)) {
                                $success = $serviceDesk->attachFilesToTicket($ticketId, $attachments, $comment);
                                if ($success) { $this->getResult()->addValue(null, 'result', 'success'); } 
                                else { $this->dieWithError('Failed to attach files to ticket', 'attachment_failed'); }
                            } else { $this->dieWithError('No valid files were uploaded', 'no_files'); }
                        } else { $this->dieWithError('No files were uploaded', 'no_files'); }
                    } catch (MWException $e) { $this->dieWithError($e->getMessage()); }
                    break;

                case 'get':
                    $ticketId = $params['ticket_id'];
                    if (!$ticketId) { $this->dieWithError(['apierror-invalidparameter', 'ticket_id']); }
                    $ticket = $serviceDesk->getTicket($ticketId);
                    if ($ticket) { $this->getResult()->addValue(null, 'ticket', $ticket); } 
                    else { $this->dieWithError('supportsystem-error-ticket-not-found'); }
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
                    if (!$ticketId) { $this->dieWithError(['apierror-invalidparameter', 'ticket_id']); }
                    $success = $serviceDesk->addComment($ticketId, $comment);
                    if ($success) { $this->getResult()->addValue(null, 'result', 'success'); } 
                    else { $this->dieWithError('supportsystem-error-add-comment-failed'); }
                    break;

                case 'solution':
                    $this->requirePostedParameters(['ticket_id', 'solution']);
                    $ticketId = $params['ticket_id'];
                    $solution = $params['solution'];
                    $source = $params['source'];
                    if (!$ticketId) { $this->dieWithError(['apierror-invalidparameter', 'ticket_id']); }
                    $success = $serviceDesk->attachSolution($ticketId, $solution, $source);
                    if ($success) { $this->getResult()->addValue(null, 'result', 'success'); } 
                    else { $this->dieWithError('supportsystem-error-attach-solution-failed'); }
                    break;
                default:
                    $this->dieWithError(['apierror-invalidparameter', 'operation']);
            }
        } catch (MWException $e) {
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
                ApiBase::PARAM_TYPE => ['create', 'get', 'list', 'comment', 'solution', 'attachment'],
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

    /**
     * Indicates this module requires write mode
     * @return bool
     */
    public function isWriteMode()
    {
        $operation = $this->getParameter('operation');
        return in_array($operation, ['create', 'comment', 'solution', 'attachment']);
    }

    /**
     * Indicates whether this module requires upload
     * @return bool
     */
    public function mustBePosted()
    {
        $operation = $this->getParameter('operation');
        return in_array($operation, ['create', 'comment', 'solution', 'attachment']);
    }
}