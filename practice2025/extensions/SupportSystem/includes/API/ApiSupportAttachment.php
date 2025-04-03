<?php

namespace MediaWiki\Extension\SupportSystem\API;

use ApiBase;
use MediaWiki\Extension\SupportSystem\ServiceDesk;
use MWException;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API модуль для загрузки файлов в тикеты
 */
class ApiSupportAttachment extends ApiBase
{
    /**
     * Выполнение API модуля
     */
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

                    // Загрузка файла в Redmine
                    try {
                        // Вызываем упрощенный метод для загрузки и прикрепления файла к тикету
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

    /**
     * Получение допустимых параметров
     * @return array
     */
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

    /**
     * Указывает, что этот модуль требует режима записи
     * @return bool
     */
    public function isWriteMode()
    {
        return true;
    }

    /**
     * Указывает, что этот модуль требует использования POST
     * @return bool
     */
    public function mustBePosted()
    {
        return true;
    }
}