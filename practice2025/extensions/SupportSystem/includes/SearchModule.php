<?php
namespace MediaWiki\Extension\SupportSystem;

use MediaWiki\MediaWikiServices;
use MWException;
use FormatJson;

/**
 * Class for search functionality
 */
class SearchModule
{
	/** @var string OpenSearch host */
	private $host;

	/** @var int OpenSearch port */
	private $port;

	/** @var string OpenSearch index name */
	private $indexName;

	/** @var int Cache lifetime in seconds (12 hours) */
	private $cacheLifetime = 43200;

	/** @var string Cache directory for search results */
	private $cacheDir;

	public function __construct()
	{
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$this->host = $config->get('SupportSystemOpenSearchHost');
		$this->port = $config->get('SupportSystemOpenSearchPort');
		$this->indexName = $config->get('SupportSystemOpenSearchIndex');
		$this->setupCacheDirectory();
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
	 * Comprehensive search across multiple sources
	 * 
	 * @param string $query Search query
	 * @param array $searchMethods Search methods to use ('opensearch', 'mediawiki')
	 * @param array $context Previous context (optional)
	 * @param string|null $userId User ID for context tracking
	 * @return array Search results with metadata
	 */
	public function comprehensiveSearch(string $query, array $searchMethods = ['opensearch', 'mediawiki'], array $context = [], string $userId = null): array
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
		if (in_array('mediawiki', $searchMethods)) {
			try {
				$mediawikiResults = $this->searchMediaWiki($query);
				$results = array_merge($results, $mediawikiResults);
				$debugInfo['mediawiki'] = [
					'count' => count($mediawikiResults),
					'status' => 'success'
				];
			} catch (\Exception $e) {
				$debugInfo['mediawiki'] = [
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
	 * Search in OpenSearch using curl
	 * 
	 * @param string $query Search query
	 * @param int $size Number of results
	 * @return array Search results
	 * @throws MWException
	 */
	private function searchOpenSearch(string $query, int $size = 10): array
	{
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
		$jsonData = json_encode($requestData);
		$command = "curl -s -X POST -H \"Content-Type: application/json\" " .
			"-d " . escapeshellarg($jsonData) . " \"$url\"";
		$response = shell_exec($command);
		if ($response === false || empty($response)) {
			throw new MWException("Error connecting to OpenSearch");
		}
		$data = json_decode($response, true);
		if (!isset($data['hits']['hits'])) {
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
	}

	/**
	 * Search MediaWiki using curl to the API
	 * 
	 * @param string $query Search query
	 * @param int $limit Maximum number of results
	 * @return array Search results
	 * @throws MWException
	 */
	private function searchMediaWiki(string $query, int $limit = 10): array
	{
		$apiUrl = wfScript('api');
		$params = [
			'action' => 'query',
			'list' => 'search',
			'srsearch' => $query,
			'srnamespace' => 0,
			'srlimit' => $limit,
			'srinfo' => 'totalhits|suggestion',
			'srprop' => 'size|wordcount|timestamp|snippet|titlesnippet|redirecttitle|redirectsnippet|sectiontitle|sectionsnippet|categorysnippet|score|hasrelated|extensiondata',
			'format' => 'json'
		];
		$queryString = http_build_query($params);
		$command = "curl -s \"$apiUrl?$queryString\"";
		$response = shell_exec($command);

		if ($response === false || empty($response)) {
			throw new MWException("Error connecting to MediaWiki API");
		}
		$data = json_decode($response, true);
		if (!isset($data['query']['search'])) {
			return [];
		}
		$results = [];
		foreach ($data['query']['search'] as $item) {
			$title = \Title::newFromText($item['title']);
			if (!$title) {
				continue;
			}
			$results[] = [
				'id' => $item['pageid'],
				'title' => $item['title'],
				'content' => $item['snippet'] ?? '',
				'score' => isset($item['score']) ? $item['score'] / 100 : 0.5, // Normalize score
				'source' => 'mediawiki',
				'url' => $title->getFullURL(),
				'highlight' => $item['snippet'] ?? '',
				'timestamp' => $item['timestamp'] ?? ''
			];
		}
		return $results;
	}

	/**
	 * Save search results to cache
	 * 
	 * @param string $key Cache key
	 * @param array $data Data to cache
	 * @return bool Success status
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
		if (!file_exists($cacheFile)) { return false; }
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
}