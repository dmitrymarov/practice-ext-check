<?php
namespace MediaWiki\Extension\SupportSystem;

use MediaWiki\MediaWikiServices;
use Http;
use MWException;
use SearchEngine;
use SearchEngineFactory;

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

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$config = MediaWikiServices::getInstance()->getMainConfig();

		$this->host = $config->get('SupportSystemOpenSearchHost');
		$this->port = $config->get('SupportSystemOpenSearchPort');
		$this->indexName = $config->get('SupportSystemOpenSearchIndex');
		$this->useMock = false;
		$this->aiServiceUrl = $config->get('SupportSystemAIServiceURL');
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

		// Если обычный поиск не дал достаточно результатов, используем AI
		if (in_array('ai', $searchMethods) && count($results) < 3) {
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

		return [
			'results' => $results,
			'debug' => $debugInfo
		];
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

		wfDebugLog('SupportSystem', "Searching OpenSearch for query: $query");

		$response = Http::request($url, $options);

		if ($response === false) {
			wfDebugLog('SupportSystem', "Error connecting to OpenSearch");
			throw new MWException('Error connecting to OpenSearch');
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
	 * Search in CirrusSearch (MediaWiki's search engine)
	 * @param string $query The search query
	 * @param int $limit The number of results to return
	 * @return array
	 */
	public function searchCirrus(string $query, int $limit = 10): array
	{
		$results = [];

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
	 * Search in MediaWiki pages
	 * @param string $query The search query
	 * @param int $limit The number of results to return
	 * @return array
	 */
	public function searchMediaWiki(string $query, int $limit = 5): array
	{
		$searchEngine = MediaWikiServices::getInstance()->newSearchEngine();
		$searchEngine->setLimitOffset($limit);

		$results = [];
		$matches = $searchEngine->searchText($query);

		if ($matches) {
			foreach ($matches as $match) {
				$title = $match->getTitle();

				$results[] = [
					'id' => 'mediawiki_' . $title->getArticleID(),
					'title' => $title->getText(),
					'content' => $match->getTextSnippet(),  // Get a snippet of the page content
					'score' => 1.0,                         // MediaWiki doesn't provide relevance scores
					'source' => 'mediawiki',
					'url' => $title->getLocalURL()
				];
			}
		}

		return $results;
	}

	/**
	 * Search via AI service for more complex queries
	 * 
	 * @param string $query The search query
	 * @param array $context Previous context (optional)
	 * @param string|null $userId User ID for tracking context (optional)
	 * @return array
	 */
	public function searchAI(string $query, array $context = [], string $userId = null): array
	{
		try {
			$url = "{$this->aiServiceUrl}/api/search_ai";

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
			wfDebugLog('SupportSystem', 'Making AI search request to URL: ' . $url . ', data: ' . json_encode($requestData));

			// Перед основным запросом проверяем доступность AI-сервиса
			$healthUrl = rtrim($this->aiServiceUrl, '/') . '/';
			$healthResponse = Http::request($healthUrl, ['method' => 'GET', 'timeout' => 5]);

			if ($healthResponse === false) {
				wfDebugLog('SupportSystem', 'AI service health check failed. Service unavailable.');
				return [
					'answer' => 'К сожалению, интеллектуальный поиск временно недоступен. Пожалуйста, попробуйте снова позже или воспользуйтесь стандартным поиском.',
					'sources' => [],
					'success' => false
				];
			}

			wfDebugLog('SupportSystem', 'AI service health check passed. Service available.');

			// Выполняем основной запрос
			$response = Http::request($url, $options);

			if ($response === false) {
				wfDebugLog('SupportSystem', 'AI service search request failed. No response.');
				return [
					'answer' => 'К сожалению, интеллектуальный поиск временно недоступен. Пожалуйста, попробуйте снова позже или воспользуйтесь стандартным поиском.',
					'sources' => [],
					'success' => false
				];
			}

			// Логируем ответ
			wfDebugLog('SupportSystem', 'AI service response: ' . substr($response, 0, 500) . '...');

			$data = json_decode($response, true);

			// Проверка корректности формата данных
			if (!$data || !is_array($data)) {
				wfDebugLog('SupportSystem', 'Invalid AI service response format: ' . substr($response, 0, 200));
				return [
					'answer' => 'Не удалось обработать ответ от сервиса ИИ. Пожалуйста, попробуйте снова позже.',
					'sources' => [],
					'success' => false
				];
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

			return $data;
		} catch (\Exception $e) {
			wfDebugLog('SupportSystem', 'Error in searchAI: ' . $e->getMessage() . "\n" . $e->getTraceAsString());

			return [
				'answer' => 'Произошла ошибка при выполнении интеллектуального поиска. Пожалуйста, попробуйте снова позже.',
				'sources' => [],
				'success' => false
			];
		}
	}

	/**
	 * Check AI service availability
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

			$response = Http::request($url, $options);

			if ($response === false) {
				return false;
			}

			$data = json_decode($response, true);

			return isset($data['status']) && $data['status'] === 'ok';
		} catch (\Exception $e) {
			wfDebugLog('SupportSystem', 'Error checking AI service availability: ' . $e->getMessage());
			return false;
		}
	}
}