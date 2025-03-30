<?php

namespace MediaWiki\Extension\SupportSystem;

use MediaWiki\MediaWikiServices;
use Http;
use MWException;

/**
 * Class for searching solutions in OpenSearch
 */
class SearchModule {
	/** @var string The OpenSearch host */
	private $host;
	
	/** @var int The OpenSearch port */
	private $port;
	
	/** @var string The OpenSearch index name */
	private $indexName;
	
	/** @var bool Whether to use mock data */
	private $useMock;
	
	/** @var array Mock data for testing */
	private $mockData;
	
	/**
	 * Constructor
	 */
	public function __construct() {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		
		$this->host = $config->get( 'SupportSystemOpenSearchHost' );
		$this->port = $config->get( 'SupportSystemOpenSearchPort' );
		$this->indexName = $config->get( 'SupportSystemOpenSearchIndex' );
		$this->useMock = $config->get( 'SupportSystemUseMock' );
		
		if ( $this->useMock ) {
			$this->loadMockData();
		}
	}
	
	/**
	 * Load mock data for testing
	 */
	private function loadMockData(): void {
		$this->mockData = [
			[
				'id' => 'sol1',
				'title' => 'Решение проблем с Wi-Fi подключением',
				'content' => '1. Перезагрузите роутер. 2. Проверьте настройки Wi-Fi на устройстве. 3. Убедитесь, что пароль вводится правильно.',
				'tags' => ['wi-fi', 'интернет', 'сеть', 'подключение'],
				'source' => 'mock'
			],
			[
				'id' => 'sol2',
				'title' => 'Исправление проблем с электронной почтой',
				'content' => '1. Проверьте подключение к интернету. 2. Убедитесь, что логин и пароль верны. 3. Очистите кэш приложения.',
				'tags' => ['email', 'почта', 'авторизация'],
				'source' => 'mock'
			],
			[
				'id' => 'sol3',
				'title' => 'Устранение неполадок с браузером',
				'content' => '1. Очистите историю и кэш браузера. 2. Обновите браузер до последней версии. 3. Проверьте настройки интернет-соединения.',
				'tags' => ['браузер', 'интернет', 'зависание'],
				'source' => 'mock'
			],
			[
				'id' => 'sol4',
				'title' => 'Решение проблем с операционной системой',
				'content' => '1. Перезагрузите компьютер. 2. Проверьте наличие обновлений. 3. Запустите диагностику системы.',
				'tags' => ['ОС', 'система', 'компьютер', 'обновление'],
				'source' => 'mock'
			],
			[
				'id' => 'sol5',
				'title' => 'Устранение проблем с мобильным приложением',
				'content' => '1. Переустановите приложение. 2. Очистите кэш и данные приложения. 3. Обновите приложение до последней версии.',
				'tags' => ['приложение', 'смартфон', 'мобильный', 'ошибка'],
				'source' => 'mock'
			]
		];
	}
	
	/**
	 * Search for solutions in OpenSearch
	 * @param string $query The search query
	 * @param int $size The number of results to return
	 * @return array
	 * @throws MWException
	 */
	public function search( string $query, int $size = 10 ): array {
		if ( $this->useMock ) {
			return $this->searchMock( $query );
		}
		
		return $this->searchOpenSearch( $query, $size );
	}
	
	/**
	 * Search in mock data
	 * @param string $query The search query
	 * @return array
	 */
	private function searchMock( string $query ): array {
		$results = [];
		$queryLower = strtolower( $query );
		
		foreach ( $this->mockData as $doc ) {
			$score = 0;
			
			// Check for keywords in title
			if ( strpos( strtolower( $doc['title'] ), $queryLower ) !== false ) {
				$score += 2;
			}
			
			// Check for keywords in content
			if ( strpos( strtolower( $doc['content'] ), $queryLower ) !== false ) {
				$score += 1;
			}
			
			// Check for keywords in tags
			if ( isset( $doc['tags'] ) ) {
				foreach ( $doc['tags'] as $tag ) {
					if ( strpos( strtolower( $tag ), $queryLower ) !== false ) {
						$score += 1;
						break;
					}
				}
			}
			
			if ( $score > 0 ) {
				$doc['score'] = $score;
				$results[] = $doc;
			}
		}
		
		// Sort by score
		usort( $results, function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );
		
		return $results;
	}
	
