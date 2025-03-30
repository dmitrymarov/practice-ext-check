<?php

namespace MediaWiki\Extension\SupportSystem;

use MediaWiki\MediaWikiServices;
use FormatJson;

/**
 * Class for working with the decision graph
 */
class DecisionGraph
{
    /** @var array The graph data */
    private $graph;

    /** @var string The path to the graph data file */
    private $filePath;

    /**
     * Constructor
     */
    public function __construct()
    {
        $config = MediaWikiServices::getInstance()->getMainConfig();
        $this->filePath = $config->get('SupportSystemGraphDataFile');
        $this->loadGraph();
    }

    /**
     * Load the graph from a file
     */
    private function loadGraph(): void
    {
        if (file_exists($this->filePath)) {
            $content = file_get_contents($this->filePath);
            $this->graph = FormatJson::decode($content, true);
        } else {
            // Create a default graph if file doesn't exist
            $this->graph = $this->createDefaultGraph();
            $this->saveGraph();
        }
    }

    /**
     * Save the graph to a file
     */
    public function saveGraph(): void
    {
        $content = FormatJson::encode($this->graph, true);
        file_put_contents($this->filePath, $content);
    }

    /**
     * Create a default graph
     * @return array
     */
    private function createDefaultGraph(): array
    {
        return [
            'nodes' => [
                [
                    'id' => 'q1',
                    'content' => 'У вас проблема с доступом к интернету или с работой приложения?',
                    'type' => 'question'
                ],
                [
                    'id' => 'q2',
                    'content' => 'Проверьте подключение к Wi-Fi. Видите ли вы сеть в списке доступных?',
                    'type' => 'question'
                ],
                [
                    'id' => 'q3',
                    'content' => 'Какое устройство вы используете?',
                    'type' => 'question'
                ],
                [
                    'id' => 's1',
                    'content' => 'Перезагрузите роутер и попробуйте подключиться снова.',
                    'type' => 'solution'
                ],
                [
                    'id' => 's2',
                    'content' => 'Проверьте настройки Wi-Fi на вашем устройстве. Убедитесь, что режим "В самолете" выключен.',
                    'type' => 'solution'
                ],
                [
                    'id' => 'q4',
                    'content' => 'Какое приложение вызывает проблемы?',
                    'type' => 'question'
                ],
                [
                    'id' => 'q5',
                    'content' => 'Какую ошибку показывает приложение?',
                    'type' => 'question'
                ],
                [
                    'id' => 's3',
                    'content' => 'Попробуйте переустановить приложение.',
                    'type' => 'solution'
                ],
                [
                    'id' => 's4',
                    'content' => 'Очистите кэш приложения и перезапустите его.',
                    'type' => 'solution'
                ]
            ],
            'edges' => [
                ['source' => 'q1', 'target' => 'q2', 'label' => 'Проблема с интернетом'],
                ['source' => 'q1', 'target' => 'q4', 'label' => 'Проблема с приложением'],
                ['source' => 'q2', 'target' => 'q3', 'label' => 'Нет'],
                ['source' => 'q2', 'target' => 's1', 'label' => 'Да'],
                ['source' => 'q3', 'target' => 's2', 'label' => 'Смартфон'],
                ['source' => 'q3', 'target' => 's1', 'label' => 'Компьютер'],
                ['source' => 'q4', 'target' => 'q5', 'label' => 'Email'],
                ['source' => 'q4', 'target' => 's3', 'label' => 'Браузер'],
                ['source' => 'q5', 'target' => 's3', 'label' => 'Ошибка авторизации'],
                ['source' => 'q5', 'target' => 's4', 'label' => 'Приложение зависает']
            ]
        ];
    }

    /**
     * Get the root node of the graph
     * @return string|null
     */
    public function getRootNode(): ?string
    {
        if (empty($this->graph['nodes'])) {
            return null;
        }

        // First node is assumed to be the root
        return $this->graph['nodes'][0]['id'];
    }

    /**
     * Get a node by ID
     * @param string $nodeId
     * @return array|null
     */
    public function getNode(string $nodeId): ?array
    {
        foreach ($this->graph['nodes'] as $node) {
            if ($node['id'] === $nodeId) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Get children of a node
     * @param string $nodeId
     * @return array
     */
    public function getChildren(string $nodeId): array
    {
        $children = [];

        foreach ($this->graph['edges'] as $edge) {
            if ($edge['source'] === $nodeId) {
                $targetNode = $this->getNode($edge['target']);
                if ($targetNode) {
                    $children[] = [
                        'id' => $targetNode['id'],
                        'content' => $targetNode['content'],
                        'type' => $targetNode['type'],
                        'label' => $edge['label'] ?? ''
                    ];
                }
            }
        }

        return $children;
    }
}