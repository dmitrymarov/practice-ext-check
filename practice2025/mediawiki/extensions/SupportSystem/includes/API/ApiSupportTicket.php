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
        $serviceDesk = new ServiceDesk();

        try {
            switch ($params['operation']) {
                case 'create':
                    $this->requirePostedParameters(['subject', 'description']);
                    $attachments = $this->handleAttachments();

                    $ticket = $serviceDesk->createTicket(
                        $params['subject'],
                        $params['description'],
                        $params['priority'] ?? 'normal',
                        $params['assigned_to'] ?? null,
                        1,
                        $attachments
                    );

                    if (!empty($params['solution'])) {
                        $serviceDesk->attachSolution(
                            $ticket['id'],
                            $params['solution'],
                            $params['source'] ?? 'unknown'
                        );
                    }

                    $this->getResult()->addValue(null, 'ticket', $ticket);
                    break;

                case 'attachment':
                    $this->requirePostedParameters(['ticket_id']);
                    $attachments = $this->handleAttachments();

                    if (empty($attachments)) {
                        $this->dieWithError('No files were uploaded', 'no_files');
                    }

                    $serviceDesk->attachFilesToTicket(
                        $params['ticket_id'],
                        $attachments,
                        $params['comment'] ?? ''
                    );

                    $this->getResult()->addValue(null, 'result', 'success');
                    break;

                case 'get':
                    $this->requireRequiredParameter('ticket_id');
                    $ticket = $serviceDesk->getTicket($params['ticket_id']);

                    if (!$ticket) {
                        $this->dieWithError('supportsystem-error-ticket-not-found');
                    }

                    $this->getResult()->addValue(null, 'ticket', $ticket);
                    break;

                case 'list':
                    $tickets = $serviceDesk->getAllTickets(
                        $params['limit'],
                        $params['offset']
                    );

                    $this->getResult()->addValue(null, 'tickets', $tickets);
                    break;

                case 'comment':
                    $this->requirePostedParameters(['ticket_id', 'comment']);
                    $serviceDesk->addComment(
                        $params['ticket_id'],
                        $params['comment']
                    );

                    $this->getResult()->addValue(null, 'result', 'success');
                    break;

                case 'solution':
                    $this->requirePostedParameters(['ticket_id', 'solution']);
                    $serviceDesk->attachSolution(
                        $params['ticket_id'],
                        $params['solution'],
                        $params['source'] ?? 'unknown'
                    );

                    $this->getResult()->addValue(null, 'result', 'success');
                    break;

                default:
                    $this->dieWithError(['apierror-invalidparameter', 'operation']);
            }
        } catch (MWException $e) {
            $this->dieWithError($e->getMessage());
        }
    }

    /**
     * Handle file uploads and validate sizes.
     *
     * @return array List of processed attachments
     */
    private function handleAttachments()
    {
        $attachments = [];
        $request = $this->getRequest();

        if ($request->wasPosted() && !empty($_FILES)) {
            foreach ($_FILES as $fileData) {
                $sizes = (array)$fileData['size'];
                foreach ($sizes as $size) {
                    if ($size > 10 * 1024 * 1024) {
                        $this->dieWithError('File is too large (max size: 10MB)', 'file_too_large');
                    }
                }
            }
            $attachments = (new ServiceDesk())->processUploadedFiles($_FILES);
        }

        return $attachments;
    }

    public function getAllowedParams()
    {
        return [
            'operation' => [ApiBase::PARAM_TYPE => ['create', 'get', 'list', 'comment', 'solution', 'attachment'], ApiBase::PARAM_REQUIRED => true],
            'ticket_id' => [ApiBase::PARAM_TYPE => 'integer'],
            'subject' => [ApiBase::PARAM_TYPE => 'string'],
            'description' => [ApiBase::PARAM_TYPE => 'string'],
            'priority' => [ApiBase::PARAM_TYPE => ['critical', 'high', 'normal', 'low', 'green', 'yellow', 'orange', 'red'], ApiBase::PARAM_DFLT => 'normal'],
            'assigned_to' => [ApiBase::PARAM_TYPE => 'integer'],
            'comment' => [ApiBase::PARAM_TYPE => 'string'],
            'solution' => [ApiBase::PARAM_TYPE => 'string'],
            'source' => [ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_DFLT => 'unknown'],
            'limit' => [ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_DFLT => 25, ApiBase::PARAM_MIN => 1, ApiBase::PARAM_MAX => 100],
            'offset' => [ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_DFLT => 0, ApiBase::PARAM_MIN => 0]
        ];
    }

    public function isWriteMode()
    {
        return in_array($this->getParameter('operation'), ['create', 'comment', 'solution', 'attachment'], true);
    }

    public function mustBePosted()
    {
        return in_array($this->getParameter('operation'), ['create', 'comment', 'solution', 'attachment'], true);
    }
}