	/**
	 * Search in OpenSearch
	 * @param string $query The search query
	 * @param int $size The number of results to return
	 * @return array
	 * @throws MWException
	 */
	private function searchOpenSearch( string $query, int $size = 10 ): array {
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
			'postData' => json_encode( $requestData ),
			'headers' => [
				'Content-Type' => 'application/json'
			]
		];
		
		$response = Http::request( $url, $options );
		
		if ( $response === false ) {
			throw new MWException( 'Error connecting to OpenSearch' );
		}
		
		$data = json_decode( $response, true );
		
		if ( !isset( $data['hits']['hits'] ) ) {
			return [];
		}
		
		$results = [];
		
		foreach ( $data['hits']['hits'] as $hit ) {
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
     * Index a MediaWiki page in OpenSearch
     * @param int $pageId The page ID
     * @return bool
     */
    public function indexMediaWikiPage(int $pageId): bool
    {
        if ($this->useMock) {
            return true; // Do nothing in mock mode
        }

        $title = \Title::newFromID($pageId);
        if (!$title || !$title->exists()) {
            return false;
        }

        $page = \WikiPage::factory($title);
        $content = $page->getContent()->getTextForSearchIndex();
        $categories = [];

        // Get page categories
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_REPLICA);
        $res = $dbr->newSelectQueryBuilder()
            ->select('cl_to')
            ->from('categorylinks')
            ->where(['cl_from' => $pageId])
            ->caller(__METHOD__)
            ->fetchResultSet();

        foreach ($res as $row) {
            $categories[] = str_replace('_', ' ', $row->cl_to);
        }

        // Prepare document for indexing
        $document = [
            'id' => 'mediawiki_' . $pageId,
            'title' => $title->getText(),
            'content' => $content,
            'categories' => $categories,
            'url' => $title->getLocalURL(),
            'source' => 'mediawiki'
        ];

        // Index document in OpenSearch
        $url = "http://{$this->host}:{$this->port}/{$this->indexName}/_doc/{$document['id']}";

        $options = [
            'method' => 'PUT',
            'timeout' => 10,
            'postData' => json_encode($document),
            'headers' => [
                'Content-Type' => 'application/json'
            ]
        ];

        $response = Http::request($url, $options);

        return $response !== false;
    }

    /**
     * Search via AI service for more complex queries
     * @param string $query The search query
     * @param array $context Previous context (optional)
     * @return array
     */
    public function searchAI(string $query, array $context = []): array
    {
        $config = MediaWikiServices::getInstance()->getMainConfig();
        $aiServiceUrl = $config->get('SupportSystemAIServiceURL');

        $url = "{$aiServiceUrl}/api/search_ai";

        $requestData = [
            'query' => $query,
            'context' => $context
        ];

        $options = [
            'method' => 'POST',
            'timeout' => 30, // Longer timeout for AI processing
            'postData' => json_encode($requestData),
            'headers' => [
                'Content-Type' => 'application/json'
            ]
        ];

        $response = Http::request($url, $options);

        if ($response === false) {
            // Return fallback answer if AI service is unavailable
            return [
                'answer' => 'К сожалению, интеллектуальный поиск временно недоступен. Пожалуйста, попробуйте снова позже или воспользуйтесь стандартным поиском.',
                'sources' => [],
                'success' => false
            ];
        }

        $data = json_decode($response, true);

        return $data ?? [
            'answer' => 'Не удалось обработать ответ от сервиса ИИ. Пожалуйста, попробуйте снова позже.',
            'sources' => [],
            'success' => false
        ];
    }
}