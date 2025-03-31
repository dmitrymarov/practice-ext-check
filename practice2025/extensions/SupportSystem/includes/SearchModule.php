<?php
namespace MediaWiki\Extension\SupportSystem;

use MediaWiki\MediaWikiServices;
use Http;
use MWException;
use SearchEngine;
use SearchEngineFactory;
use FormatJson;

/**
 * Class for searching solutions
 */
class SearchModule
{
	/** @var string The OpenSearch host */
	private $host;

	/** @var int The OpenSearch port */
	private $port;

	/** @var string The OpenSearch index name */
	private $indexName;

	/** @var string The AI service URL */
	private $aiServiceUrl;

	/** @var array Backup AI service URLs */
	private $backupAiServiceUrls = [];

	/** @var int Max retry attempts */
	private $maxRetries = 3;

	/** @var int Retry delay in seconds */
	private $retryDelay = 1;

	/** @var bool Whether to use mock responses when services are down */
	private $useMock = false;

	/** @var string Cache directory for search results */
	private $cacheDir;

	/** @var int Cache lifetime in seconds (12 hours) */
	private $cacheLifetime = 43200;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$config = MediaWikiServices::getInstance()->getMainConfig();

		$this->host = $config->get('SupportSystemOpenSearchHost');
		$this->port = $config->get('SupportSystemOpenSearchPort');
		$this->indexName = $config->get('SupportSystemOpenSearchIndex');
		$this->useMock = $config->get('SupportSystemUseMock', false);
		$this->aiServiceUrl = $config->get('SupportSystemAIServiceURL');

		// Setup backup service URLs based on different network configurations
		$this->setupBackupServiceUrls();

		// Initialize cache directory
		$this->setupCacheDirectory();

