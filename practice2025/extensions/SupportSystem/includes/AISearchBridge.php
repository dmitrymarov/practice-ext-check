<?php

namespace MediaWiki\Extension\SupportSystem;

use MediaWiki\MediaWikiServices;
use Http;
use FormatJson;
use MWException;

/**
 * Bridge class for AI search service
 */
class AISearchBridge
{
    /** @var string The AI service URL */
    private $aiServiceUrl;

    /** @var array Backup AI service URLs */
    private $backupAiServiceUrls = [];

    /** @var int Max retry attempts */
    private $maxRetries = 3;

    /** @var int Retry delay in seconds */
    private $retryDelay = 1;

    public function __construct()
    {
        $config = MediaWikiServices::getInstance()->getMainConfig();
        $this->aiServiceUrl = $config->get('SupportSystemAIServiceURL');
        $this->setupBackupServiceUrls();
    }

    /**
     * Setup backup service URLs for different network configurations
     */
    private function setupBackupServiceUrls()
    {
        $parsedUrl = parse_url($this->aiServiceUrl);
        $port = $parsedUrl['port'] ?? 5000;
        $this->backupAiServiceUrls[] = "http://ai-service:$port";
        $this->backupAiServiceUrls[] = "http://172.17.0.1:$port";
        $this->backupAiServiceUrls[] = "http://localhost:$port";
        $this->backupAiServiceUrls[] = "http://172.29.46.60:$port";
        $this->backupAiServiceUrls = array_filter($this->backupAiServiceUrls, function ($url) {
            return $url !== $this->aiServiceUrl;
        });
    }

    /**
     * Search via AI service
     * 
     * @param string $query The search query
     * @param array $context Previous context (optional)
     * @param string|null $userId User ID for tracking context (optional)
     * @return array
     */
    public function search(string $query, array $context = [], string $userId = null): array
    {
        $attempts = 0;
        $urlsToTry = array_merge([$this->aiServiceUrl], $this->backupAiServiceUrls);
        foreach ($urlsToTry as $currentUrl) {
            $attempts = 0;

            while ($attempts < $this->maxRetries) {
                try {
                    $url = "{$currentUrl}/api/search_ai";
                    $requestData = [
                        'query' => $query,
                        'context' => $context
                    ];
                    $options = [
                        'method' => 'POST',
                        'timeout' => 30,
                        'postData' => json_encode($requestData),
                        'headers' => [
                            'Content-Type' => 'application/json'
                        ],
                        'followRedirects' => true,
                        'sslVerifyCert' => false,
                        'proxy' => false
                    ];
                    $response = Http::request($url, $options);
                    if ($response === false) {
                        $attempts++;
                        if ($attempts < $this->maxRetries) {
                            sleep($this->retryDelay);
                            continue;
                        } else {
                            break;
                        }
                    }
                    $statusCode = Http::getLastStatusCode();
                    if ($statusCode >= 400) {
                        $attempts++;
                        if ($attempts < $this->maxRetries) {
                            sleep($this->retryDelay);
                            continue;
                        } else {
                            break;
                        }
                    }
                    $data = json_decode($response, true);
                    if (!$data || !is_array($data)) {
                        $attempts++;

                        if ($attempts < $this->maxRetries) {
                            sleep($this->retryDelay);
                            continue;
                        } else {
                            break;
                        }
                    }
                    if (!isset($data['answer'])) {
                        $data['answer'] = 'Ответ не предоставлен сервисом ИИ.';
                        $data['success'] = false;
                    }

                    if (!isset($data['sources'])) {
                        $data['sources'] = [];
                    }

                    if (!isset($data['success'])) {
                        $data['success'] = false;
                    }

                    return $data;
                } catch (\Exception $e) {
                    $lastError = $e;
                    $attempts++;

                    if ($attempts < $this->maxRetries) {
                        sleep($this->retryDelay);
                    }
                }
            }
        }
        return [
            'answer' => 'Произошла ошибка при выполнении интеллектуального поиска. Пожалуйста, попробуйте снова позже.',
            'sources' => [],
            'success' => false
        ];
    }

    /**
     * Check AI service availability
     * @return bool
     */
    public function isAvailable(): bool
    {
        try {
            $url = "{$this->aiServiceUrl}/";
            $options = [
                'method' => 'GET',
                'timeout' => 5,
                'followRedirects' => true,
                'sslVerifyCert' => false,
                'proxy' => false
            ];
            $response = Http::request($url, $options);
            if ($response === false) {
                return false;
            }
            $statusCode = Http::getLastStatusCode();
            if ($statusCode >= 400) {
                return false;
            }
            $data = json_decode($response, true);
            if (isset($data['status']) && $data['status'] === 'ok') {
                return true;
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}