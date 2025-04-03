/**
 * JavaScript for the decision graph admin page
 */
(function () {
    'use strict';

    // Graph data
    var nodes = [];
    var edges = [];
    var editingNodeIndex = null;
    var editingEdgeIndex = null;
    var isDirty = false;

    /**
     * Initialize the graph admin page
     */
    function init() {
        nodes = window.supportGraphNodes || [];
        edges = window.supportGraphEdges || [];
        $('#support-graph-tab-nodes').on('click', function () {
            showTab('nodes');
        });
        $('#support-graph-tab-edges').on('click', function () {
            showTab('edges');
        });
        $('#support-graph-tab-visualize').on('click', function () {
            showTab('visualize');
            renderVisualization();
        });
        $('#support-graph-add-node').on('click', function () {
            showNodeEditor(null);
        });
        $('#support-node-save').on('click', function () {
            saveNode();
        });
        $('#support-node-cancel').on('click', function () {
            hideNodeEditor();
        });
        $('#support-node-delete').on('click', function () {
            if (confirm('Вы уверены, что хотите удалить этот узел? Это также удалит все связанные ребра.')) {
                deleteNode();
            }
        });
        $('#support-graph-add-edge').on('click', function () {
            showEdgeEditor(null);
        });
        $('#support-edge-save').on('click', function () {
            saveEdge();
        });
        $('#support-edge-cancel').on('click', function () {
            hideEdgeEditor();
        });
        $('#support-edge-delete').on('click', function () {
            if (confirm('Вы уверены, что хотите удалить это ребро?')) {
                deleteEdge();
            }
        });
        $('#support-graph-save').on('click', function () {
            saveGraph();
        });
        updateNodesList();
        updateEdgesList();
        $(document).on('click', '.support-graph-node-edit', function (e) {
            e.preventDefault();
            var index = $(this).data('index');
            showNodeEditor(index);
        });
        $(document).on('click', '.support-graph-edge-edit', function (e) {
            e.preventDefault();
            var index = $(this).data('index');
            showEdgeEditor(index);
        });
        $(window).on('beforeunload', function () {
            if (isDirty) {
                return 'У вас есть несохраненные изменения. Вы действительно хотите покинуть страницу?';
            }
        });
    }

    /**
     * Show a specific tab
     * @param {string} tabName Name of the tab to show
     */
    function showTab(tabName) {
        $('.support-graph-panel').hide();
        $('.support-graph-tab').removeClass('active');
        $('#support-graph-' + tabName + '-panel').show();
        $('#support-graph-tab-' + tabName).addClass('active');
        hideNodeEditor();
        hideEdgeEditor();
    }

    /**
     * Update the list of nodes
     */
    function updateNodesList() {
        var container = $('#support-graph-nodes-list');
        container.empty();
        if (nodes.length === 0) {
            container.html('<p>Нет добавленных узлов</p>');
            return;
        }
        var html = '<table class="support-graph-table">' +
            '<thead>' +
            '<tr>' +
            '<th>ID</th>' +
            '<th>Содержимое</th>' +
            '<th>Тип</th>' +
            '<th>Действия</th>' +
            '</tr>' +
            '</thead>' +
            '<tbody>';
        nodes.forEach(function (node, index) {
            var typeText = node.type === 'question' ? 'Вопрос' : 'Решение';
            var contentPreview = node.content.length > 50
                ? node.content.substring(0, 50) + '...'
                : node.content;
            html += '<tr>' +
                '<td>' + node.id + '</td>' +
                '<td>' + contentPreview + '</td>' +
                '<td>' + typeText + '</td>' +
                '<td>' +
                '<button class="support-graph-node-edit" data-index="' + index + '">Редактировать</button>' +
                '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        container.html(html);
    }

    /**
     * Update the list of edges
     */
    function updateEdgesList() {
        var container = $('#support-graph-edges-list');
        container.empty();
        if (edges.length === 0) {
            container.html('<p>Нет добавленных ребер</p>');
            return;
        }
        var html = '<table class="support-graph-table">' +
            '<thead>' +
            '<tr>' +
            '<th>От узла</th>' +
            '<th>К узлу</th>' +
            '<th>Метка</th>' +
            '<th>Действия</th>' +
            '</tr>' +
            '</thead>' +
            '<tbody>';
        edges.forEach(function (edge, index) {
            html += '<tr>' +
                '<td>' + edge.source + '</td>' +
                '<td>' + edge.target + '</td>' +
                '<td>' + (edge.label || '') + '</td>' +
                '<td>' +
                '<button class="support-graph-edge-edit" data-index="' + index + '">Редактировать</button>' +
                '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        container.html(html);
    }
    /**
     * Show the node editor
     * @param {number|null} nodeIndex Index of the node to edit, or null for a new node
     */
    function showNodeEditor(nodeIndex) {
        var editor = $('#support-graph-node-editor');
        var nodeData = nodeIndex !== null ? nodes[nodeIndex] : { id: '', content: '', type: 'question' };
        $('#support-node-id').val(nodeData.id);
        $('#support-node-content').val(nodeData.content);
        $('#support-node-type').val(nodeData.type);
        editingNodeIndex = nodeIndex;
        if (nodeIndex === null) { $('#support-node-delete').hide(); } 
        else { $('#support-node-delete').show(); }
        editor.show();
        $('#support-node-id').focus();
    }
    /**
     * Hide the node editor
     */
    function hideNodeEditor() {
        $('#support-graph-node-editor').hide();
        editingNodeIndex = null;
    }
    /**
     * Save the current node being edited
     */
    function saveNode() {
        var nodeId = $('#support-node-id').val().trim();
        var content = $('#support-node-content').val().trim();
        var type = $('#support-node-type').val();
        if (!nodeId) {
            alert('Пожалуйста, введите ID узла');
            $('#support-node-id').focus();
            return;
        }
        if (!content) {
            alert('Пожалуйста, введите содержимое узла');
            $('#support-node-content').focus();
            return;
        }
        var isNewId = editingNodeIndex === null || nodes[editingNodeIndex].id !== nodeId;
        if (isNewId) {
            var duplicate = nodes.some(function (node, index) {
                return node.id === nodeId && index !== editingNodeIndex;
            });
            if (duplicate) {
                alert('Узел с таким ID уже существует. Пожалуйста, используйте другой ID.');
                $('#support-node-id').focus();
                return;
            }
        }
        var nodeData = {
            id: nodeId,
            content: content,
            type: type
        };
        if (editingNodeIndex === null) { nodes.push(nodeData);}
        else { nodes[editingNodeIndex] = nodeData; }
        isDirty = true;
        hideNodeEditor();
        updateNodesList();
        updateEdgesList();
    }

    /**
     * Delete the current node being edited
     */
    function deleteNode() {
        if (editingNodeIndex === null) { return; }
        var nodeId = nodes[editingNodeIndex].id;
        nodes.splice(editingNodeIndex, 1);
        edges = edges.filter(function (edge) {
            return edge.source !== nodeId && edge.target !== nodeId;
        });
        isDirty = true;
        hideNodeEditor();
        updateNodesList();
        updateEdgesList();
    }
    /**
     * Show the edge editor
     * @param {number|null} edgeIndex Index of the edge to edit, or null for a new edge
     */
    function showEdgeEditor(edgeIndex) {
        var editor = $('#support-graph-edge-editor');
        var edgeData = edgeIndex !== null ? edges[edgeIndex] : { source: '', target: '', label: '' };
        var sourceSelect = $('#support-edge-source');
        var targetSelect = $('#support-edge-target');
        sourceSelect.empty();
        targetSelect.empty();
        nodes.forEach(function (node) {
            sourceSelect.append('<option value="' + node.id + '">' + node.id + ': ' + truncateText(node.content, 30) + '</option>');
            targetSelect.append('<option value="' + node.id + '">' + node.id + ': ' + truncateText(node.content, 30) + '</option>');
        });
        sourceSelect.val(edgeData.source);
        targetSelect.val(edgeData.target);
        $('#support-edge-label').val(edgeData.label || '');
        editingEdgeIndex = edgeIndex;
        if (edgeIndex === null) { $('#support-edge-delete').hide(); }
        else { $('#support-edge-delete').show(); }
        editor.show();
        $('#support-edge-label').focus();
    }
    /**
     * Hide the edge editor
     */
    function hideEdgeEditor() {
        $('#support-graph-edge-editor').hide();
        editingEdgeIndex = null;
    }
    /**
     * Save the current edge being edited
     */
    function saveEdge() {
        var source = $('#support-edge-source').val();
        var target = $('#support-edge-target').val();
        var label = $('#support-edge-label').val().trim();
        if (!source || !target) {
            alert('Пожалуйста, выберите исходный и целевой узлы');
            return;
        }
        var isDuplicate = false;
        edges.forEach(function (edge, index) {
            if (edge.source === source && edge.target === target && index !== editingEdgeIndex) {
                isDuplicate = true;
            }
        });
        if (isDuplicate) {
            alert('Ребро между этими узлами уже существует.');
            return;
        }
        var edgeData = {
            source: source,
            target: target,
            label: label
        };
        if (editingEdgeIndex === null) { edges.push(edgeData); } 
        else { edges[editingEdgeIndex] = edgeData; }
        isDirty = true;
        hideEdgeEditor();
        updateEdgesList();
    }

    /**
     * Delete the current edge being edited
     */
    function deleteEdge() {
        if (editingEdgeIndex === null) {
            return;
        }
        edges.splice(editingEdgeIndex, 1);
        isDirty = true;
        hideEdgeEditor();
        updateEdgesList();
    }

    function saveGraph() {
        var api = new mw.Api();
        $('#support-graph-save').prop('disabled', true);
        $('#support-graph-save-status').text('Сохранение...');
        api.postWithToken('csrf', {
            action: 'supportgraphadmin',
            graphaction: 'save',
            nodes: JSON.stringify(nodes),
            edges: JSON.stringify(edges)
        }).done(function (data) {
            if (data.result === 'success') {
                $('#support-graph-save-status').text('Граф успешно сохранен');
                isDirty = false;
                setTimeout(function () {
                    $('#support-graph-save-status').text('');
                }, 3000);
            } else {
                $('#support-graph-save-status').text('Ошибка при сохранении графа');
            }
        }).fail(function (error) {
            $('#support-graph-save-status').text('Ошибка при сохранении графа: ' + error);
            console.error('Error saving graph:', error);
        }).always(function () {
            $('#support-graph-save').prop('disabled', false);
        });
    }
    /**
     * Render the graph visualization
     */
    function renderVisualization() {
        var container = $('#support-graph-visualization');
        if (typeof mermaid === 'undefined') {
            var script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/mermaid/dist/mermaid.min.js';
            script.onload = function () {
                mermaid.initialize({
                    startOnLoad: false,
                    theme: 'default'
                });
                drawGraph();
            };
            document.head.appendChild(script);
        } else {
            drawGraph();
        }
        function drawGraph() {
            var graphDefinition = 'graph TD\n';
            nodes.forEach(function (node) {
                var shape = node.type === 'question' ? '[' : '(';
                var endShape = node.type === 'question' ? ']' : ')';
                graphDefinition += '    ' + node.id + shape + node.id + ': ' + truncateText(node.content, 20) + endShape + '\n';
            });
            edges.forEach(function (edge) {
                graphDefinition += '    ' + edge.source + ' -->|' + (edge.label || '') + '| ' + edge.target + '\n';
            });
            container.html('<div class="mermaid">' + graphDefinition + '</div>');
            mermaid.init(undefined, $('.mermaid'));
        }
    }

    /**
     * Truncate text to a maximum length
     * @param {string} text The text to truncate
     * @param {number} maxLength Maximum length
     * @return {string} Truncated text
     */
    function truncateText(text, maxLength) {
        if (text.length <= maxLength) {
            return text;
        }
        return text.substring(0, maxLength) + '...';
    }
    $(init);

}());