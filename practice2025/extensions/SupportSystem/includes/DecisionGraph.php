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

    public function __construct()
    {
        $config = MediaWikiServices::getInstance()->getMainConfig();
        $this->filePath = $config->get('SupportSystemGraphDataFile');
        $this->loadGraph();
    }

    private function loadGraph(): void
    {
        if (file_exists($this->filePath)) {
            $content = file_get_contents($this->filePath);
            $this->graph = FormatJson::decode($content, true);
        } else {
            $this->graph = $this->createDefaultGraph();
            $this->saveGraph();
        }
    }

    /**
     * Save the graph to a file
     * @return bool Success status
     */
    public function saveGraph(): bool
    {
        try {
            $content = FormatJson::encode($this->graph, true);
            return file_put_contents($this->filePath, $content) !== false;
        } catch (\Exception $e) {
            return false;
        }
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
     * Get the entire graph data
     * @return array The graph data
     */
    public function getGraph(): array
    {
        return $this->graph;
    }

    /**
     * Set the entire graph data
     * @param array $graphData The graph data
     */
    public function setGraph(array $graphData): void
    {
        $this->graph = $graphData;
    }

    /**
     * Get the root node of the graph
     * @return string|null
     */
    public function getRootNode(): ?string
    {
        if (empty($this->graph['nodes'])) { return null; }
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
            if ($node['id'] === $nodeId) { return $node; }
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
    
    /**
     * Add a new node to the graph
     * @param array $node The node data
     * @return bool Success status
     */
    public function addNode(array $node): bool
    {
        if (!isset($node['id']) || !isset($node['content']) || !isset($node['type'])) { return false; }
        foreach ($this->graph['nodes'] as $existingNode) {
            if ($existingNode['id'] === $node['id']) { return false; }
        }
        $this->graph['nodes'][] = $node;
        return true;
    }
    
    /**
     * Update an existing node
     * @param string $nodeId The node ID
     * @param array $nodeData The new node data
     * @return bool Success status
     */
    public function updateNode(string $nodeId, array $nodeData): bool
    {
        foreach ($this->graph['nodes'] as $key => $node) {
            if ($node['id'] === $nodeId) {
                if (isset($nodeData['id']) && $nodeData['id'] !== $nodeId) {
                    $newId = $nodeData['id'];
                    foreach ($this->graph['edges'] as $edgeKey => $edge) {
                        if ($edge['source'] === $nodeId) { $this->graph['edges'][$edgeKey]['source'] = $newId; }
                    }
                    foreach ($this->graph['edges'] as $edgeKey => $edge) {
                        if ($edge['target'] === $nodeId) { $this->graph['edges'][$edgeKey]['target'] = $newId; }
                    }
                }
                $this->graph['nodes'][$key] = array_merge($node, $nodeData);
                return true;
            }
        }
        return false;
    }
    
    /**
     * Delete a node from the graph
     * @param string $nodeId The node ID
     * @return bool Success status
     */
    public function deleteNode(string $nodeId): bool
    {
        $found = false;
        foreach ($this->graph['nodes'] as $key => $node) {
            if ($node['id'] === $nodeId) {
                unset($this->graph['nodes'][$key]);
                $this->graph['nodes'] = array_values($this->graph['nodes']);
                $found = true;
                break;
            }
        }
        if (!$found) { return false; }
        foreach ($this->graph['edges'] as $key => $edge) {
            if ($edge['source'] === $nodeId || $edge['target'] === $nodeId) { unset($this->graph['edges'][$key]); }
        }
        $this->graph['edges'] = array_values($this->graph['edges']);
        return true;
    }
    
    /**
     * Add an edge to the graph
     * @param array $edge The edge data
     * @return bool Success status
     */
    public function addEdge(array $edge): bool
    {
        if (!isset($edge['source']) || !isset($edge['target'])) { return false; }
        $sourceExists = false;
        $targetExists = false;
        foreach ($this->graph['nodes'] as $node) {
            if ($node['id'] === $edge['source']) { $sourceExists = true; }
            if ($node['id'] === $edge['target']) { $targetExists = true; }
        }
        if (!$sourceExists || !$targetExists) { return false; }
        foreach ($this->graph['edges'] as $existingEdge) {
            if ($existingEdge['source'] === $edge['source'] && $existingEdge['target'] === $edge['target']) { return false; }
        }
        $this->graph['edges'][] = $edge;
        return true;
    }
    
    /**
     * Update an existing edge
     * @param int $edgeIndex Index of the edge to update
     * @param array $edgeData The new edge data
     * @return bool Success status
     */
    public function updateEdge(int $edgeIndex, array $edgeData): bool
    {
        if (!isset($this->graph['edges'][$edgeIndex])) { return false; }
        if ((isset($edgeData['source']) || isset($edgeData['target']))) {
            $sourceNode = isset($edgeData['source']) ? $edgeData['source'] : $this->graph['edges'][$edgeIndex]['source'];
            $targetNode = isset($edgeData['target']) ? $edgeData['target'] : $this->graph['edges'][$edgeIndex]['target'];
            $sourceExists = false;
            $targetExists = false;
            foreach ($this->graph['nodes'] as $node) {
                if ($node['id'] === $sourceNode) { $sourceExists = true; }
                if ($node['id'] === $targetNode) { $targetExists = true; }
            }
            if (!$sourceExists || !$targetExists) { return false; }
        }
        $this->graph['edges'][$edgeIndex] = array_merge($this->graph['edges'][$edgeIndex], $edgeData);
        return true;
    }
    
    /**
     * Delete an edge from the graph
     * @param int $edgeIndex Index of the edge to delete
     * @return bool Success status
     */
    public function deleteEdge(int $edgeIndex): bool
    {
        if (!isset($this->graph['edges'][$edgeIndex])) { return false; }
        unset($this->graph['edges'][$edgeIndex]);
        $this->graph['edges'] = array_values($this->graph['edges']);
        return true;
    }
}