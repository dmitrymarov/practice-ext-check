<?php
namespace MediaWiki\Extension\SupportSystem;

use MediaWiki\MediaWikiServices;
use Http;
use MWException;

/**
 * Class for integration with Redmine (Service Desk)
 * 
 * Этот класс реализует интеграцию MediaWiki с API Redmine для управления тикетами.
 */
class ServiceDesk
{
    /** @var string The Redmine API URL */
    private $apiUrl;

    /** @var string The Redmine API key */
    private $apiKey;

    /** @var int Max retry attempts */
    private $maxRetryAttempts = 3;

    /** @var int Retry delay in seconds */
    private $retryDelay = 2;

    /**
     * Constructor
     */
    public function __construct()
    {
        $config = MediaWikiServices::getInstance()->getMainConfig();
        $this->apiUrl = $config->get('SupportSystemRedmineURL');
        $this->apiKey = $config->get('SupportSystemRedmineAPIKey');
        wfDebugLog('SupportSystem', "ServiceDesk initialized with URL: {$this->apiUrl}");
    }

    /**
     * Create a ticket in Redmine
     * 
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
        wfDebugLog('SupportSystem', "Creating ticket: '$subject', priority: $priority");
        $priorityId = $this->getPriorityId($priority);
        $issueData = [
            'issue' => [
                'subject' => $subject,
                'description' => $description,
                'project_id' => $projectId,
                'priority_id' => $priorityId,
                'tracker_id' => 1,
                'status_id' => 1
            ]
        ];
        if ($assignedTo) {
            $issueData['issue']['assigned_to_id'] = $assignedTo;
        }
        $url = rtrim($this->apiUrl, '/') . "/issues.json";
        wfDebugLog('SupportSystem', "Request URL: $url");
        wfDebugLog('SupportSystem', "Request data: " . json_encode($issueData));
        $options = [
            'method' => 'POST',
            'timeout' => 30,
            'postData' => json_encode($issueData),
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Redmine-API-Key' => $this->apiKey
            ],
            'followRedirects' => true,
            'sslVerifyCert' => false
        ];
        $attempts = 0;
        $lastError = null;
        while ($attempts < $this->maxRetryAttempts) {
            try {
                wfDebugLog('SupportSystem', "Attempt " . ($attempts + 1) . " to create ticket");
                $response = Http::request($url, $options);
                $statusCode = Http::getLastStatusCode();
                wfDebugLog('SupportSystem', "Response status: $statusCode");
                if ($response) {
                    wfDebugLog('SupportSystem', "Response body (first 500 chars): " . substr($response, 0, 500));
                } else {
                    wfDebugLog('SupportSystem', "Empty response received");
                }
                if ($statusCode >= 400) {
                    throw new MWException("Redmine API error: Status $statusCode - Response: $response");
                }
                $data = json_decode($response, true);
                if (!isset($data['issue'])) {
                    throw new MWException("Invalid response format from Redmine API: " . substr($response, 0, 200));
                }
                wfDebugLog('SupportSystem', "Ticket created successfully, ID: " . $data['issue']['id']);
                return $data['issue'];
            } catch (MWException $e) {
                $lastError = $e;
                wfDebugLog('SupportSystem', "Exception in createTicket: " . $e->getMessage());

                $attempts++;
                if ($attempts < $this->maxRetryAttempts) {
                    sleep($this->retryDelay);
                    continue;
                }
            }
        }
        throw new MWException("Failed to create ticket after {$this->maxRetryAttempts} attempts: " .
            ($lastError ? $lastError->getMessage() : "Unknown error"));
    }

    /**
     * Get priority ID from name
     * 
     * @param string $priorityName Priority name
     * @return int Priority ID
     */
    private function getPriorityId(string $priorityName): int
    {
        // Приоритеты в Redmine
        $priorities = [
            'Red' => 1,
            'Yellow' => 2,
            'Green' => 3
        ];

        return $priorities[strtolower($priorityName)] ?? 2; // По умолчанию Normal
    }

