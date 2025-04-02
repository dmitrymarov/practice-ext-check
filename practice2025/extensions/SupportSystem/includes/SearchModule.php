<?php
namespace MediaWiki\Extension\SupportSystem;

use MediaWiki\MediaWikiServices;
use Http;
use MWException;
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

	/** @var int Max retry attempts */
	private $maxRetries = 3;

	/** @var int Retry delay in seconds */
	private $retryDelay = 1;

	/** @var string Cache directory for search results */
	private $cacheDir;

	/** @var int Cache lifetime in seconds (12 hours) */
	private $cacheLifetime = 43200;

	/** @var AISearchBridge AI search bridge instance */
	private $aiBridge;
	public function __construct()
	{
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$this->host = $config->get('SupportSystemOpenSearchHost');
		$this->port = $config->get('SupportSystemOpenSearchPort');
		$this->indexName = $config->get('SupportSystemOpenSearchIndex');
		$this->setupCacheDirectory();
		$this->aiBridge = new AISearchBridge();
	}
	private function setupCacheDirectory()
	{
		$this->cacheDir = wfTempDir() . DIRECTORY_SEPARATOR . 'support_system_cache';
		if (!file_exists($this->cacheDir)) {
			wfMkdirParents($this->cacheDir);
		}
		$this->cleanupCacheDirectory();
	}

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
	 * Perform search using all available methods
	 * 
	 * @param string $query The search query
	 * @param array $searchMethods Array of search methods to use ('opensearch', 'cirrus', 'ai')
	 * @param array $context Previous context (optional)
	 * @param string|null $userId User ID for tracking context (optional)
	 * @return array Search results with metadata
	 */
	public function comprehensiveSearch(string $query, array $searchMethods = ['opensearch', 'cirrus'], array $context = [], string $userId = null): array
	{
		$results = [];
		$debugInfo = [
			'query' => $query,
			'methods' => $searchMethods,
			'startTime' => microtime(true)
		];
		$cacheKey = md5($query . json_encode($searchMethods) . json_encode($context));
		$cachedResults = $this->getFromCache($cacheKey);

		if ($cachedResults !== false) {
			return $cachedResults;
		}
		if (in_array('opensearch', $searchMethods)) {
			try {
				$opensearchResults = $this->searchOpenSearch($query);
				$results = array_merge($results, $opensearchResults);
				$debugInfo['opensearch'] = [
					'count' => count($opensearchResults),
					'status' => 'success'
				];
			} catch (\Exception $e) {
				$debugInfo['opensearch'] = [
					'status' => 'error',
					'message' => $e->getMessage()
				];
			}
		}
		if (in_array('cirrus', $searchMethods) && class_exists('CirrusSearch\CirrusSearch')) {
			try {
				$cirrusResults = $this->searchCirrus($query);
				$results = array_merge($results, $cirrusResults);
				$debugInfo['cirrus'] = [
					'count' => count($cirrusResults),
					'status' => 'success'
				];
			} catch (\Exception $e) {
				$debugInfo['cirrus'] = [
					'status' => 'error',
					'message' => $e->getMessage()
				];
			}
		}
		if (in_array('ai', $searchMethods)) {
			try {
				$aiResult = $this->aiBridge->search($query, $context, $userId);
				$debugInfo['ai'] = [
					'status' => $aiResult['success'] ? 'success' : 'failed',
					'sourcesCount' => count($aiResult['sources'] ?? [])
				];
				if ($aiResult['success']) {
					$aiResults = [
						[
							'id' => 'ai_' . md5($query),
							'title' => 'AI решение для: ' . $query,
							'content' => $aiResult['answer'],
							'score' => 1.0,
							'source' => 'ai',
							'ai_result' => $aiResult
						]
					];
					$results = array_merge($aiResults, $results);
				}
			} catch (\Exception $e) {
				$debugInfo['ai'] = [
					'status' => 'error',
					'message' => $e->getMessage()
				];
			}
		}
		usort($results, function ($a, $b) {
			return $b['score'] <=> $a['score'];
		});
		$debugInfo['totalCount'] = count($results);
		$debugInfo['totalTime'] = microtime(true) - $debugInfo['startTime'];
		$returnData = [
			'results' => $results,
			'debug' => $debugInfo
		];
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
			if (time() - $cacheData['timestamp'] > $this->cacheLifetime) {
				return false;
			}
			return $cacheData['data'];
		} catch (\Exception $e) {
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
	{ return $this->searchOpenSearch($query, $size); }

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
				$response = Http::request($url, $options);
				if ($response === false) {
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
				$isCirrus = (get_class($searchEngine) === 'CirrusSearch\CirrusSearch' || is_subclass_of($searchEngine, 'CirrusSearch\CirrusSearch'));
				$searchEngine->setLimitOffset($limit);
				$searchEngine->setNamespaces([NS_MAIN]);
				$term = $searchEngine->transformSearchTerm($query);
				$matches = $searchEngine->searchText($term);
				if ($matches) {
					foreach ($matches as $match) {
						$title = $match->getTitle();
						$snippet = $match->getTextSnippet();
						if (empty($snippet)) {
							$content = $this->getPageContent($title);
							$snippet = $this->createSnippet($content, 200);
						}
						$result = [
							'id' => 'cirrus_' . $title->getArticleID(),
							'title' => $title->getText(),
							'content' => $snippet,
							'score' => $match->getScore() ?: 0.8,
							'source' => 'cirrus',
							'url' => $title->getFullURL()
						];
						if (method_exists($match, 'getHighlightText')) {
							$highlight = $match->getHighlightText();
							if ($highlight) {
								$result['highlight'] = $highlight;
							}
						}
						$results[] = $result;
					}
				}
				break;
			} catch (\Exception $e) {
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
}