		// Test AI service connectivity
		$this->testAiServiceConnectivity();
	}

	/**
	 * Setup backup service URLs for different network configurations
	 */
	private function setupBackupServiceUrls()
	{
		// Parse primary URL to get components
		$parsedUrl = parse_url($this->aiServiceUrl);
		$host = $parsedUrl['host'] ?? 'ai-service';
		$port = $parsedUrl['port'] ?? 5000;

		// Add Docker service name
		$this->backupAiServiceUrls[] = "http://ai-service:$port";

		// Add common Docker bridge network IP
		$this->backupAiServiceUrls[] = "http://172.17.0.1:$port";

		// Add localhost
		$this->backupAiServiceUrls[] = "http://localhost:$port";

		// Add host machine IP when running inside container
		$this->backupAiServiceUrls[] = "http://172.29.46.60:$port";

		// Add IP discovery from Docker network
		try {
			$dockerHostIp = trim(shell_exec('ip route | grep default | cut -d " " -f 3'));
			if ($dockerHostIp) {
				$this->backupAiServiceUrls[] = "http://$dockerHostIp:$port";
			}
		} catch (\Exception $e) {
			wfDebugLog('SupportSystem', "Failed to get Docker host IP: " . $e->getMessage());
		}

		// Filter out URLs that match primary URL
		$this->backupAiServiceUrls = array_filter($this->backupAiServiceUrls, function ($url) {
			return $url !== $this->aiServiceUrl;
		});

		wfDebugLog('SupportSystem', "Primary AI service URL: " . $this->aiServiceUrl);
		wfDebugLog('SupportSystem', "Backup AI service URLs: " . implode(', ', $this->backupAiServiceUrls));
	}

	/**
	 * Setup cache directory for search results
	 */
	private function setupCacheDirectory()
	{
		$this->cacheDir = wfTempDir() . DIRECTORY_SEPARATOR . 'support_system_cache';

		if (!file_exists($this->cacheDir)) {
			wfMkdirParents($this->cacheDir);
		}

		// Cleanup old cache files
		$this->cleanupCacheDirectory();
	}

	/**
	 * Cleanup old cache files
	 */
	private function cleanupCacheDirectory()
	{
		$now = time();
		$files = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache');

		foreach ($files as $file) {
			$mtime = filemtime($file);
			if ($now - $mtime > $this->cacheLifetime) {
				unlink($file);
			}
		}
	}

	/**
	 * Test AI service connectivity and try backup URLs if needed
	 * 
	 * @return bool True if a working connection was found
	 */
	private function testAiServiceConnectivity()
	{
		// Try primary URL first
		if ($this->isAIServiceAvailable()) {
			wfDebugLog('SupportSystem', "AI service is available at primary URL: " . $this->aiServiceUrl);
			return true;
		}

		wfDebugLog('SupportSystem', "AI service not available at primary URL, trying backups");

		// Try backup URLs
		foreach ($this->backupAiServiceUrls as $backupUrl) {
			$originalUrl = $this->aiServiceUrl;
			$this->aiServiceUrl = $backupUrl;

			if ($this->isAIServiceAvailable()) {
				wfDebugLog('SupportSystem', "AI service is available at backup URL: " . $backupUrl);
				return true;
			}

			// Restore original URL if this backup failed
			$this->aiServiceUrl = $originalUrl;
		}

		wfDebugLog('SupportSystem', "No working AI service URL found. Will try all URLs for each request.");
		return false;
	}

	/**
	 * Perform comprehensive search using all available methods
	 * 
	 * @param string $query The search query
	 * @param array $searchMethods Array of search methods to use ('graph', 'opensearch', 'cirrus', 'ai')
	 * @param array $context Previous context (optional)
	 * @param string|null $userId User ID for tracking context (optional)
	 * @return array Search results with metadata
	 */
	public function comprehensiveSearch(string $query, array $searchMethods = ['opensearch', 'cirrus', 'ai'], array $context = [], string $userId = null): array
	{
		$results = [];
		$debugInfo = [
			'query' => $query,
			'methods' => $searchMethods,
			'startTime' => microtime(true)
		];

		// Check cache first
		$cacheKey = md5($query . json_encode($searchMethods) . json_encode($context));
		$cachedResults = $this->getFromCache($cacheKey);

		if ($cachedResults !== false) {
			wfDebugLog('SupportSystem', "Using cached search results for query: $query");
			return $cachedResults;
		}

		// Поиск в OpenSearch, если включен
		if (in_array('opensearch', $searchMethods)) {
			try {
				$opensearchResults = $this->search($query);
				$results = array_merge($results, $opensearchResults);
				$debugInfo['opensearch'] = [
					'count' => count($opensearchResults),
					'status' => 'success'
				];
			} catch (\Exception $e) {
				wfDebugLog('SupportSystem', "OpenSearch error: " . $e->getMessage());
				$debugInfo['opensearch'] = [
					'status' => 'error',
					'message' => $e->getMessage()
				];
			}
		}

		// Поиск через CirrusSearch, если включен
		if (in_array('cirrus', $searchMethods) && class_exists('CirrusSearch\CirrusSearch')) {
			try {
				$cirrusResults = $this->searchCirrus($query);
				$results = array_merge($results, $cirrusResults);
				$debugInfo['cirrus'] = [
					'count' => count($cirrusResults),
					'status' => 'success'
				];
			} catch (\Exception $e) {
				wfDebugLog('SupportSystem', "CirrusSearch error: " . $e->getMessage());
				$debugInfo['cirrus'] = [
					'status' => 'error',
					'message' => $e->getMessage()
				];
			}
		}

		// Если AI поиск включен, используем его независимо от других результатов
		if (in_array('ai', $searchMethods)) {
			try {
				$aiResult = $this->searchAI($query, $context, $userId);
				$debugInfo['ai'] = [
					'status' => $aiResult['success'] ? 'success' : 'failed',
					'sourcesCount' => count($aiResult['sources'] ?? [])
				];

				// Если AI-поиск успешен, добавляем его результат
				if ($aiResult['success']) {
					$aiResults = [
						[
							'id' => 'ai_' . md5($query),
							'title' => 'AI решение для: ' . $query,
							'content' => $aiResult['answer'],
							'score' => 1.0, // Высокий приоритет для AI-ответа
							'source' => 'ai',
							'ai_result' => $aiResult
						]
					];
					$results = array_merge($aiResults, $results); // AI-результат в начале
				}
			} catch (\Exception $e) {
				wfDebugLog('SupportSystem', "AI search error: " . $e->getMessage());
				$debugInfo['ai'] = [
					'status' => 'error',
					'message' => $e->getMessage()
				];
			}
		}

		// Сортируем результаты по релевантности
		usort($results, function ($a, $b) {
			return $b['score'] <=> $a['score'];
		});

		$debugInfo['totalCount'] = count($results);
		$debugInfo['totalTime'] = microtime(true) - $debugInfo['startTime'];

		$returnData = [
			'results' => $results,
			'debug' => $debugInfo
		];

		// Save to cache only if we have results
		if (count($results) > 0) {
			$this->saveToCache($cacheKey, $returnData);
		}

		return $returnData;
	}

	/**
	 * Save search results to cache
	 * 
	 * @param string $key Cache key
	 * @param array $data Data to cache
	 * @return bool Success
	 */
	private function saveToCache($key, $data)
	{
		$cacheFile = $this->cacheDir . DIRECTORY_SEPARATOR . $key . '.cache';
		$cacheData = [
			'timestamp' => time(),
			'data' => $data
		];

		try {
			return file_put_contents($cacheFile, FormatJson::encode($cacheData), LOCK_EX) !== false;
		} catch (\Exception $e) {
			wfDebugLog('SupportSystem', "Error saving to cache: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Get search results from cache
	 * 
	 * @param string $key Cache key
	 * @return array|false Cached data or false if not found/expired
	 */
	private function getFromCache($key)
	{
		$cacheFile = $this->cacheDir . DIRECTORY_SEPARATOR . $key . '.cache';

		if (!file_exists($cacheFile)) {
			return false;
		}

		try {
			$cacheData = FormatJson::decode(file_get_contents($cacheFile), true);

			if (!isset($cacheData['timestamp']) || !isset($cacheData['data'])) {
				return false;
			}

			// Check if cache is still valid
			if (time() - $cacheData['timestamp'] > $this->cacheLifetime) {
				return false;
			}

			return $cacheData['data'];
		} catch (\Exception $e) {
			wfDebugLog('SupportSystem', "Error reading from cache: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Search for solutions in OpenSearch
	 * @param string $query The search query
	 * @param int $size The number of results to return
	 * @return array
	 * @throws MWException
	 */
	public function search(string $query, int $size = 10): array
	{
		return $this->searchOpenSearch($query, $size);
	}

	/**
	 * Search in OpenSearch
	 * @param string $query The search query
	 * @param int $size The number of results to return
	 * @return array
	 * @throws MWException
	 */
	private function searchOpenSearch(string $query, int $size = 10): array
	{
		$attempts = 0;
		$lastException = null;

		while ($attempts < $this->maxRetries) {
			try {
				$url = "http://{$this->host}:{$this->port}/{$this->indexName}/_search";

				$requestData = [
					'query' => [
						'multi_match' => [
							'query' => $query,
							'fields' => ['title^2', 'content', 'tags^1.5'],
							'type' => 'best_fields'
						]
					],
					'highlight' => [
						'fields' => [
							'content' => new \stdClass()
						]
					],
					'size' => $size
				];

				$options = [
					'method' => 'POST',
					'timeout' => 10,
					'postData' => json_encode($requestData),
					'headers' => [
						'Content-Type' => 'application/json'
					]
				];

				wfDebugLog('SupportSystem', "Searching OpenSearch for query: $query (attempt " . ($attempts + 1) . ")");

				$response = Http::request($url, $options);

				if ($response === false) {
					wfDebugLog('SupportSystem', "Error connecting to OpenSearch (attempt " . ($attempts + 1) . ")");
					$attempts++;

					if ($attempts < $this->maxRetries) {
						sleep($this->retryDelay);
						continue;
					}

					throw new MWException('Error connecting to OpenSearch after ' . $this->maxRetries . ' attempts');
				}

				$data = json_decode($response, true);

				if (!isset($data['hits']['hits'])) {
					$attempts++;

					if ($attempts < $this->maxRetries) {
						sleep($this->retryDelay);
						continue;
					}

					return [];
				}

				$results = [];

				foreach ($data['hits']['hits'] as $hit) {
					$source = $hit['_source'];
					$result = [
						'id' => $source['id'] ?? $hit['_id'],
						'title' => $source['title'] ?? 'Untitled',
						'content' => $source['content'] ?? '',
						'score' => $hit['_score'],
						'source' => 'opensearch',
						'url' => $source['url'] ?? ''
					];

					if (isset($hit['highlight']['content'])) {
						$result['highlight'] = $hit['highlight']['content'][0];
					}

					if (isset($source['tags'])) {
						$result['tags'] = $source['tags'];
					}

					$results[] = $result;
				}

				return $results;
			} catch (\Exception $e) {
				$lastException = $e;
				wfDebugLog('SupportSystem', "Error searching OpenSearch (attempt " . ($attempts + 1) . "): " . $e->getMessage());
				$attempts++;

				if ($attempts < $this->maxRetries) {
					sleep($this->retryDelay);
				}
			}
		}

		if ($lastException) {
			throw $lastException;
		}

		return [];
	}

	/**
	 * Search in CirrusSearch (MediaWiki's search engine)
	 * @param string $query The search query
	 * @param int $limit The number of results to return
	 * @return array
	 */
	public function searchCirrus(string $query, int $limit = 10): array
	{
		$results = [];

		$attempts = 0;
		while ($attempts < $this->maxRetries) {
			try {
				$services = MediaWikiServices::getInstance();
				$searchEngineFactory = $services->getSearchEngineFactory();
				$searchEngine = $searchEngineFactory->create();

				// Проверяем, используется ли CirrusSearch
				$isCirrus = (get_class($searchEngine) === 'CirrusSearch\CirrusSearch' || is_subclass_of($searchEngine, 'CirrusSearch\CirrusSearch'));
				wfDebugLog('SupportSystem', "Using search engine: " . get_class($searchEngine) . ", is CirrusSearch: " . ($isCirrus ? "yes" : "no"));

				$searchEngine->setLimitOffset($limit);
				$searchEngine->setNamespaces([NS_MAIN]); // Только основное пространство имен

				$term = $searchEngine->transformSearchTerm($query);
				$matches = $searchEngine->searchText($term);

				if ($matches) {
					foreach ($matches as $match) {
						$title = $match->getTitle();

						// Получение фрагмента текста
						$snippet = $match->getTextSnippet();
						if (empty($snippet)) {
							// Если сниппет недоступен, пытаемся получить короткое описание
							$content = $this->getPageContent($title);
							$snippet = $this->createSnippet($content, 200);
						}

						$result = [
							'id' => 'cirrus_' . $title->getArticleID(),
							'title' => $title->getText(),
							'content' => $snippet,
							'score' => $match->getScore() ?: 0.8, // Немного ниже чем OpenSearch
							'source' => 'cirrus',
							'url' => $title->getFullURL()
						];

						// Если доступны хайлайты, добавляем их
						if (method_exists($match, 'getHighlightText')) {
							$highlight = $match->getHighlightText();
							if ($highlight) {
								$result['highlight'] = $highlight;
							}
						}

						$results[] = $result;
					}
				}

				// Если успешно получили результаты - выходим из цикла
				break;
			} catch (\Exception $e) {
				wfDebugLog('SupportSystem', "CirrusSearch error (attempt " . ($attempts + 1) . "): " . $e->getMessage());
				$attempts++;

				if ($attempts < $this->maxRetries) {
					sleep($this->retryDelay);
				}
			}
		}

		return $results;
	}

	/**
	 * Get content of a page
	 * @param Title $title The title object
	 * @return string Page content
	 */
	private function getPageContent($title)
	{
		$page = \WikiPage::factory($title);
		$content = $page->getContent();

		if ($content) {
			return $content->getTextForSummary(1000);
		}

		return '';
	}

	/**
	 * Create a snippet from content
	 * @param string $content The content
	 * @param int $length Maximum length
	 * @return string Snippet
	 */
	private function createSnippet($content, $length = 200)
	{
		$content = preg_replace('/\s+/', ' ', strip_tags($content));

		if (strlen($content) <= $length) {
			return $content;
		}

		return mb_substr($content, 0, $length - 3) . '...';
	}

	/**
	 * Search via AI service for more complex queries with robust error handling and retry
	 * 
	 * @param string $query The search query
	 * @param array $context Previous context (optional)
	 * @param string|null $userId User ID for tracking context (optional)
	 * @return array
	 */
	public function searchAI(string $query, array $context = [], string $userId = null): array
	{
		$attempts = 0;
		$lastError = null;
		$urlsToTry = array_merge([$this->aiServiceUrl], $this->backupAiServiceUrls);

		// Check cache first for this specific query and context
		$cacheKey = 'ai_' . md5($query . json_encode($context) . ($userId ?? ''));
		$cachedResult = $this->getFromCache($cacheKey);

		if ($cachedResult !== false) {
			wfDebugLog('SupportSystem', "Using cached AI search result for query: $query");
			return $cachedResult;
		}

		// Try all URLs with retry for each
		foreach ($urlsToTry as $currentUrl) {
			$attempts = 0;

			while ($attempts < $this->maxRetries) {
				try {
					$url = "{$currentUrl}/api/search_ai";

					$requestData = [
						'query' => $query,
						'context' => $context
					];

					// Add user ID if available for context tracking
					if ($userId !== null) {
						$requestData['user_id'] = $userId;
					}

					$options = [
						'method' => 'POST',
						'timeout' => 30, // Longer timeout for AI processing
						'postData' => json_encode($requestData),
						'headers' => [
							'Content-Type' => 'application/json'
						],
						'followRedirects' => true,
						'sslVerifyCert' => false,
						'proxy' => false
					];

					// Детальное логирование запроса
					wfDebugLog('SupportSystem', "Making AI search request to URL: {$url} (attempt " . ($attempts + 1) . ")");

					// Перед основным запросом проверяем доступность AI-сервиса
					$healthUrl = rtrim($currentUrl, '/') . '/';
					$healthResponse = Http::request($healthUrl, ['method' => 'GET', 'timeout' => 5]);

					if ($healthResponse === false) {
						wfDebugLog('SupportSystem', "AI service health check failed for URL: {$currentUrl}");
						$attempts++;

						if ($attempts < $this->maxRetries) {
							sleep($this->retryDelay);
							continue;
						} else {
							// Try next URL
							break;
						}
					}

					wfDebugLog('SupportSystem', "AI service health check passed for URL: {$currentUrl}");

					// Выполняем основной запрос
					$response = Http::request($url, $options);

					if ($response === false) {
						wfDebugLog('SupportSystem', "AI service search request failed for URL: {$currentUrl} (attempt " . ($attempts + 1) . ")");
						$attempts++;

						if ($attempts < $this->maxRetries) {
							sleep($this->retryDelay);
							continue;
						} else {
							// Try next URL
							break;
						}
					}

					// Check for HTTP errors
					$statusCode = Http::getLastStatusCode();
					if ($statusCode >= 400) {
						wfDebugLog('SupportSystem', "AI service returned error status {$statusCode} for URL: {$currentUrl} (attempt " . ($attempts + 1) . ")");
						$attempts++;

						if ($attempts < $this->maxRetries) {
							sleep($this->retryDelay);
							continue;
						} else {
							// Try next URL
							break;
						}
					}

					// Логируем ответ
					wfDebugLog('SupportSystem', "AI service response from {$currentUrl}: " . substr($response, 0, 500) . '...');

					$data = json_decode($response, true);

					// Проверка корректности формата данных
					if (!$data || !is_array($data)) {
						wfDebugLog('SupportSystem', "Invalid AI service response format from {$currentUrl}: " . substr($response, 0, 200));
						$attempts++;

						if ($attempts < $this->maxRetries) {
							sleep($this->retryDelay);
							continue;
						} else {
							// Try next URL
							break;
						}
					}

					// Обеспечиваем стандартный формат ответа, даже если что-то отсутствует
					if (!isset($data['answer'])) {
						$data['answer'] = 'Ответ не предоставлен сервисом ИИ.';
						$data['success'] = false;
					}

					if (!isset($data['sources'])) {
						$data['sources'] = [];
					}

					if (!isset($data['success'])) {
						$data['success'] = false;
					}

					// Store successful result in cache
					if ($data['success']) {
						$this->saveToCache($cacheKey, $data);

						// Remember this working URL as the primary one for future requests
						if ($currentUrl !== $this->aiServiceUrl) {
							wfDebugLog('SupportSystem', "Updating primary AI service URL from {$this->aiServiceUrl} to {$currentUrl}");
							$this->aiServiceUrl = $currentUrl;
						}
					}

					return $data;
				} catch (\Exception $e) {
					$lastError = $e;
					wfDebugLog('SupportSystem', "Error in searchAI for URL {$currentUrl} (attempt " . ($attempts + 1) . "): " . $e->getMessage());
					$attempts++;

					if ($attempts < $this->maxRetries) {
						sleep($this->retryDelay);
					}
				}
			}
		}

		// All URLs and attempts failed, return error response
		wfDebugLog('SupportSystem', 'All AI service URLs failed after multiple attempts. Last error: ' . ($lastError ? $lastError->getMessage() : 'Unknown'));

		return [
			'answer' => 'Произошла ошибка при выполнении интеллектуального поиска. Пожалуйста, попробуйте снова позже.',
			'sources' => [],
			'success' => false
		];
	}

	/**
	 * Check AI service availability with improved error handling
	 * @return bool
	 */
	public function isAIServiceAvailable(): bool
	{
		try {
			$url = "{$this->aiServiceUrl}/";

			$options = [
				'method' => 'GET',
				'timeout' => 5, // Short timeout for health check
				'followRedirects' => true,
				'sslVerifyCert' => false,
				'proxy' => false
			];

			wfDebugLog('SupportSystem', "Checking AI service availability at: {$url}");
			$response = Http::request($url, $options);

			if ($response === false) {
				wfDebugLog('SupportSystem', "AI service availability check failed: No response");
				return false;
			}

			// Check status code
			$statusCode = Http::getLastStatusCode();
			if ($statusCode >= 400) {
				wfDebugLog('SupportSystem', "AI service availability check failed: Status code {$statusCode}");
				return false;
			}

			// Try to parse response as JSON
			$data = json_decode($response, true);

			// Check for expected health check response format
			if (isset($data['status']) && $data['status'] === 'ok') {
				wfDebugLog('SupportSystem', "AI service availability check passed with status 'ok'");
				return true;
			}

			// If we got any response at all, consider it a success
			wfDebugLog('SupportSystem', "AI service returned a response but without expected format. Considering it available.");
			return true;
		} catch (\Exception $e) {
			wfDebugLog('SupportSystem', 'Error checking AI service availability: ' . $e->getMessage());
			return false;
		}
	}
}