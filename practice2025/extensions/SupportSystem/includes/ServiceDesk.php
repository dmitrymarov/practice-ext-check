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
     * @param array $attachments Array of attachment tokens from uploadFile
     * @return array The created ticket
     * @throws MWException When ticket creation fails
     */
    public function createTicket(
        string $subject,
        string $description,
        string $priority = 'yellow',
        int $assignedTo = null,
        int $projectId = 1,
        array $attachments = []
    ): array {
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
        if ($assignedTo) { $issueData['issue']['assigned_to_id'] = $assignedTo; }
        if (!empty($attachments)) { $issueData['issue']['uploads'] = $attachments; }
        $url = rtrim($this->apiUrl, '/') . "/issues.json";
        $jsonData = json_encode($issueData);
        wfDebugLog('SupportSystem', "Creating ticket with data: " . json_encode($issueData, JSON_UNESCAPED_UNICODE));
        $command = "curl -s -X POST " .
            "-H \"Content-Type: application/json\" " .
            "-H \"X-Redmine-API-Key: {$this->apiKey}\" " .
            "-d " . escapeshellarg($jsonData) . " " .
            "\"$url\"";
        wfDebugLog('SupportSystem', "Create ticket command (redacted): " .
            preg_replace('/X-Redmine-API-Key: [^ ]+/', 'X-Redmine-API-Key: [REDACTED]', $command));
        $response = shell_exec($command);
        if (!$response) { throw new MWException("Empty response from Redmine API"); }
        wfDebugLog('SupportSystem', "Create ticket response: $response");
        $data = json_decode($response, true);
        if (!isset($data['issue'])) { throw new MWException("Invalid response format from Redmine API: " . $response); }
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
     * Upload a file to Redmine and get upload token
     * 
     * @param string $filePath Path to the file
     * @param string $fileName Optional filename (if different from the actual file)
     * @param string $contentType Content type of the file
     * @return array Upload info with token, filename and content_type
     * @throws MWException When upload fails
     */
    public function uploadFile(string $filePath, string $fileName = ''): array
    {
        if (!file_exists($filePath)) {
            wfDebugLog('SupportSystem', "Error: File not found: $filePath");
            throw new MWException("File not found: $filePath");
        }
        if (empty($fileName)) { $fileName = basename($filePath); }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $contentType = $finfo->file($filePath);
        $fileSize = filesize($filePath);
        wfDebugLog('SupportSystem', "Uploading file: $fileName, size: $fileSize bytes, type: $contentType");
        $url = rtrim($this->apiUrl, '/') . "/uploads.json";
        $encodedFilename = urlencode($fileName);
        $command = "curl -s -X POST -H \"Content-Type: application/octet-stream\" -H \"X-Redmine-API-Key: {$this->apiKey}\" " .
            "--data-binary @" . escapeshellarg($filePath) . " " .
            "\"$url?filename=$encodedFilename\"";
        wfDebugLog('SupportSystem', "File upload command (redacted): " .
            preg_replace('/X-Redmine-API-Key: [^ ]+/', 'X-Redmine-API-Key: [REDACTED]', $command));
        $response = shell_exec($command);
        if (!$response) {
            wfDebugLog('SupportSystem', "Error: Empty response from Redmine API when uploading file");
            throw new MWException("Empty response from Redmine API when uploading file");
        }
        wfDebugLog('SupportSystem', "Upload response: $response");
        $data = json_decode($response, true);
        if (!$data || !isset($data['upload']) || !isset($data['upload']['token'])) {
            wfDebugLog('SupportSystem', "Error: Invalid JSON response format: $response");
            throw new MWException("Invalid response format from Redmine API: $response");
        }
        wfDebugLog('SupportSystem', "Successfully uploaded file, received token: " . $data['upload']['token']);
        return [
            'token' => $data['upload']['token'],
            'filename' => $fileName,
            'content_type' => $contentType
        ];
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
            $uploadInfo = $this->uploadFile($filePath, $fileName, $contentType);
            $issueData = [
                'issue' => [
                    'notes' => $comment ?: 'Файл прикреплен',
                    'uploads' => [
                        [
                            'token' => $uploadInfo['token'],
                            'filename' => $uploadInfo['filename'],
                            'content_type' => $uploadInfo['content_type']
                        ]
                    ]
                ]
            ];
            $url = rtrim($this->apiUrl, '/') . "/issues/{$ticketId}.json";
            $jsonData = json_encode($issueData);
            $command = sprintf(
                'curl -s -X PUT -H "Content-Type: application/json" -H "X-Redmine-API-Key: %s" -d %s "%s"',
                $this->apiKey,
                escapeshellarg($jsonData),
                $url
            );
            wfDebugLog('SupportSystem', "Attach file command: " . preg_replace('/X-Redmine-API-Key: [^ ]+/', 'X-Redmine-API-Key: [REDACTED]', $command));
            $response = shell_exec($command);
            wfDebugLog('SupportSystem', "Attach response: " . ($response ?: 'Empty response (expected for successful update)'));
            return true;
        } catch (MWException $e) {
            wfDebugLog('SupportSystem', "Error attaching file: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Process uploaded files for a new ticket
     * 
     * @param array $files Files array ($_FILES)
     * @return array Array of file upload information for ticket creation
     */
    public function processUploadedFiles(array $files): array
    {
        $uploadInfos = [];
        if (empty($files)) {
            wfDebugLog('SupportSystem', "No files to process");
            return $uploadInfos;
        }
        wfDebugLog('SupportSystem', "Processing uploaded files");
        if (isset($files['ticket_files'])) {
            if (is_array($files['ticket_files']['name'])) {
                $fileCount = count($files['ticket_files']['name']);
                wfDebugLog('SupportSystem', "Processing $fileCount files");
                for ($i = 0; $i < $fileCount; $i++) {
                    if ($files['ticket_files']['error'][$i] === UPLOAD_ERR_OK) {
                        $tmpName = $files['ticket_files']['tmp_name'][$i];
                        $fileName = $files['ticket_files']['name'][$i];
                        try {
                            $uploadInfo = $this->uploadFile($tmpName, $fileName);
                            $uploadInfos[] = $uploadInfo;
                            wfDebugLog('SupportSystem', "File $fileName uploaded successfully");
                        } catch (MWException $e) { wfDebugLog('SupportSystem', "Error uploading file $fileName: " . $e->getMessage()); }
                    }
                }
            }
            else if ($files['ticket_files']['error'] === UPLOAD_ERR_OK) {
                $tmpName = $files['ticket_files']['tmp_name'];
                $fileName = $files['ticket_files']['name'];
                try {
                    $uploadInfo = $this->uploadFile($tmpName, $fileName);
                    $uploadInfos[] = $uploadInfo;
                    wfDebugLog('SupportSystem', "File $fileName uploaded successfully");
                } catch (MWException $e) { wfDebugLog('SupportSystem', "Error uploading file $fileName: " . $e->getMessage()); }
            }
        }
        return $uploadInfos;
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
        $command = sprintf(
            'curl -s -H "X-Redmine-API-Key: %s" "%s"',
            $this->apiKey,
            $url
        );
        wfDebugLog('SupportSystem', "Get ticket command: " . preg_replace('/X-Redmine-API-Key: [^ ]+/', 'X-Redmine-API-Key: [REDACTED]', $command));
        $response = shell_exec($command);
        if (!$response) { return null; }
        wfDebugLog('SupportSystem', "Get ticket response received (length: " . strlen($response) . ")");
        $data = json_decode($response, true);
        if (!isset($data['issue'])) { return null; }
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
        $command = sprintf(
            'curl -s -X PUT -H "Content-Type: application/json" -H "X-Redmine-API-Key: %s" -d %s "%s"',
            $this->apiKey,
            escapeshellarg($jsonData),
            $url
        );
        wfDebugLog('SupportSystem', "Add comment command: " . preg_replace('/X-Redmine-API-Key: [^ ]+/', 'X-Redmine-API-Key: [REDACTED]', $command));
        $response = shell_exec($command);
        wfDebugLog('SupportSystem', "Add comment response: " . ($response ?: 'Empty response (expected for successful update)'));
        return true;
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
        $command = sprintf(
            'curl -s -H "X-Redmine-API-Key: %s" "%s"',
            $this->apiKey,
            $url
        );
        wfDebugLog('SupportSystem', "Get all tickets command: " . preg_replace('/X-Redmine-API-Key: [^ ]+/', 'X-Redmine-API-Key: [REDACTED]', $command));
        $response = shell_exec($command);
        if (!$response) { return []; }
        wfDebugLog('SupportSystem', "Get all tickets response received (length: " . strlen($response) . ")");
        $data = json_decode($response, true);
        if (!isset($data['issues'])) {
            return [];
        }
        return $data['issues'];
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
}