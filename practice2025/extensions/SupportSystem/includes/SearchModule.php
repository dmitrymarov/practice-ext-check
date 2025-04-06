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
	}
}