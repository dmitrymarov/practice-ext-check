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
     */
    public function createTicket(string $subject, string $description, string $priority = 'normal', int $assignedTo = null, int $projectId = 1): array
    {
        if ($this->useMock) {
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

        $url = "{$this->apiUrl}/issues.json";

        $options = [
            'method' => 'POST',
            'timeout' => 10,
            'postData' => json_encode($issueData),
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Redmine-API-Key' => $this->apiKey
            ]
        ];

        $response = Http::request($url, $options);

        if ($response === false) {
            throw new MWException('Error connecting to Redmine');
        }

        $data = json_decode($response, true);

        if (!isset($data['issue'])) {
            throw new MWException('Failed to create ticket: ' . $response);
        }

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

        $url = "{$this->apiUrl}/issues/{$ticketId}.json";

        $options = [
            'method' => 'GET',
            'timeout' => 10,
            'headers' => [
                'X-Redmine-API-Key' => $this->apiKey
            ]
        ];

        $response = Http::request($url, $options);

        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);

        return $data['issue'] ?? null;
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

        $url = "{$this->apiUrl}/issues.json";

        $options = [
            'method' => 'GET',
            'timeout' => 10,
            'headers' => [
                'X-Redmine-API-Key' => $this->apiKey
            ]
        ];

        $response = Http::request($url, $options);

        if ($response === false) {
            return [];
        }

        $data = json_decode($response, true);

        return $data['issues'] ?? [];
    }

    /**
     * Update a ticket
     * @param int $ticketId Ticket ID
     * @param array $data Ticket data to update
     * @return bool Whether the update was successful
     */
    public function updateTicket(int $ticketId, array $data): bool
    {
        if ($this->useMock) {
            foreach ($this->mockTickets as $key => $ticket) {
                if ($ticket['id'] === $ticketId) {
                    $this->mockTickets[$key] = array_merge($ticket, $data);
                    $this->saveMockData();
                    return true;
                }
            }

            return false;
        }

        $issueData = [
            'issue' => $data
        ];

        $url = "{$this->apiUrl}/issues/{$ticketId}.json";

        $options = [
            'method' => 'PUT',
            'timeout' => 10,
            'postData' => json_encode($issueData),
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Redmine-API-Key' => $this->apiKey
            ]
        ];

        $response = Http::request($url, $options);

        return $response !== false;
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

        $url = "{$this->apiUrl}/issues/{$ticketId}.json";

        $options = [
            'method' => 'PUT',
            'timeout' => 10,
            'postData' => json_encode($issueData),
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Redmine-API-Key' => $this->apiKey
            ]
        ];

        $response = Http::request($url, $options);

        return $response !== false;
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