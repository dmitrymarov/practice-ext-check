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

    /** @var bool Whether to use mock data */
    private $useMock;

    /** @var array Mock tickets data */
    private $mockTickets;

    /** @var int Next ticket ID for mock data */
    private $nextTicketId;

    /** @var string The path to the mock data file */
    private $mockDataFile;

    /**
     * Constructor
     */
    public function __construct()
    {
        $config = MediaWikiServices::getInstance()->getMainConfig();

        $this->apiUrl = $config->get('SupportSystemRedmineURL');
        $this->apiKey = $config->get('SupportSystemRedmineAPIKey');
        $this->useMock = $config->get('SupportSystemUseMock');

        if ($this->useMock) {
            $this->mockDataFile = __DIR__ . '/../data/mock_tickets.json';
            $this->loadMockData();
        }
    }

    /**
     * Load mock data from file
     */
    private function loadMockData(): void
    {
        if (file_exists($this->mockDataFile)) {
            $content = file_get_contents($this->mockDataFile);
            $data = FormatJson::decode($content, true);

            $this->mockTickets = $data['tickets'] ?? [];
            $this->nextTicketId = $data['nextId'] ?? 1;
        } else {
            $this->mockTickets = [];
            $this->nextTicketId = 1;
            $this->saveMockData();
        }
    }

    /**
     * Save mock data to file
     */
    private function saveMockData(): void
    {
        $data = [
            'tickets' => $this->mockTickets,
            'nextId' => $this->nextTicketId
        ];

        $content = FormatJson::encode($data, true);
        file_put_contents($this->mockDataFile, $content);
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
        // Log the ticket creation attempt
        wfDebugLog('SupportSystem', "Creating ticket: $subject, Priority: $priority");

        if ($this->useMock) {
            wfDebugLog('SupportSystem', "Using mock data for ticket creation");
            return $this->createMockTicket($subject, $description, $priority, $assignedTo, $projectId);
        }

        $priorityId = $this->getPriorityId($priority);

        $issueData = [
            'issue' => [
                'subject' => $subject,
                'description' => $description,
                'project_id' => $projectId,
                'priority_id' => $priorityId
            ]
        ];

        if ($assignedTo) {
            $issueData['issue']['assigned_to_id'] = $assignedTo;
        }

        $url = rtrim($this->apiUrl, '/') . "/issues.json";
        wfDebugLog('SupportSystem', "Redmine API URL: $url");

        $options = [
            'method' => 'POST',
            'timeout' => 30, // Increased timeout
            'postData' => json_encode($issueData),
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Redmine-API-Key' => $this->apiKey
            ]
        ];

        // Log the request data
        wfDebugLog('SupportSystem', "Request data: " . json_encode($issueData));

        // Make the API request
        $response = Http::request($url, $options);

        if ($response === false) {
            wfDebugLog('SupportSystem', "Error connecting to Redmine: No response");
            throw new MWException('Error connecting to Redmine: No response received');
        }

        // Log the response
        wfDebugLog('SupportSystem', "Redmine response: $response");

        $data = json_decode($response, true);

        if (!isset($data['issue'])) {
            wfDebugLog('SupportSystem', "Failed to create ticket: " . substr($response, 0, 500));
            throw new MWException('Failed to create ticket: ' . substr($response, 0, 500));
        }

        wfDebugLog('SupportSystem', "Ticket created successfully: ID " . $data['issue']['id']);
        return $data['issue'];
    }

    /**
     * Create a mock ticket
     * @param string $subject Ticket subject
     * @param string $description Ticket description
     * @param string $priority Ticket priority
     * @param int|null $assignedTo User ID to assign the ticket to
     * @param int $projectId Project ID
     * @return array The created ticket
     */
    private function createMockTicket(string $subject, string $description, string $priority = 'normal', int $assignedTo = null, int $projectId = 1): array
    {
        $ticket = [
            'id' => $this->nextTicketId,
            'subject' => $subject,
            'description' => $description,
            'priority' => $priority,
            'status' => 'new',
            'created_on' => wfTimestamp(TS_ISO_8601),
            'project_id' => $projectId,
            'comments' => []
        ];

        if ($assignedTo) {
            $ticket['assigned_to'] = $assignedTo;
        }

        $this->mockTickets[] = $ticket;
        $this->nextTicketId++;
        $this->saveMockData();

        return $ticket;
    }

    /**
     * Get a ticket by ID
     * @param int $ticketId Ticket ID
     * @return array|null The ticket or null if not found
     */
    public function getTicket(int $ticketId): ?array
    {
        if ($this->useMock) {
            foreach ($this->mockTickets as $ticket) {
                if ($ticket['id'] === $ticketId) {
                    return $ticket;
                }
            }

            return null;
        }

        $url = rtrim($this->apiUrl, '/') . "/issues/{$ticketId}.json";

        $options = [
            'method' => 'GET',
            'timeout' => 30,
            'headers' => [
                'X-Redmine-API-Key' => $this->apiKey
            ]
        ];

        $response = Http::request($url, $options);

        if ($response === false) {
            wfDebugLog('SupportSystem', "Error retrieving ticket #$ticketId: No response");
            return null;
        }

        $data = json_decode($response, true);

        if (!isset($data['issue'])) {
            wfDebugLog('SupportSystem', "Error retrieving ticket #$ticketId: " . substr($response, 0, 100));
            return null;
        }

        return $data['issue'];
    }

    /**
     * Get all tickets
     * @return array The tickets
     */
    public function getAllTickets(): array
    {
        if ($this->useMock) {
            return $this->mockTickets;
        }

        $url = rtrim($this->apiUrl, '/') . "/issues.json";

        $options = [
            'method' => 'GET',
            'timeout' => 30,
            'headers' => [
                'X-Redmine-API-Key' => $this->apiKey
            ]
        ];

        $response = Http::request($url, $options);

        if ($response === false) {
            wfDebugLog('SupportSystem', "Error retrieving all tickets: No response");
            return [];
        }

        $data = json_decode($response, true);

        if (!isset($data['issues'])) {
            wfDebugLog('SupportSystem', "Error retrieving all tickets: " . substr($response, 0, 100));
            return [];
        }

        return $data['issues'];
    }

    /**
     * Add a comment to a ticket
     * @param int $ticketId Ticket ID
     * @param string $comment Comment text
     * @return bool Whether adding the comment was successful
     */
    public function addComment(int $ticketId, string $comment): bool
    {
        if ($this->useMock) {
            foreach ($this->mockTickets as $key => $ticket) {
                if ($ticket['id'] === $ticketId) {
                    if (!isset($this->mockTickets[$key]['comments'])) {
                        $this->mockTickets[$key]['comments'] = [];
                    }

                    $this->mockTickets[$key]['comments'][] = [
                        'text' => $comment,
                        'created_on' => wfTimestamp(TS_ISO_8601)
                    ];

                    $this->saveMockData();
                    return true;
                }
            }

            return false;
        }

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
            ]
        ];

        $response = Http::request($url, $options);

        if ($response === false) {
            wfDebugLog('SupportSystem', "Error adding comment to ticket #$ticketId: No response");
            return false;
        }

        // For Redmine, a successful update returns 204 No Content
        return true;
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
}