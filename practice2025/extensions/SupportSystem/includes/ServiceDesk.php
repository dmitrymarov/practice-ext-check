<?php

namespace MediaWiki\Extension\SupportSystem;

use MediaWiki\MediaWikiServices;
use Http;
use FormatJson;
use MWException;

/**
 * Class for integration with Redmine (Service Desk)
 */
class ServiceDesk
{
    /** @var string The Redmine API URL */
    private $apiUrl;

    /** @var string The Redmine API key */
    private $apiKey;

    /**
     * Constructor
     */
    public function __construct()
    {
        $config = MediaWikiServices::getInstance()->getMainConfig();

        $this->apiUrl = $config->get('SupportSystemRedmineURL');
        $this->apiKey = $config->get('SupportSystemRedmineAPIKey');
    }

    /**
     * Create a ticket in Redmine
     * @param string $subject Ticket subject
     * @param string $description Ticket description
     * @param string $priority Ticket priority ('low', 'normal', 'high', 'urgent')
     * @param int|null $assignedTo User ID to assign the ticket to
     * @param int $projectId Project ID
     * @return array The created ticket
     * @throws MWException When ticket creation fails
     */
    public function createTicket(string $subject, string $description, string $priority = 'normal', int $assignedTo = null, int $projectId = 1): array
    {
        // Проверка сервиса Redmine перед отправкой запроса
        $testUrl = rtrim($this->apiUrl, '/');
        wfDebugLog('SupportSystem', "Testing connection to Redmine URL: $testUrl");

        $testResponse = Http::request($testUrl, [
            'method' => 'GET',
            'timeout' => 10,
            'followRedirects' => true,
            'sslVerifyCert' => false,
            'proxy' => false,
            'noProxy' => true,
            'curlOptions' => [
                CURLOPT_FAILONERROR => false, // Don't fail on 4xx errors
                CURLOPT_CONNECTTIMEOUT => 5   // Connect timeout
            ]
        ]);

        if ($testResponse === false) {
            wfDebugLog('SupportSystem', "Redmine connectivity test failed. Redmine might be unreachable.");
            throw new MWException('Redmine service is unreachable. Please check connection settings.');
        }

        wfDebugLog('SupportSystem', "Redmine connectivity test succeeded. Creating ticket...");

        // Формируем правильную структуру данных для Redmine API
        $priorityId = $this->getPriorityId($priority);
        $issueData = [
            'issue' => [
                'subject' => $subject,
                'description' => $description,
                'project_id' => $projectId,
                'priority_id' => $priorityId,
                'tracker_id' => 1,  // Bug
                'status_id' => 1    // New
            ]
        ];

        if ($assignedTo) {
            $issueData['issue']['assigned_to_id'] = $assignedTo;
        }

        $url = rtrim($this->apiUrl, '/') . "/issues.json";
        wfDebugLog('SupportSystem', "Redmine API URL for ticket creation: $url");

        // Делаем запрос с более детальными настройками curl
        $options = [
            'method' => 'POST',
            'timeout' => 30,
            'postData' => json_encode($issueData),
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Redmine-API-Key' => $this->apiKey
            ],
            'followRedirects' => true,
            'sslVerifyCert' => false,
            'proxy' => false,
            'noProxy' => true,
            'curlOptions' => [
                CURLOPT_FAILONERROR => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_VERBOSE => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]
        ];

        wfDebugLog('SupportSystem', "Request data: " . json_encode($issueData));

