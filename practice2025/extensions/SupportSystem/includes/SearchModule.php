<?php
namespace MediaWiki\Extension\SupportSystem;

use MediaWiki\MediaWikiServices;
use MWException;
use FormatJson;

/**
 * Класс для поиска решений
 */
class SearchModule
{
	/** @var string Хост OpenSearch */
	private $host;

	/** @var int Порт OpenSearch */
	private $port;

	/** @var string Имя индекса OpenSearch */
	private $indexName;

	/** @var int Кэш (время жизни в секундах - 12 часов) */
	private $cacheLifetime = 43200;

	/** @var string Каталог кэша для результатов поиска */
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
	 * Комплексный поиск
	 * @param string $query Поисковый запрос
	 * @param array $searchMethods Методы поиска ('opensearch', 'cirrus', 'ai')
	 * @param array $context Предыдущий контекст (опционально)
	 * @param string|null $userId ID пользователя для отслеживания контекста
	 * @return array Результаты поиска с метаданными
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
				$aiResult = $this->searchAI($query, $context, $userId);
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
		usort($results, function ($a, $b) { $b['score'] <=> $a['score']; });
		$debugInfo['totalCount'] = count($results);
		$debugInfo['totalTime'] = microtime(true) - $debugInfo['startTime'];
		$returnData = [
			'results' => $results,
			'debug' => $debugInfo
		];
		if (count($results) > 0) { $this->saveToCache($cacheKey, $returnData); }
		return $returnData;
	}

	/**
	 * Поиск в OpenSearch с использованием curl
	 * @param string $query Поисковый запрос
	 * @param int $size Количество результатов
	 * @return array
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
			throw new MWException("Ошибка подключения к OpenSearch");
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
			if (isset($hit['highlight']['content'])) { $result['highlight'] = $hit['highlight']['content'][0]; }
			if (isset($source['tags'])) { $result['tags'] = $source['tags']; }
			$results[] = $result;
		}
		return $results;
	}

	/**
	 * Поиск
	 * @param string $query Поисковый запрос
	 * @param array $context Контекст диалога
	 * @param string|null $userId ID пользователя
	 * @return array Результат AI-поиска
	 * @throws MWException
	 */
	private function searchAI(string $query, array $context = [], string $userId = null): array
	{
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$aiServiceUrl = $config->get('SupportSystemAIServiceURL');
		$requestData = [
			'query' => $query,
			'context' => $context
		];
		if ($userId) { $requestData['user_id'] = $userId; }
		$jsonData = json_encode($requestData);
		$url = rtrim($aiServiceUrl, '/') . "/api/search_ai";
		$command = "curl -s -X POST -H \"Content-Type: application/json\" " .
			"-d " . escapeshellarg($jsonData) . " \"$url\"";
		$response = shell_exec($command);
		if ($response === false || empty($response)) { throw new MWException("Ошибка подключения к AI сервису"); }
		$data = json_decode($response, true);
		return [
			'answer' => $data['answer'] ?? 'Ответ не получен',
			'sources' => $data['sources'] ?? [],
			'success' => $data['success'] ?? false
		];
	}

	/**
	 * Сохранение результатов поиска в кэш
	 * @param string $key Ключ кэша
	 * @param array $data Данные для кэширования
	 * @return bool Успех операции
	 */
	private function saveToCache($key, $data)
	{
		$cacheFile = $this->cacheDir . DIRECTORY_SEPARATOR . $key . '.cache';
		$cacheData = [
			'timestamp' => time(),
			'data' => $data
		];
		try { return file_put_contents($cacheFile, FormatJson::encode($cacheData), LOCK_EX) !== false; }
		catch (\Exception $e) { return false; }
	}

	/**
	 * Получение результатов поиска из кэша
	 * @param string $key Ключ кэша
	 * @return array|false Закэшированные данные или false если не найдено/истекло
	 */
	private function getFromCache($key)
	{
		$cacheFile = $this->cacheDir . DIRECTORY_SEPARATOR . $key . '.cache';
		if (!file_exists($cacheFile)) { return false; }
		try {
			$cacheData = FormatJson::decode(file_get_contents($cacheFile), true);
			if (!isset($cacheData['timestamp']) || !isset($cacheData['data'])) { return false; }
			if (time() - $cacheData['timestamp'] > $this->cacheLifetime) { return false; }
			return $cacheData['data'];
		} catch (\Exception $e) { return false; }
	}
}