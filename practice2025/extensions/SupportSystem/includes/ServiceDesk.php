<?php
namespace MediaWiki\Extension\SupportSystem;

use MediaWiki\MediaWikiServices;
use Http;
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
     * Create a ticket in Redmine using curl
     * 
     * @param string $subject Ticket subject
     * @param string $description Ticket description
     * @param string $priority Ticket priority ('red', 'orange', 'yellow', 'green')
     * @param int|null $assignedTo User ID to assign the ticket to
     * @param int $projectId Project ID
     * @return array The created ticket
     * @throws MWException When ticket creation fails
     */
    public function createTicket(string $subject, string $description, string $priority = 'yellow', int $assignedTo = null, int $projectId = 1): array
    {
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
        $jsonData = json_encode($issueData);

        $command = "curl -s -X POST -H \"Content-Type: application/json\" -H \"X-Redmine-API-Key: {$this->apiKey}\" -d " . escapeshellarg($jsonData) . " \"$url\"";
        $response = shell_exec($command);

        if (!$response) {
            throw new MWException("Empty response from Redmine API");
        }

        $data = json_decode($response, true);
        if (!isset($data['issue'])) {
            throw new MWException("Invalid response format from Redmine API");
        }

        return $data['issue'];
    }

    /**
     * Get priority ID from name
     * 
     * @param string $priorityName Priority name
     * @return int Priority ID
     */
    private function getPriorityId(string $priorityName): int
    {
        $priorities = [
            'red' => 1,     // High
            'orange' => 2,  // Urgent
            'yellow' => 3,  // Normal
            'green' => 4    // Low
        ];

        $priorityName = strtolower($priorityName);
        return $priorities[$priorityName] ?? 3; // Default to Normal
    }

    /**
     * Get a ticket by ID
     * 
     * @param int $ticketId Ticket ID
     * @return array|null The ticket or null if not found
     */
    public function getTicket(int $ticketId): ?array
    {
        $url = rtrim($this->apiUrl, '/') . "/issues/{$ticketId}.json?include=journals,attachments";

        $command = "curl -s -H \"X-Redmine-API-Key: {$this->apiKey}\" \"$url\"";
        $response = shell_exec($command);

        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['issue'])) {
            return null;
        }

        return $data['issue'];
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
        $issueData = [
            'issue' => [
                'notes' => $comment
            ]
        ];

        $url = rtrim($this->apiUrl, '/') . "/issues/{$ticketId}.json";
        $jsonData = json_encode($issueData);

        $command = "curl -s -X PUT -H \"Content-Type: application/json\" -H \"X-Redmine-API-Key: {$this->apiKey}\" -d " . escapeshellarg($jsonData) . " \"$url\"";
        $response = shell_exec($command);

        // If we received any response, consider it successful
        // (Redmine returns 204 No Content on successful updates)
        return $response !== false;
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
        $url = rtrim($this->apiUrl, '/') . "/issues.json?limit=$limit&offset=$offset";

        $command = "curl -s -H \"X-Redmine-API-Key: {$this->apiKey}\" \"$url\"";
        $response = shell_exec($command);

        if (!$response) {
            return [];
        }

        $data = json_decode($response, true);
        if (!isset($data['issues'])) {
            return [];
        }

        return $data['issues'];
    }

    /**
     * Upload a file to Redmine and get upload token
     * 
     * @param string $filePath Path to the file
     * @param string $fileName Optional filename (if different from the actual file)
     * @return string Upload token
     * @throws MWException When upload fails
     */
    public function uploadFile(string $filePath, string $fileName = ''): string
    {
        if (!file_exists($filePath)) {
            throw new MWException("File not found: $filePath");
        }

        $url = rtrim($this->apiUrl, '/') . "/uploads.json";

        if (empty($fileName)) {
            $fileName = basename($filePath);
        }

        // Simple curl command for file upload
        $command = "curl -s -X POST -H \"Content-Type: application/octet-stream\" " .
            "-H \"X-Redmine-API-Key: {$this->apiKey}\" " .
            "--data-binary @" . escapeshellarg($filePath) . " " .
            "\"$url?filename=" . urlencode($fileName) . "\"";

        $response = shell_exec($command);

        if (!$response) {
            throw new MWException("Empty response from Redmine API when uploading file");
        }

        $data = json_decode($response, true);
        if (!isset($data['upload']) || !isset($data['upload']['token'])) {
            throw new MWException("Invalid response format from Redmine API");
        }

        return $data['upload']['token'];
    }

    /**
     * Attach a file to an existing ticket
     * 
     * @param int $ticketId Ticket ID
     * @param string $filePath Path to the file
     * @param string $fileName Optional filename
     * @param string $contentType Content type of the file
     * @param string $comment Optional comment to add with the attachment
     * @return bool Success status
     * @throws MWException On error
     */
    public function attachFileToTicket(int $ticketId, string $filePath, string $fileName = '', string $contentType = '', string $comment = ''): bool
    {
        try {
            // Step 1: Upload file and get token
            $token = $this->uploadFile($filePath, $fileName);

            // Step 2: Update issue with uploaded file token
            if (empty($fileName)) {
                $fileName = basename($filePath);
            }

            if (empty($contentType)) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $contentType = $finfo->file($filePath);
            }

            $issueData = [
                'issue' => [
                    'notes' => $comment ?: 'Файл прикреплен',
                    'uploads' => [
                        [
                            'token' => $token,
                            'filename' => $fileName,
                            'content_type' => $contentType
                        ]
                    ]
                ]
            ];

            $url = rtrim($this->apiUrl, '/') . "/issues/{$ticketId}.json";
            $jsonData = json_encode($issueData);

            // Single curl command to attach file to ticket
            $command = "curl -s -X PUT -H \"Content-Type: application/json\" " .
                "-H \"X-Redmine-API-Key: {$this->apiKey}\" " .
                "-d " . escapeshellarg($jsonData) . " \"$url\"";

            $response = shell_exec($command);

            // Redmine returns 204 No Content on successful updates
            return true;
        } catch (MWException $e) {
            throw $e;
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
        $comment = "Найденное решение: {$solutionText}\n\nИсточник: {$source}";
        return $this->addComment($ticketId, $comment);
    }

    /**
     * Add a custom field to a ticket
     * 
     * @param int $ticketId Ticket ID
     * @param int $customFieldId Custom field ID
     * @param string|array $value Custom field value
     * @return bool Whether adding the custom field was successful
     */
    public function addCustomField(int $ticketId, int $customFieldId, $value): bool
    {
        $issueData = [
            'issue' => [
                'custom_fields' => [
                    [
                        'id' => $customFieldId,
                        'value' => $value
                    ]
                ]
            ]
        ];

        $url = rtrim($this->apiUrl, '/') . "/issues/{$ticketId}.json";
        $jsonData = json_encode($issueData);

        $command = "curl -s -X PUT -H \"Content-Type: application/json\" -H \"X-Redmine-API-Key: {$this->apiKey}\" -d " . escapeshellarg($jsonData) . " \"$url\"";
        $response = shell_exec($command);
        return $response !== false;
    }
}