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
        $command = "curl -s -X POST " .
            "-H \"Content-Type: application/json\" " .
            "-H \"X-Redmine-API-Key: {$this->apiKey}\" " .
            "-d " . escapeshellarg($jsonData) . " " .
            "\"$url\"";
        $response = shell_exec($command);
        if (!$response) {
            throw new MWException("Empty response from Redmine API");
        }
        $data = json_decode($response, true);
        if (!isset($data['issue'])) {
            throw new MWException("Invalid response format from Redmine API: " . $response);
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
        return $priorities[$priorityName] ?? 3;
    }

    /**
     * Upload a file to Redmine and get upload token
     * 
     * @param string $filePath Path to the file
     * @param string $fileName Optional filename (if different from the actual file)
     * @return array Upload info with token, filename and content_type
     * @throws MWException When upload fails
     */
    public function uploadFile(string $filePath, string $fileName = ''): array
    {
        if (!file_exists($filePath)) {
            throw new MWException("Файл не найден: $filePath");
        }
        if (empty($fileName)) { $fileName = basename($filePath); }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $contentType = $finfo->file($filePath);
        $url = rtrim($this->apiUrl, '/') . "/uploads.json";
        $encodedFilename = urlencode($fileName);
        $command = "curl -s -X POST -H \"Content-Type: application/octet-stream\" -H \"X-Redmine-API-Key: {$this->apiKey}\" " .
            "--data-binary @" . escapeshellarg($filePath) . " " .
            "\"$url?filename=$encodedFilename\"";
        $response = shell_exec($command);
        if (!$response) {
            throw new MWException("Пустой ответ от Redmine API при загрузке файла");
        }
        $data = json_decode($response, true);
        if (!$data || !isset($data['upload']) || !isset($data['upload']['token'])) {
            throw new MWException("Неверный формат ответа от Redmine API: $response");
        }
        return [
            'token' => $data['upload']['token'],
            'filename' => $fileName,
            'content_type' => $contentType
        ];
    }

    /**
     * Attach files to an existing ticket
     * 
     * @param int $ticketId Ticket ID
     * @param array $attachments Array of attachment tokens from uploadFile
     * @param string $comment Optional comment to add with the attachments
     * @return bool Success status
     * @throws MWException When attachment fails
     */
    public function attachFilesToTicket(int $ticketId, array $attachments, string $comment = ''): bool
    {
        if (empty($attachments)) { return false; }
        $issueData = [
            'issue' => [
                'notes' => $comment ?: 'Файлы прикреплены к заявке',
                'uploads' => $attachments
            ]
        ];
        $url = rtrim($this->apiUrl, '/') . "/issues/{$ticketId}.json";
        $jsonData = json_encode($issueData);
        $command = "curl -s -X PUT " .
            "-H \"Content-Type: application/json\" " .
            "-H \"X-Redmine-API-Key: {$this->apiKey}\" " .
            "-d " . escapeshellarg($jsonData) . " " .
            "\"$url\"";
        $response = shell_exec($command);
        if (empty($response)) { return true; }
        $data = json_decode($response, true);
        if (isset($data['errors'])) {
            throw new MWException("Failed to attach files: " . implode(", ", $data['errors']));
        }
        return true;
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
    public function attachFileToTicket(
        int $ticketId,
        string $filePath,
        string $fileName = '',
        string $contentType = '',
        string $comment = ''
    ): bool {
        try {
            $uploadInfo = $this->uploadFile($filePath, $fileName);
            $issueData = [
                'issue' => [
                    'notes' => $comment ?: 'Файл прикреплен',
                    'uploads' => [
                        [
                            'token' => $uploadInfo['token'],
                            'filename' => $uploadInfo['filename'],
                            'content_type' => $contentType ?: $uploadInfo['content_type']
                        ]
                    ]
                ]
            ];
            $url = rtrim($this->apiUrl, '/') . "/issues/{$ticketId}.json";
            $jsonData = json_encode($issueData);
            $command = "curl -s -X PUT -H \"Content-Type: application/json\" -H \"X-Redmine-API-Key: {$this->apiKey}\" " .
                "-d " . escapeshellarg($jsonData) . " \"$url\"";
            shell_exec($command);
            return true;
        } catch (MWException $e) { throw $e; }
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
        if (!$response) { return null; }
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
        $issueData = ['issue' => [ 'notes' => $comment ]
        ];
        $url = rtrim($this->apiUrl, '/') . "/issues/{$ticketId}.json";
        $jsonData = json_encode($issueData);
        $command = "curl -s -X PUT -H \"Content-Type: application/json\" -H \"X-Redmine-API-Key: {$this->apiKey}\" " .
            "-d " . escapeshellarg($jsonData) . " \"$url\"";
        shell_exec($command);
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
        $command = "curl -s -H \"X-Redmine-API-Key: {$this->apiKey}\" \"$url\"";
        $response = shell_exec($command);
        if (!$response) { return []; }
        $data = json_decode($response, true);
        if (!isset($data['issues'])) { return []; }
        return $data['issues'];
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
            return $uploadInfos;
        }

        foreach ($files as $fieldName => $fileInfo) {
            if (is_array($fileInfo['name'])) {
                $fileCount = count($fileInfo['name']);
                for ($i = 0; $i < $fileCount; $i++) {
                    if ($fileInfo['error'][$i] === UPLOAD_ERR_OK && !empty($fileInfo['tmp_name'][$i])) {
                        $tmpName = $fileInfo['tmp_name'][$i];
                        $fileName = $fileInfo['name'][$i];
                        try {
                            $uploadInfo = $this->uploadFile($tmpName, $fileName);
                            $uploadInfos[] = $uploadInfo;
                        } catch (MWException $e) {
                        }
                    }
                }
            } else {
                if ($fileInfo['error'] === UPLOAD_ERR_OK && !empty($fileInfo['tmp_name'])) {
                    $tmpName = $fileInfo['tmp_name'];
                    $fileName = $fileInfo['name'];
                    try {
                        $uploadInfo = $this->uploadFile($tmpName, $fileName);
                        $uploadInfos[] = $uploadInfo;
                    } catch (MWException $e) {
                    }
                }
            }
        }
        return $uploadInfos;
    }
    
    /**
     * Attach a solution to a ticket
     * 
     * @param int $ticketId Ticket ID
     * @param string $solution Solution text
     * @param string $source Source of the solution
     * @return bool Success status
     */
    public function attachSolution(int $ticketId, string $solution, string $source = 'unknown'): bool
    {
        try {
            if (empty($solution)) { return false; }
            $comment = "Решение из источника: $source\n\n" . substr($solution, 0, 30000);
            $issueData = [
                'issue' => [
                    'notes' => $comment
                ]
            ];
            $url = rtrim($this->apiUrl, '/') . "/issues/{$ticketId}.json";
            $jsonData = json_encode($issueData);
            $command = "curl -s -X PUT " .
                "-H \"Content-Type: application/json\" " .
                "-H \"X-Redmine-API-Key: {$this->apiKey}\" " .
                "-d " . escapeshellarg($jsonData) . " " .
                "\"$url\"";
            $response = shell_exec($command);
            if (empty($response)) { return true; }
            $data = json_decode($response, true);
            if (isset($data['errors'])) {
                wfDebugLog('supportSystem', 'Error attaching solution: ' . implode(', ', $data['errors']));
                return false;
            }
            return true;
        } catch (\Exception $e) {
            wfDebugLog('supportSystem', 'Exception in attachSolution: ' . $e->getMessage());
            return false;
        }
    }
}