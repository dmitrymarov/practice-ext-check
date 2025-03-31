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

        // Initialize Redmine connection
        $this->initializeConnection();
    }

    /**
     * Initialize connection by testing and resolving Redmine URL
     * 
     * @return bool Success status
     */
    private function initializeConnection()
    {
        wfDebugLog('SupportSystem', "Initializing Redmine connection to URL: {$this->apiUrl}");

        // Try primary URL
        $testResult = $this->testRedmineConnection();

        if ($testResult['success']) {
            wfDebugLog('SupportSystem', "Redmine connection successful.");
            return true;
        }

        // If primary URL fails, try alternative connection methods
        wfDebugLog('SupportSystem', "Primary Redmine connection failed. Trying alternative connection.");

        // Try Docker service name
        $dockerUrl = 'http://redmine:3000';
        if ($this->apiUrl !== $dockerUrl) {
            $this->apiUrl = $dockerUrl;
            $testResult = $this->testRedmineConnection();
            if ($testResult['success']) {
                wfDebugLog('SupportSystem', "Redmine connection successful via Docker service name: $dockerUrl");
                return true;
            }
        }

        // Try IP address in Docker network - common Docker bridge IP
        $ipUrl = 'http://172.17.0.1:3000';
        if ($this->apiUrl !== $ipUrl) {
            $this->apiUrl = $ipUrl;
            $testResult = $this->testRedmineConnection();
            if ($testResult['success']) {
                wfDebugLog('SupportSystem', "Redmine connection successful via IP address: $ipUrl");
                return true;
            }
        }

        // Try localhost for local development
        $localhostUrl = 'http://localhost:3000';
        if ($this->apiUrl !== $localhostUrl) {
            $this->apiUrl = $localhostUrl;
            $testResult = $this->testRedmineConnection();
            if ($testResult['success']) {
                wfDebugLog('SupportSystem', "Redmine connection successful via localhost: $localhostUrl");
                return true;
            }
        }

        // Try host machine from Docker container
        $hostUrl = 'http://172.29.46.60:3000';
        if ($this->apiUrl !== $hostUrl) {
            $this->apiUrl = $hostUrl;
            $testResult = $this->testRedmineConnection();
            if ($testResult['success']) {
                wfDebugLog('SupportSystem', "Redmine connection successful via host.docker.internal: $hostUrl");
                return true;
            }
        }

        // Try Docker container IP by getting gateway IP and hostname
        try {
            $dockerHostIp = trim(shell_exec('ip route | grep default | cut -d " " -f 3'));
            if ($dockerHostIp) {
                $dockerIpUrl = "http://$dockerHostIp:3000";
                if ($this->apiUrl !== $dockerIpUrl) {
                    $this->apiUrl = $dockerIpUrl;
                    $testResult = $this->testRedmineConnection();
                    if ($testResult['success']) {
                        wfDebugLog('SupportSystem', "Redmine connection successful via Docker host IP: $dockerIpUrl");
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            wfDebugLog('SupportSystem', "Failed to get Docker host IP: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Test connection to Redmine server with retry logic
     * @return array Success status and message
     */
    private function testRedmineConnection(): array
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $this->maxRetryAttempts) {
            try {
                $testUrl = rtrim($this->apiUrl, '/');

                $options = [
                    'method' => 'GET',
                    'timeout' => 5,
                    'headers' => [
                        'X-Redmine-API-Key' => $this->apiKey
                    ],
                    'followRedirects' => true,
                    'sslVerifyCert' => false,
                    'proxy' => false,
                    'noProxy' => true,
                    'curlOptions' => [
                        CURLOPT_CONNECTTIMEOUT => 3,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false
                    ]
                ];

                wfDebugLog('SupportSystem', "Testing Redmine connection to: $testUrl (Attempt " . ($attempts + 1) . ")");
                $response = Http::request($testUrl, $options);

                if ($response === false) {
                    wfDebugLog('SupportSystem', "Redmine connection test failed: No response (Attempt " . ($attempts + 1) . ")");
                    $attempts++;
                    sleep($this->retryDelay);
                    continue;
                }

                // Check for error status codes
                $statusCode = Http::getLastStatusCode();
                if ($statusCode >= 400) {
                    wfDebugLog('SupportSystem', "Redmine connection test failed with status code: $statusCode (Attempt " . ($attempts + 1) . ")");
                    $attempts++;
                    sleep($this->retryDelay);
                    continue;
                }

                // If we got any response, it's a good sign
                wfDebugLog('SupportSystem', "Redmine connection test succeeded on attempt " . ($attempts + 1));
                return [
                    'success' => true,
                    'message' => 'Connection successful'
                ];
            } catch (\Exception $e) {
                $lastException = $e;
                wfDebugLog('SupportSystem', "Redmine connection test exception: " . $e->getMessage() . " (Attempt " . ($attempts + 1) . ")");
                $attempts++;

                if ($attempts < $this->maxRetryAttempts) {
                    sleep($this->retryDelay);
                }
            }
        }

        $errorMessage = $lastException ? $lastException->getMessage() : 'Max retries exceeded';
        return [
            'success' => false,
            'message' => $errorMessage
        ];
    }

    /**
     * Create a ticket in Redmine with retry logic
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
        // Make sure we have a working connection to Redmine first
        $this->initializeConnection();

        // Verify connection before proceeding
        $testResponse = $this->testRedmineConnection();
        if (!$testResponse['success']) {
            wfDebugLog('SupportSystem', "Redmine service is unreachable during ticket creation. Error: " . $testResponse['message']);
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

        // Enhanced HTTP request with improved error handling
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

        // Multi-attempt ticket creation with detailed error reporting
        $attempts = 0;
        $lastError = null;

        while ($attempts < $this->maxRetryAttempts) {
            try {
                wfDebugLog('SupportSystem', "Creating ticket, attempt " . ($attempts + 1));
                $response = Http::request($url, $options);

                if ($response === false) {
                    $error = error_get_last();
                    $errorMessage = $error ? json_encode($error) : 'No response received';
                    wfDebugLog('SupportSystem', "Error in ticket creation attempt " . ($attempts + 1) . ": " . $errorMessage);
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    throw new MWException('Error connecting to Redmine: ' . $errorMessage);
                }

                $statusCode = Http::getLastStatusCode();
                if ($statusCode >= 400) {
                    wfDebugLog('SupportSystem', "Redmine API returned error status $statusCode on attempt " . ($attempts + 1) . ": $response");
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    throw new MWException("Redmine API returned error status: $statusCode - $response");
                }

                wfDebugLog('SupportSystem', "Redmine API response received successfully");
                $data = json_decode($response, true);

                if (!isset($data['issue'])) {
                    wfDebugLog('SupportSystem', "Invalid response format from Redmine: " . substr($response, 0, 500));
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    throw new MWException('Invalid response format from Redmine: ' . substr($response, 0, 100));
                }

                wfDebugLog('SupportSystem', "Ticket created successfully: ID " . $data['issue']['id']);
                return $data['issue'];
            } catch (\Exception $e) {
                $lastError = $e;
                wfDebugLog('SupportSystem', "Exception during API request on attempt " . ($attempts + 1) . ": " . $e->getMessage());
                $attempts++;

                if ($attempts < $this->maxRetryAttempts) {
                    sleep($this->retryDelay);
                }
            }
        }

        // If all attempts fail, throw an exception with detailed error information
        if ($lastError) {
            throw new MWException('Error creating ticket after ' . $this->maxRetryAttempts . ' attempts: ' . $lastError->getMessage());
        } else {
            throw new MWException('Unknown error creating ticket after ' . $this->maxRetryAttempts . ' attempts');
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
        // Make sure we have a working connection to Redmine first
        $this->initializeConnection();

        $url = rtrim($this->apiUrl, '/') . "/issues/{$ticketId}.json";
        $options = [
            'method' => 'GET',
            'timeout' => 30,
            'headers' => [
                'X-Redmine-API-Key' => $this->apiKey
            ],
            'followRedirects' => true,
            'sslVerifyCert' => false,
            'proxy' => false,
            'curlOptions' => [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]
        ];

        wfDebugLog('SupportSystem', "Getting ticket #$ticketId from Redmine");

        // Add retry logic for better reliability
        $attempts = 0;
        while ($attempts < $this->maxRetryAttempts) {
            try {
                $response = Http::request($url, $options);

                if ($response === false) {
                    wfDebugLog('SupportSystem', "Error retrieving ticket #$ticketId: No response (Attempt " . ($attempts + 1) . ")");
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    return null;
                }

                $statusCode = Http::getLastStatusCode();
                if ($statusCode >= 400) {
                    wfDebugLog('SupportSystem', "Error retrieving ticket #$ticketId: Status $statusCode (Attempt " . ($attempts + 1) . ")");
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    return null;
                }

                $data = json_decode($response, true);
                if (!isset($data['issue'])) {
                    wfDebugLog('SupportSystem', "Error retrieving ticket #$ticketId: " . substr($response, 0, 200));
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    return null;
                }

                return $data['issue'];
            } catch (\Exception $e) {
                wfDebugLog('SupportSystem', "Exception retrieving ticket #$ticketId: " . $e->getMessage() . " (Attempt " . ($attempts + 1) . ")");
                $attempts++;

                if ($attempts < $this->maxRetryAttempts) {
                    sleep($this->retryDelay);
                }
            }
        }

        return null;
    }

    /**
     * Add a comment to a ticket
     * @param int $ticketId Ticket ID
     * @param string $comment Comment text
     * @return bool Whether adding the comment was successful
     */
    public function addComment(int $ticketId, string $comment): bool
    {
        // Make sure we have a working connection to Redmine first
        $this->initializeConnection();

        // Verify connection before proceeding
        $connectionTest = $this->testRedmineConnection();
        if (!$connectionTest['success']) {
            wfDebugLog('SupportSystem', "Cannot add comment: Redmine connection test failed");
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
            ],
            'followRedirects' => true,
            'sslVerifyCert' => false,
            'proxy' => false,
            'curlOptions' => [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]
        ];

        wfDebugLog('SupportSystem', "Adding comment to ticket #$ticketId");

        // Add retry logic for better reliability
        $attempts = 0;
        while ($attempts < $this->maxRetryAttempts) {
            try {
                $response = Http::request($url, $options);

                if ($response === false) {
                    wfDebugLog('SupportSystem', "Error adding comment to ticket #$ticketId: No response (Attempt " . ($attempts + 1) . ")");
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    return false;
                }

                $statusCode = Http::getLastStatusCode();
                if ($statusCode >= 400) {
                    wfDebugLog('SupportSystem', "Error adding comment to ticket #$ticketId: Status $statusCode (Attempt " . ($attempts + 1) . ")");
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    return false;
                }

                // For Redmine success is typically status 200/204
                return true;
            } catch (\Exception $e) {
                wfDebugLog('SupportSystem', "Exception adding comment to ticket #$ticketId: " . $e->getMessage() . " (Attempt " . ($attempts + 1) . ")");
                $attempts++;

                if ($attempts < $this->maxRetryAttempts) {
                    sleep($this->retryDelay);
                }
            }
        }

        return false;
    }

    /**
     * Get all tickets
     * @return array The tickets
     */
    public function getAllTickets(): array
    {
        // Make sure we have a working connection to Redmine first
        $this->initializeConnection();

        // Verify connection before proceeding
        $connectionTest = $this->testRedmineConnection();
        if (!$connectionTest['success']) {
            wfDebugLog('SupportSystem', "Cannot get tickets: Redmine connection test failed");
            return [];
        }

        $url = rtrim($this->apiUrl, '/') . "/issues.json";
        $options = [
            'method' => 'GET',
            'timeout' => 30,
            'headers' => [
                'X-Redmine-API-Key' => $this->apiKey
            ],
            'followRedirects' => true,
            'sslVerifyCert' => false,
            'proxy' => false,
            'curlOptions' => [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]
        ];

        wfDebugLog('SupportSystem', "Getting all tickets from Redmine");

        // Add retry logic for better reliability
        $attempts = 0;
        while ($attempts < $this->maxRetryAttempts) {
            try {
                $response = Http::request($url, $options);

                if ($response === false) {
                    wfDebugLog('SupportSystem', "Error retrieving all tickets: No response (Attempt " . ($attempts + 1) . ")");
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    return [];
                }

                $statusCode = Http::getLastStatusCode();
                if ($statusCode >= 400) {
                    wfDebugLog('SupportSystem', "Error retrieving all tickets: Status $statusCode (Attempt " . ($attempts + 1) . ")");
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    return [];
                }

                $data = json_decode($response, true);
                if (!isset($data['issues'])) {
                    wfDebugLog('SupportSystem', "Error retrieving all tickets: " . substr($response, 0, 200));
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    return [];
                }

                return $data['issues'];
            } catch (\Exception $e) {
                wfDebugLog('SupportSystem', "Exception retrieving all tickets: " . $e->getMessage() . " (Attempt " . ($attempts + 1) . ")");
                $attempts++;

                if ($attempts < $this->maxRetryAttempts) {
                    sleep($this->retryDelay);
                }
            }
        }

        return [];
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