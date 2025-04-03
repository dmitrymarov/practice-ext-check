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
     * Create a ticket in Redmine using PHP curl extension
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
        $attempts = 0;
        $lastError = null;
        while ($attempts < $this->maxRetryAttempts) {
            try {
                wfDebugLog('SupportSystem', "Attempt " . ($attempts + 1) . " to create ticket using PHP curl");
                $ch = curl_init($url);
                $jsonData = json_encode($issueData);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'X-Redmine-API-Key: ' . $this->apiKey,
                    'Content-Length: ' . strlen($jsonData)
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                wfDebugLog('SupportSystem', "Response HTTP code: $httpCode");
                wfDebugLog('SupportSystem', "Response: " . substr($response, 0, 500));
                if ($curlError) {
                    throw new MWException("cURL error: $curlError");
                }
                if ($httpCode >= 400) {
                    throw new MWException("HTTP error $httpCode: $response");
                }
                if (!$response) {
                    throw new MWException("Empty response from Redmine API");
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
            'red' => 1,     // Высший приоритет
            'orange' => 2,  // Высокий приоритет
            'yellow' => 3,  // Нормальный приоритет
            'green' => 4    // Низкий приоритет
        ];

        $priorityName = strtolower($priorityName);
        return $priorities[$priorityName] ?? 3; // По умолчанию Yellow (нормальный)
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
        $url = rtrim($this->apiUrl, '/') . "/issues/{$ticketId}.json?include=journals";

        try {
            $command = "curl -s -H \"X-Redmine-API-Key: {$this->apiKey}\" \"$url\"";
            wfDebugLog('SupportSystem', "Executing command: $command");
            $response = shell_exec($command);

            if (!$response) {
                wfDebugLog('SupportSystem', "Error getting ticket #$ticketId: empty response");
                return null;
            }

            $data = json_decode($response, true);
            if (!isset($data['issue'])) {
                wfDebugLog('SupportSystem', "Invalid response format for ticket #$ticketId");
                return null;
            }

            wfDebugLog('SupportSystem', "Successfully got ticket #$ticketId");
            return $data['issue'];
        } catch (\Exception $e) {
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
        try {
            $jsonData = json_encode($issueData);
            $command = "curl -s -X PUT \"$url\" -H \"Content-Type: application/json\" -H \"X-Redmine-API-Key: {$this->apiKey}\" -d .addslashes($jsonData)";
            $response = shell_exec($command);
            $exitCode = shell_exec("echo $?");
            if ($exitCode != '0') {
                wfDebugLog('SupportSystem', "Error adding comment to ticket #$ticketId: non-zero exit code");
                return false;
            }
            wfDebugLog('SupportSystem', "Comment added to ticket #$ticketId");
            return true;
        } catch (\Exception $e) {
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

        try {
            $command = "curl -s -H \"X-Redmine-API-Key: {$this->apiKey}\" \"$url\"";
            wfDebugLog('SupportSystem', "Executing command: $command");
            $response = shell_exec($command);

            if (!$response) {
                wfDebugLog('SupportSystem', "Error getting tickets: empty response");
                return [];
            }

            $data = json_decode($response, true);
            if (!isset($data['issues'])) {
                wfDebugLog('SupportSystem', "Invalid response format for tickets list");
                return [];
            }

            wfDebugLog('SupportSystem', "Got " . count($data['issues']) . " tickets");
            return $data['issues'];
        } catch (\Exception $e) {
            wfDebugLog('SupportSystem', "Exception getting tickets: " . $e->getMessage());
            return [];
        }
    }
    /**
     * Upload a file to Redmine and get an upload token
     * 
     * @param string $filePath Path to the file
     * @param string $fileName Optional filename (if different from the actual file)
     * @param string $contentType Content type of the file
     * @return string Upload token
     * @throws MWException When upload fails
     */
    public function uploadFile(string $filePath, string $fileName = '', string $contentType = ''): string
    {
        wfDebugLog('SupportSystem', "Загрузка файла: $filePath");
        if (!file_exists($filePath)) {
            throw new MWException("Файл не найден: $filePath");
        }
        $url = rtrim($this->apiUrl, '/') . "/uploads.json";
        if (empty($fileName)) {
            $fileName = basename($filePath);
        }
        if (empty($contentType)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $contentType = $finfo->file($filePath);
        }
        $command = 'curl -s -X POST ' .
            '-H "Content-Type: application/octet-stream" ' .
            '-H "X-Redmine-API-Key: ' . $this->apiKey . '" ' .
            '--data-binary @' . escapeshellarg($filePath) . ' ' .
            '"' . $url . '?filename=' . urlencode($fileName) . '"';
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new MWException("Ошибка выполнения curl команды для загрузки файла");
        }
        $response = implode('', $output);
        $data = json_decode($response, true);
        if (!isset($data['upload']) || !isset($data['upload']['token'])) {
            throw new MWException("Недопустимый формат ответа от Redmine API: " . substr($response, 0, 200));
        }
        $token = $data['upload']['token'];
        wfDebugLog('SupportSystem', "Файл успешно загружен, токен: $token");
        return $token;
    }
    /**
     * Attach a file to an existing ticket
     * 
     * @param int $ticketId Ticket ID
     * @param string $filePath Path to the file
     * @param string $fileName Optional filename (if different from the actual file)
     * @param string $contentType Content type of the file
     * @param string $comment Optional comment to add with the attachment
     * @return bool Success status
     * @throws MWException When attachment fails
     */
    public function attachFileToTicket(int $ticketId, string $filePath, string $fileName = '', string $contentType = '', string $comment = ''): bool
    {
        wfDebugLog('SupportSystem', "Прикрепление файла к тикету #$ticketId: $filePath");
        try {
            $token = $this->uploadFile($filePath, $fileName, $contentType);
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
            $jsonData = json_encode($issueData);
            $command = 'curl -s -X PUT ' .
                '-H "Content-Type: application/json" ' .
                '-H "X-Redmine-API-Key: ' . $this->apiKey . '" ' .
                '-d ' . escapeshellarg($jsonData) . ' ' .
                '"' . rtrim($this->apiUrl, '/') . "/issues/$ticketId.json" . '"';
            exec($command, $output, $exitCode);
            if ($exitCode !== 0) {
                throw new MWException("Ошибка выполнения curl команды для прикрепления файла");
            }
            wfDebugLog('SupportSystem', "Файл успешно прикреплен к тикету #$ticketId");
            return true;
        } catch (MWException $e) {
            wfDebugLog('SupportSystem', "Ошибка в attachFileToTicket: " . $e->getMessage());
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
        wfDebugLog('SupportSystem', "Attaching solution to ticket #$ticketId");
        $comment = "Найденное решение: {$solutionText}\n\nИсточник: {$source}";
        return $this->addComment($ticketId, $comment);
    }
}