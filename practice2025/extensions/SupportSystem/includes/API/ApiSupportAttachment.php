<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\Extension\SupportSystem\ServiceDesk;
use MWException;
use Wikimedia\ParamValidator\ParamValidator;

class ApiSupportAttachment extends ApiBase
{
    public function execute()
    {
        $this->requireLogin();
        $params = $this->extractRequestParams();
        $operation = $params['operation'];
        try {
            $serviceDesk = new ServiceDesk();
            switch ($operation) {
                case 'upload':
                    $this->requirePostedParameters(['ticket_id']);
                    $request = $this->getRequest();
                    $ticketId = $params['ticket_id'];
                    $comment = $params['comment'];
                    if (!$request->getUpload('file')) {
                        $this->dieWithError('No file uploaded', 'no_file');
                    }

                    $tmpName = $request->getFileTempname('file');
                    $originalName = $request->getFileName('file');
                    $fileSize = $request->getFileSize('file');
                    $fileType = $request->getUploadMimeType('file');

                    if (!$tmpName || !$originalName) {
                        $this->dieWithError('Invalid file upload', 'invalid_file');
                    }

                    if ($fileSize <= 0) {
                        $this->dieWithError('Empty file uploaded', 'empty_file');
                    }
                    try {
                        $result = $serviceDesk->attachFileToTicket(
                            $ticketId,
                            $tmpName,
                            $originalName,
                            $fileType,
                            $comment
                        );
                        if ($result) {
                            $this->getResult()->addValue(null, 'result', 'success');
                            $this->getResult()->addValue(null, 'message', 'File attached successfully');
                        } else {
                            $this->dieWithError('Failed to attach file', 'attach_failed');
                        }
                    } catch (MWException $e) {
                        $this->dieWithError($e->getMessage(), 'attach_error');
                    }
                    break;
                default:
                    $this->dieWithError(['apierror-invalidparameter', 'operation']);
            }
        } catch (MWException $e) {
            $this->dieWithError($e->getMessage(), 'api_error');
        }
    }
    public function getAllowedParams()
    {
        return [
            'operation' => [
                ParamValidator::PARAM_TYPE => ['upload'],
                ParamValidator::PARAM_REQUIRED => true,
            ],
            'ticket_id' => [
                ParamValidator::PARAM_TYPE => 'integer',
                ParamValidator::PARAM_REQUIRED => false,
            ],
            'comment' => [
                ParamValidator::PARAM_TYPE => 'string',
                ParamValidator::PARAM_REQUIRED => false,
                ParamValidator::PARAM_DEFAULT => 'Файл прикреплен',
            ],
        ];
    }
    public function isWriteMode()
    {
        return true;
    }
    public function mustBePosted()
    {
        return true;
    }
}