<?php

namespace MediaWiki\Extension\SupportSystem;

use MediaWiki\MediaWikiServices;
use Http;
use FormatJson;
use MWException;

/**
 * Bridge class for Redmine integration
 */
class RedmineBridge
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
    }

    /**
     * Create a ticket in Redmine with retry logic
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
            'proxy' => false
        ];

        // Multi-attempt ticket creation with detailed error reporting
        $attempts = 0;
        $lastError = null;

        while ($attempts < $this->maxRetryAttempts) {
            try {
                $response = Http::request($url, $options);

                if ($response === false) {
                    $error = error_get_last();
                    $errorMessage = $error ? json_encode($error) : 'No response received';
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    throw new MWException('Error connecting to Redmine: ' . $errorMessage);
                }

                $statusCode = Http::getLastStatusCode();
                if ($statusCode >= 400) {
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    throw new MWException("Redmine API returned error status: $statusCode - $response");
                }

                $data = json_decode($response, true);

                if (!isset($data['issue'])) {
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    throw new MWException('Invalid response format from Redmine: ' . substr($response, 0, 100));
                }

                return $data['issue'];
            } catch (\Exception $e) {
                $lastError = $e;
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
     * Get a ticket by ID
     * 
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

        // Add retry logic for better reliability
        $attempts = 0;
        while ($attempts < $this->maxRetryAttempts) {
            try {
                $response = Http::request($url, $options);

                if ($response === false) {
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    return null;
                }

                $statusCode = Http::getLastStatusCode();
                if ($statusCode >= 400) {
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    return null;
                }

                $data = json_decode($response, true);
                if (!isset($data['issue'])) {
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    return null;
                }

                return $data['issue'];
            } catch (\Exception $e) {
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

        // Add retry logic for better reliability
        $attempts = 0;
        while ($attempts < $this->maxRetryAttempts) {
            try {
                $response = Http::request($url, $options);

                if ($response === false) {
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }

                    return false;
                }

                $statusCode = Http::getLastStatusCode();
                if ($statusCode >= 400) {
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
     * 
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

        $attempts = 0;
        while ($attempts < $this->maxRetryAttempts) {
            try {
                $response = Http::request($url, $options);
                if ($response === false) {
                    $attempts++;
                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }
                    return [];
                }

                $statusCode = Http::getLastStatusCode();
                if ($statusCode >= 400) {
                    $attempts++;
                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }
                    return [];
                }
                $data = json_decode($response, true);
                if (!isset($data['issues'])) {
                    $attempts++;

                    if ($attempts < $this->maxRetryAttempts) {
                        sleep($this->retryDelay);
                        continue;
                    }
                    return [];
                }
                return $data['issues'];
            } catch (\Exception $e) {
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
     * Get priority ID from name
     * 
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