        // Используем обертку с try-catch для более безопасного HTTP запроса
        try {
            $response = Http::request($url, $options);

            if ($response === false) {
                wfDebugLog('SupportSystem', "Error connecting to Redmine API: No response");

                // Проверяем наличие ошибки в PHP error log
                $error = error_get_last();
                if ($error) {
                    wfDebugLog('SupportSystem', "PHP error: " . json_encode($error));
                }

                throw new MWException('Error connecting to Redmine: No response received');
            }

            wfDebugLog('SupportSystem', "Redmine API raw response: " . substr($response, 0, 500) . '...');

            $data = json_decode($response, true);

            // Проверяем наличие ошибок в ответе
            if (isset($data['errors'])) {
                $errors = implode(', ', $data['errors']);
                wfDebugLog('SupportSystem', "Redmine API returned errors: $errors");
                throw new MWException('Redmine API error: ' . $errors);
            }

            if (!isset($data['issue'])) {
                wfDebugLog('SupportSystem', "Invalid response format from Redmine: " . substr($response, 0, 500));
                throw new MWException('Failed to create ticket: Invalid response format');
            }

            wfDebugLog('SupportSystem', "Ticket created successfully: ID " . $data['issue']['id']);
            return $data['issue'];
        } catch (\Exception $e) {
            wfDebugLog('SupportSystem', "Exception during API request: " . $e->getMessage());
            throw new MWException('Error creating ticket: ' . $e->getMessage());
        }
    }

    /**
     * Get priority ID from name
     * @param string $priorityName Priority name
     * @return int Priority ID
     */
    private function getPriorityId(string $priorityName): int
    {
        $priorities = [
            'low' => 1,
            'normal' => 2,
            'high' => 3,
            'urgent' => 4
        ];

        return $priorities[strtolower($priorityName)] ?? 2;
    }
    
    /**
     * Get a ticket by ID
     * @param int $ticketId Ticket ID
     * @return array|null The ticket or null if not found
     */
    public function getTicket(int $ticketId): ?array
    {
        $url = rtrim($this->apiUrl, '/') . "/issues/{$ticketId}.json";
        $options = [
            'method' => 'GET',
            'timeout' => 30,
            'headers' => [
                'X-Redmine-API-Key' => $this->apiKey
            ],
            'followRedirects' => true,
            'sslVerifyCert' => false,
            'proxy' => false
        ];

        wfDebugLog('SupportSystem', "Getting ticket #$ticketId from Redmine");

        $response = Http::request($url, $options);
        if ($response === false) {
            wfDebugLog('SupportSystem', "Error retrieving ticket #$ticketId: No response");
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['issue'])) {
            wfDebugLog('SupportSystem', "Error retrieving ticket #$ticketId: " . substr($response, 0, 200));
            return null;
        }

        return $data['issue'];
    }
    
    /**
     * Add a comment to a ticket
     * @param int $ticketId Ticket ID
     * @param string $comment Comment text
     * @return bool Whether adding the comment was successful
     */
    public function addComment(int $ticketId, string $comment): bool
    {
        $issueData = [
            'issue' => [
                'notes' => $comment
            ]
        ];

        $url = rtrim($this->apiUrl, '/') . "/issues/{$ticketId}.json";

        $options = [
            'method' => 'PUT',
            'timeout' => 30,
            'postData' => json_encode($issueData),
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Redmine-API-Key' => $this->apiKey
            ],
            'followRedirects' => true,
            'sslVerifyCert' => false,
            'proxy' => false
        ];

        wfDebugLog('SupportSystem', "Adding comment to ticket #$ticketId");

        $response = Http::request($url, $options);
        if ($response === false) {
            wfDebugLog('SupportSystem', "Error adding comment to ticket #$ticketId: No response");
            return false;
        }

        // Для Redmine успешное обновление возвращает 204 No Content (пустой ответ)
        return true;
    }
    /**
     * Get all tickets
     * @return array The tickets
     */
    public function getAllTickets(): array
    {
        $url = rtrim($this->apiUrl, '/') . "/issues.json";
        $options = [
            'method' => 'GET',
            'timeout' => 30,
            'headers' => [
                'X-Redmine-API-Key' => $this->apiKey
            ],
            'followRedirects' => true,
            'sslVerifyCert' => false,
            'proxy' => false
        ];

        wfDebugLog('SupportSystem', "Getting all tickets from Redmine");

        $response = Http::request($url, $options);
        if ($response === false) {
            wfDebugLog('SupportSystem', "Error retrieving all tickets: No response");
            return [];
        }

        $data = json_decode($response, true);
        if (!isset($data['issues'])) {
            wfDebugLog('SupportSystem', "Error retrieving all tickets: " . substr($response, 0, 200));
            return [];
        }

        return $data['issues'];
    }


    /**
     * Attach a solution to a ticket
     * @param int $ticketId Ticket ID
     * @param string $solutionText Solution text
     * @param string $source Solution source
     * @return bool Whether attaching the solution was successful
     */
    public function attachSolution(int $ticketId, string $solutionText, string $source = 'unknown'): bool
    {
        $comment = "Найденное решение: {$solutionText}\n\nИсточник: {$source}";
        return $this->addComment($ticketId, $comment);
    }
}