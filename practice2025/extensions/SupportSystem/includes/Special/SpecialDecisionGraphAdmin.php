<?php

namespace MediaWiki\Extension\SupportSystem\Special;

use SpecialPage;
use HTMLForm;
use MediaWiki\Extension\SupportSystem\DecisionGraph;

/**
 * Special page for administering the decision tree graph
 */
class SpecialDecisionGraphAdmin extends SpecialPage
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct('DecisionGraphAdmin', 'edit');
    }

    /**
     * Show the special page
     * @param string|null $par Parameter passed to the page
     */
    public function execute($par)
    {
        $this->requireLogin();
        $this->checkPermissions();

        $this->setHeaders();
        $this->outputHeader();
        
        $out = $this->getOutput();
        $out->setPageTitle($this->msg('supportsystem-graphadmin-title'));
        $out->addModules('ext.supportSystem.graphAdmin');
        $graph = new DecisionGraph();
        $graphData = $graph->getGraph();
        $out->addWikiTextAsInterface($this->msg('supportsystem-graphadmin-desc')->text());
        $out->addHTML($this->createGraphEditorInterface($graphData));
    }

    /**
     * Create HTML for the graph editor interface
     * @param array $graphData The graph data
     * @return string HTML
     */
    private function createGraphEditorInterface(array $graphData): string
    {
        $nodesJson = json_encode($graphData['nodes']);
        $edgesJson = json_encode($graphData['edges']);

        $html = <<<HTML
<div class="support-graph-admin-container">
    <div class="support-graph-tabs">
        <button id="support-graph-tab-nodes" class="support-graph-tab active">Узлы</button>
        <button id="support-graph-tab-edges" class="support-graph-tab">Ребра</button>
        <button id="support-graph-tab-visualize" class="support-graph-tab">Визуализация</button>
    </div>
    
    <div id="support-graph-nodes-panel" class="support-graph-panel">
        <div class="support-graph-panel-header">
            <h3>Узлы графа</h3>
            <button id="support-graph-add-node" class="support-graph-add-button">Добавить узел</button>
        </div>
        <div id="support-graph-nodes-list" class="support-graph-list"></div>
    </div>
    
    <div id="support-graph-edges-panel" class="support-graph-panel" style="display: none;">
        <div class="support-graph-panel-header">
            <h3>Ребра графа</h3>
            <button id="support-graph-add-edge" class="support-graph-add-button">Добавить ребро</button>
        </div>
        <div id="support-graph-edges-list" class="support-graph-list"></div>
    </div>
    
    <div id="support-graph-visualize-panel" class="support-graph-panel" style="display: none;">
        <div class="support-graph-panel-header">
            <h3>Визуализация графа</h3>
        </div>
        <div id="support-graph-visualization" class="support-graph-visualization">
            <div class="support-loading">Загрузка визуализации...</div>
        </div>
    </div>
    
    <div id="support-graph-node-editor" class="support-graph-editor" style="display: none;">
        <h3>Редактирование узла</h3>
        <div class="support-form-group">
            <label for="support-node-id">ID узла:</label>
            <input type="text" id="support-node-id" class="support-input">
        </div>
        <div class="support-form-group">
            <label for="support-node-content">Содержимое:</label>
            <textarea id="support-node-content" class="support-textarea" rows="4"></textarea>
        </div>
        <div class="support-form-group">
            <label for="support-node-type">Тип узла:</label>
            <select id="support-node-type" class="support-select">
                <option value="question">Вопрос</option>
                <option value="solution">Решение</option>
            </select>
        </div>
        <div class="support-form-actions">
            <button id="support-node-save" class="support-submit-button">Сохранить</button>
            <button id="support-node-cancel" class="support-cancel-button">Отмена</button>
            <button id="support-node-delete" class="support-delete-button">Удалить</button>
        </div>
    </div>
    
    <div id="support-graph-edge-editor" class="support-graph-editor" style="display: none;">
        <h3>Редактирование ребра</h3>
        <div class="support-form-group">
            <label for="support-edge-source">Исходный узел:</label>
            <select id="support-edge-source" class="support-select"></select>
        </div>
        <div class="support-form-group">
            <label for="support-edge-target">Целевой узел:</label>
            <select id="support-edge-target" class="support-select"></select>
        </div>
        <div class="support-form-group">
            <label for="support-edge-label">Метка:</label>
            <input type="text" id="support-edge-label" class="support-input">
        </div>
        <div class="support-form-actions">
            <button id="support-edge-save" class="support-submit-button">Сохранить</button>
            <button id="support-edge-cancel" class="support-cancel-button">Отмена</button>
            <button id="support-edge-delete" class="support-delete-button">Удалить</button>
        </div>
    </div>
    
    <div class="support-graph-save-container">
        <button id="support-graph-save" class="support-graph-save-button">Сохранить граф</button>
        <span id="support-graph-save-status" class="support-graph-save-status"></span>
    </div>
</div>

<script>
// Store graph data for JavaScript access
var supportGraphNodes = $nodesJson;
var supportGraphEdges = $edgesJson;
</script>
HTML;

        return $html;
    }

    /**
     * Get the group name for categorization in Special:SpecialPages
     * @return string
     */
    protected function getGroupName()
    {
        return 'other';
    }
}