    /**
     * Get a ticket by ID
     * 
     * @param int $ticketId Ticket ID
     * @return array|null The ticket or null if not found
     */
    public function getTicket(int $ticketId): ?array
    {
        wfDebugLog('SupportSystem', "Getting ticket #$ticketId");

        // Формируем URL согласно документации Redmine API
        $url = rtrim($this->apiUrl, '/') . "/issues/{$ticketId}.json?include=journals";

        $options = [
            'method' => 'GET',
            'timeout' => 30,
            'headers' => [
                'X-Redmine-API-Key' => $this->apiKey
            ],
            'followRedirects' => true,
            'sslVerifyCert' => false
        ];

        try {
            $response = Http::request($url, $options);
            $statusCode = Http::getLastStatusCode();

            if ($response === false || $statusCode >= 400) {
                wfDebugLog('SupportSystem', "Error getting ticket #$ticketId: status $statusCode");
                return null;
            }

            $data = json_decode($response, true);
            if (!isset($data['issue'])) {
                wfDebugLog('SupportSystem', "Invalid response format for ticket #$ticketId");
                return null;
            }

            wfDebugLog('SupportSystem', "Successfully got ticket #$ticketId");
            return $data['issue'];

        } catch (Exception $e) {
            wfDebugLog('SupportSystem', "Exception getting ticket #$ticketId: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Add a comment to a ticket
     * 
     * @param int $ticketId Ticket ID
     * @param string $comment Comment text
     * @return bool Whether adding the comment was successful
     */
    public function addComment(int $ticketId, string $comment): bool
    {
        wfDebugLog('SupportSystem', "Adding comment to ticket #$ticketId");
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
            'sslVerifyCert' => false
        ];

        try {
            $response = Http::request($url, $options);
            $statusCode = Http::getLastStatusCode();

            if ($response === false || $statusCode >= 400) {
                wfDebugLog('SupportSystem', "Error adding comment to ticket #$ticketId: status $statusCode");
                return false;
            }

            wfDebugLog('SupportSystem', "Comment added to ticket #$ticketId");
            return true;

        } catch (Exception $e) {
            wfDebugLog('SupportSystem', "Exception adding comment: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all tickets
     * 
     * @param int $limit Maximum number of tickets to return
     * @param int $offset Offset for pagination
     * @return array The tickets
     */
    public function getAllTickets(int $limit = 25, int $offset = 0): array
    {
        wfDebugLog('SupportSystem', "Getting all tickets (limit: $limit, offset: $offset)");
        $url = rtrim($this->apiUrl, '/') . "/issues.json?limit=$limit&offset=$offset";
        $options = [
            'method' => 'GET',
            'timeout' => 30,
            'headers' => [
                'X-Redmine-API-Key' => $this->apiKey
            ],
            'followRedirects' => true,
            'sslVerifyCert' => false
        ];
        try {
            $response = Http::request($url, $options);
            $statusCode = Http::getLastStatusCode();
            if ($response === false || $statusCode >= 400) {
                wfDebugLog('SupportSystem', "Error getting tickets: status $statusCode");
                return [];
            }
            $data = json_decode($response, true);
            if (!isset($data['issues'])) {
                wfDebugLog('SupportSystem', "Invalid response format for tickets list");
                return [];
            }
            wfDebugLog('SupportSystem', "Got " . count($data['issues']) . " tickets");
            return $data['issues'];
        } catch (Exception $e) {
            wfDebugLog('SupportSystem', "Exception getting tickets: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Attach a solution to a ticket
     * 
     * @param int $ticketId Ticket ID
     * @param string $solutionText Solution text
     * @param string $source Solution source
     * @return bool Whether attaching the solution was successful
     */
    public function attachSolution(int $ticketId, string $solutionText, string $source = 'unknown'): bool
    {
        wfDebugLog('SupportSystem', "Attaching solution to ticket #$ticketId");

        $comment = "Найденное решение: {$solutionText}\n\nИсточник: {$source}";
        return $this->addComment($ticketId, $comment);
    }
}