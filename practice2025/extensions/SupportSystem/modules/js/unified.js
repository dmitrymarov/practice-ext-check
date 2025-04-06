
// Глобальные переменные для хранения выбранного решения и источника
var selectedSolution = '';
var selectedSource = '';

(function () {
    'use strict';
    var config = mw.config.get('supportsystemConfig') || {};
    var messages = config.messages || {};
    var currentSolution = '';
    var selectedSolution = '';
    var selectedSource = '';
    var dialogHistory = [];
    var currentNodeId = 'root';
    var searchState = {
        graphSearchDone: false,
        wikiSearchDone: false,
        aiSearchDone: false,
        searchQuery: ''
    };
    function init() {
        $('.support-tab').on('click', function () {
            var panelId = $(this).data('panel');
            showPanel(panelId);
        });
        initDialogTab();
        initSearchTab();
        initTicketsTab();
        $('#support-ticket-form-element').on('submit', function (e) {
            e.preventDefault();
            submitTicket();
        });
        $('#support-ticket-close, #support-ticket-cancel').on('click', function () {
            $('#support-ticket-form').hide();
        });
        loadTickets();
    }

    /**
     * Показать определенную панель/вкладку
     * @param {string} panelId Идентификатор панели
     */
    function showPanel(panelId) {
        $('.support-tab').removeClass('active');
        $('.support-panel').removeClass('active');
        $('#support-tab-' + panelId).addClass('active');
        $('#support-panel-' + panelId).addClass('active');
    }

    function initDialogTab() {
        $('#support-start-button').on('click', function () {
            $(this).remove();
            $('.support-welcome-message').remove();
            loadNode('root');
        });
        $('#support-restart-button').on('click', function () {
            $('#support-chat-container').empty();
            $('#support-solution-container').hide();
            $('#support-options-container').show();
            dialogHistory = [];
            loadNode('root');
        });
        $('#support-create-ticket-button').on('click', function () {
            showTicketForm(currentSolution, 'dialog');
        });
        $('#support-ai-search-button').on('click', function () {
            searchAI(currentSolution);
        });
        $('#support-wiki-search-button').on('click', function () {
            $('#support-search-input').val(currentSolution);
            showPanel('search');
            searchSolutions(currentSolution);
        });
        $('#support-ai-accept-button').on('click', function () {
            $('#support-ai-container').hide();
            $('#support-solution-container').show();
        });
        $('#support-ai-ticket-button').on('click', function () {
            var aiText = $('#support-ai-text').text();
            showTicketForm(aiText, 'ai');
        });
        $('#support-ai-back-button').on('click', function () {
            $('#support-ai-container').hide();
            $('#support-solution-container').show();
        });
        $(document).on('click', '.support-option-btn', function () {
            var childId = $(this).data('child-id');
            var optionText = $(this).text();
            addMessage(optionText, 'user');
            $('#support-options-container').empty();
            dialogHistory.push({
                nodeId: currentNodeId,
                selectedOption: optionText,
                selectedNodeId: childId
            });
            loadNode(childId);
        });
    }

    /**
     * Загрузка узла графа
     * @param {string} nodeId ID узла
     */
    function loadNode(nodeId) {
        var api = new mw.Api();
        api.get({
            action: 'supportnode',
            node_id: nodeId
        }).done(function (data) {
            currentNodeId = data.supportnode.id;
            addMessage(data.supportnode.content, 'system');
            if (data.supportnode.type === 'question') {
                showOptions(data.supportnode.children);
            } else {
                currentSolution = data.supportnode.content;
                showSolution(data.supportnode.content);
            }
            scrollChatToBottom();
        }).fail(function () {
            mw.notify(messages.error_loading_node || 'Error loading node', { type: 'error' });
        });
    }

    /**
     * Добавить сообщение в чат
     * @param {string} text Текст сообщения
     * @param {string} sender 'system' или 'user'
     */
    function addMessage(text, sender) {
        var className = sender === 'system' ? 'support-system-message' : 'support-user-message';
        var align = sender === 'system' ? 'left' : 'right';
        var bubbleClass = sender === 'system' ? 'support-system-bubble' : 'support-user-bubble';
        var html = '<div class="' + className + '">' +
            '<div class="support-message-align-' + align + '">' +
            '<div class="' + bubbleClass + '">' + text + '</div>' +
            '</div>' +
            '</div>';
        $('#support-chat-container').append(html);
    }

    /**
     * Показать опции для вопроса
     * @param {Array} options Опции выбора
     */
    function showOptions(options) {
        var container = $('#support-options-container');
        container.empty();
        options.forEach(function (option) {
            var button = $('<button>')
                .addClass('support-option-btn')
                .text(option.label)
                .data('child-id', option.id);

            container.append(button);
        });
        container.show();
    }

    /**
     * Показать найденное решение
     * @param {string} text Текст решения
     */
    function showSolution(text) {
        $('#support-solution-text').text(text);
        $('#support-options-container').hide();
        $('#support-wiki-search-button').show();
        $('#support-ai-search-button').show();
        $('#support-solution-container').show();
        currentSolution = text;
        searchState.graphSearchDone = true;
    }

    /**
     * Поиск с использованием AI
     * @param {string} query Поисковый запрос
     */
    function searchAI(query) {
        var api = new mw.Api();
        searchState.searchQuery = query || searchState.searchQuery || currentSolution;
        var context = [];
        dialogHistory.forEach(function (item) {
            context.push({
                question: item.nodeId,
                answer: item.selectedOption
            });
        });
        $('#support-ai-loading').show();
        $('#support-ai-content').hide();
        $('#support-ai-container').show();
        $('#support-solution-container').hide();
        api.get({
            action: 'aibridge',
            query: searchState.searchQuery,
            context: JSON.stringify(context),
            format: 'json'
        }).done(function (data) {
            searchState.aiSearchDone = true;
            if (data.ai_result && data.ai_result.success) {
                $('#support-ai-text').text(data.ai_result.answer);
                $('#support-ai-loading').hide();
                $('#support-ai-content').show();
                if (data.ai_result.sources && data.ai_result.sources.length > 0) {
                    var sourcesList = $('#support-ai-sources-list');
                    sourcesList.empty();
                    data.ai_result.sources.forEach(function (source) {
                        var li = $('<li>');
                        if (source.url) {
                            li.append($('<a>')
                                .attr('href', source.url)
                                .attr('target', '_blank')
                                .text(source.title)
                            );
                        } else {li.text(source.title);}
                        sourcesList.append(li);
                    });
                    $('#support-ai-sources').show();
                } else {$('#support-ai-sources').hide();}
                $('#support-ai-ticket-button').show();
            } else {
                $('#support-ai-text').text(data.ai_result && data.ai_result.answer ||
                    getMessage('supportsystem-dt-ai-error', 'An error occurred while processing the AI request.'));
                $('#support-ai-loading').hide();
                $('#support-ai-content').show();
                $('#support-ai-sources').hide();
                $('#support-ai-ticket-button').show();
            }
        }).fail(function () {
            searchState.aiSearchDone = true;
            $('#support-ai-text').text(getMessage('supportsystem-dt-ai-error', 'An error occurred while processing the AI request.'));
            $('#support-ai-loading').hide();
            $('#support-ai-content').show();
            $('#support-ai-sources').hide();
            $('#support-ai-ticket-button').show();
        });
    }
    function scrollChatToBottom() {
        var container = document.getElementById('support-chat-container');
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
    }
    function initSearchTab() {
        $('#support-search-button').on('click', function () {
            var query = $('#support-search-input').val().trim();
            if (query) {
                searchSolutions(query);
            } else {
                mw.notify(messages.search_empty || 'Please enter a search query', { type: 'error' });
            }
        });
        $('#support-search-input').on('keypress', function (e) {
            if (e.which === 13) {
                var query = $(this).val().trim();
                if (query) {
                    searchSolutions(query);
                } else {
                    mw.notify(messages.search_empty || 'Please enter a search query', { type: 'error' });
                }
            }
        });
    }

    /**
     * Поиск решений через API (использующее curl внутри)
     * @param {string} query Поисковый запрос
     */
    function searchSolutions(query) {
        var useAI = $('#support-search-use-ai').is(':checked');
        $('#support-search-results').html(
            '<div class="support-loading">' +
            '<div class="support-spinner"></div>' +
            '<p>' + getMessage('supportsystem-search-loading', 'Поиск...') + '</p>' +
            '</div>'
        );
        var api = new mw.Api();
        api.get({
            action: 'unifiedsearch',
            query: query,
            use_ai: useAI,
            sources: 'opensearch|mediawiki',
            context: JSON.stringify(dialogHistory)
        }).done(function (data) {
            var results = [];
            if (data.results && data.results.opensearch) {
                data.results.opensearch.forEach(function (result) {
                    results.push(result);
                });
            }
            if (data.results && data.results.cirrus) {
                data.results.cirrus.forEach(function (result) { results.push(result); });
            }
            results.sort(function (a, b) { return b.score - a.score;
            });

            if (useAI && data.results && data.results.ai) { displayAIResult(data.results.ai, query); }
            else if (results.length > 0) { displaySearchResults(results, query); }
            else {
                $('#support-search-results').html(
                    '<div class="support-no-results">' +
                    '<p>' + getMessage('supportsystem-search-noresults', 'Результатов не найдено. Попробуйте изменить запрос.') + '</p>' +
                    '</div>' +
                    (useAI ? '' : '<div class="support-try-ai">' +
                        '<button id="support-search-ai-button" class="support-button-primary">' +
                        getMessage('supportsystem-search-try-ai', 'Попробуйте AI-поиск') + '</button>' +
                        '</div>')
                );
                $('#support-search-ai-button').on('click', function () {
                    $('#support-search-use-ai').prop('checked', true);
                    searchSolutions(query);
                });
            }
        }).fail(function () {
            $('#support-search-results').html(
                '<div class="support-error">' +
                '<p>' + getMessage('supportsystem-search-error', 'Произошла ошибка при поиске.') + '</p>' +
                '</div>'
            );
        });
    }

    /**
     * Отображение результатов AI поиска
     * @param {Object} aiResult Результат AI поиска
     * @param {string} query Поисковый запрос
     */
    function displayAIResult(aiResult, query) {
        var resultsHtml = '<div class="support-ai-result">';
        resultsHtml += '<h3>' + (mw.msg('supportsystem-search-ai-result-title') || 'AI-based Answer') + '</h3>';
        var formattedAnswer = aiResult.answer
            .replace(/\n/g, '<br>')
            .replace(/(\d+\. )/g, '<br>$1');
        resultsHtml += '<div class="support-ai-answer">' + formattedAnswer + '</div>';
        if (aiResult.sources && aiResult.sources.length > 0) {
            resultsHtml += '<div class="support-ai-sources">';
            resultsHtml += '<h4>' + (mw.msg('supportsystem-search-ai-sources') || 'Information Sources') + '</h4>';
            resultsHtml += '<ul>';
            aiResult.sources.forEach(function (source) {
                if (source.url) {
                    resultsHtml += '<li><a href="' + source.url + '" target="_blank">' + source.title + '</a></li>';
                } else {
                    resultsHtml += '<li>' + source.title + '</li>';
                }
            });
            resultsHtml += '</ul></div>';
        }
        resultsHtml += '<div class="support-ai-actions">';
        resultsHtml += '<button class="support-create-ticket-btn support-button-primary" data-solution="' +
            aiResult.answer.replace(/"/g, '&quot;') + '" data-source="ai">' +
            (mw.msg('supportsystem-search-create-ticket-ai') || 'Create ticket with this answer') + '</button>';
        resultsHtml += '</div>';
        resultsHtml += '</div>';
        $('#support-search-results').html(resultsHtml);
        $('.support-create-ticket-btn').on('click', function () {
            var solution = $(this).data('solution');
            var source = $(this).data('source');
            showTicketForm(solution, source);
        });
    }

    /**
     * Отображение результатов обычного поиска
     * @param {Array} results Результаты поиска
     * @param {string} query Поисковый запрос
     */
    function displaySearchResults(results, query) {
        var resultsHtml = '';
        resultsHtml += '<h3>' + (mw.msg('supportsystem-search-results-count', results.length) || 'Found solutions: ' + results.length) + '</h3>';
        results.forEach(function (result) {
            var source = result.source || 'unknown';
            var sourceLabel = getSourceLabel(source);
            var badgeClass = 'support-badge-' + source;

            var content = result.content;
            if (result.highlight) {
                content = result.highlight.replace(/\n/g, '<br>');
            } else {
                var maxLength = 300;
                if (content && content.length > maxLength) {
                    content = content.substring(0, maxLength) + '...';
                }
            }
            var tagsHtml = '';
            if (result.tags && result.tags.length > 0) {
                tagsHtml = '<div class="support-result-tags"><strong>' +
                    (mw.msg('supportsystem-search-tags') || 'Tags') + ':</strong> ';
                tagsHtml += result.tags.map(function (tag) {
                    return '<span class="support-tag">' + tag + '</span>';
                }).join(' ');
                tagsHtml += '</div>';
            }
            resultsHtml +=
                '<div class="support-result-card">' +
                '<div class="support-result-header">' +
                '<h4>' + result.title + '</h4>' +
                '<div class="support-result-meta">' +
                '<span class="support-badge ' + badgeClass + '">' + sourceLabel + '</span>' +
                '<span class="support-score-badge">' +
                (mw.msg('supportsystem-search-relevance', Math.round(result.score * 10) / 10) || 'Relevance: ' + (Math.round(result.score * 10) / 10)) +
                '</span>' +
                '</div>' +
                '</div>' +
                '<div class="support-result-body">' +
                '<div class="support-result-content">' + content + '</div>' +
                tagsHtml +
                '<div class="support-result-actions">' +
                (result.url ? '<a href="' + result.url + '" target="_blank" class="support-source-link support-button-secondary">' +
                    (mw.msg('supportsystem-search-source-link') || 'Go to source') + '</a>' : '') +
                '<button class="support-create-ticket-btn support-button-primary" data-solution="' +
                result.content.replace(/"/g, '&quot;') + '" data-source="' + source + '">' +
                (mw.msg('supportsystem-search-create-ticket') || 'Create ticket with this solution') + '</button>' +
                '</div>' +
                '</div>' +
                '</div>';
        });
        $('#support-search-results').html(resultsHtml);
        $('.support-create-ticket-btn').on('click', function () {
            var solution = $(this).data('solution');
            var source = $(this).data('source');
            showTicketForm(solution, source);
        });
    }

    /**
     * Получить метку источника
     * @param {string} source Источник
     * @return {string} Метка источника
     */
    function getSourceLabel(source) {
        var defaultLabels = {
            'opensearch': 'Knowledge Base',
            'mediawiki': 'MediaWiki',
            'ai': 'AI Analysis',
            'unknown': 'Unknown Source',
            'dialog': 'Dialog'
        };
        var key = 'supportsystem-search-source-' + source;
        var message = mw.msg(key);
        if (message && message.indexOf('⧼') === -1 && message !== key) {
            return message;
        }
        return defaultLabels[source] || 'Unknown Source';
    }
    function initTicketsTab() {
        $('#support-tickets-create').on('click', function () {
            showTicketForm('', 'new');
        });
        $('#support-ticket-details-back').on('click', function () {
            $('#support-ticket-details').hide();
            $('#support-tickets-list').show();
        });
        $('#support-comment-submit').on('click', function () {
            var ticketId = $('#support-ticket-details').data('ticket-id');
            var comment = $('#support-comment-text').val().trim();
            if (!comment) {
                mw.notify(mw.msg('supportsystem-sd-ticket-comment-required') || 'Please enter a comment', { type: 'error' });
                return;
            }
            addComment(ticketId, comment);
        });
    }

    /**
     * Функция загрузки списка тикетов через curl
     * @param {number} limit Максимальное количество тикетов
     * @param {number} offset Смещение для пагинации
     */
    function loadTickets(limit = 25, offset = 0) {
        $('#support-tickets-list').html(
            '<div class="support-loading">' +
            '<div class="support-spinner"></div>' +
            '<p>' + (mw.msg('supportsystem-sd-loading') || 'Загрузка тикетов...') + '</p>' +
            '</div>'
        );
        var api = new mw.Api();
        api.get({
            action: 'supportticket',
            operation: 'list',
            limit: limit,
            offset: offset
        }).done(function (data) {
            if (data.tickets && data.tickets.length > 0) {
                displayTickets(data.tickets);
            } else {
                $('#support-tickets-list').html(
                    '<div class="support-empty-list">' +
                    '<p>' + (mw.msg('supportsystem-sd-empty') || 'У вас нет тикетов') + '</p>' +
                    '</div>'
                );
            }
        }).fail(function () {
            $('#support-tickets-list').html(
                '<div class="support-error">' +
                '<p>' + (mw.msg('supportsystem-sd-error') || 'Ошибка загрузки тикетов') + '</p>' +
                '</div>'
            );
        });
    }

    /**
     * Функция отображения списка тикетов
     * @param {Array} tickets Список тикетов
     */
    function displayTickets(tickets) {
        var listHtml = '';
        tickets.sort(function (a, b) {
            return new Date(b.created_on) - new Date(a.created_on);
        });
        tickets.forEach(function (ticket) {
            var statusName = ((ticket.status || {}).name || 'Новый');
            var statusClass = 'support-status-' + statusName.toLowerCase().replace(/\s+/g, '-');
            var priorityMapping = {
                1: { class: 'support-priority-red', name: 'Критический' },
                2: { class: 'support-priority-orange', name: 'Высокий' },
                6: { class: 'support-priority-normal', name: 'Нормальный' },
                3: { class: 'support-priority-green', name: 'Низкий' }
            };

            var priority = priorityMapping[6];
            if (ticket.priority && ticket.priority.id) {
                priority = priorityMapping[ticket.priority.id] || priority;
            }
            listHtml +=
                '<div class="support-ticket-item" data-ticket-id="' + ticket.id + '">' +
                '<div class="support-ticket-header">' +
                '<h4>#' + ticket.id + ': ' + ticket.subject + '</h4>' +
                '</div>' +
                '<div class="support-ticket-info">' +
                '<span class="' + statusClass + '">' + statusName + '</span> ' +
                '<span class="' + priority.class + '">' + priority.name + '</span>' +
                '<span class="support-ticket-date">' + formatDate(ticket.created_on) + '</span>' +
                '</div>' +
                '</div>';
        });
        $('#support-tickets-list').html(listHtml);
        $('.support-ticket-item').on('click', function () {
            var ticketId = $(this).data('ticket-id');
            viewTicket(ticketId);
        });
    }

    /**
     * Функция просмотра тикета по ID, переписанная под curl
     * @param {number} ticketId ID тикета
     */
    function viewTicket(ticketId) {
        $('#support-tickets-list').hide();
        $('#support-ticket-details').show().data('ticket-id', ticketId);
        $('#support-ticket-details').html(
            '<div class="support-loading">' +
            '<div class="support-spinner"></div>' +
            '<p>' + (mw.msg('supportsystem-sd-loading') || 'Загрузка тикета...') + '</p>' +
            '</div>'
        );
        var api = new mw.Api();
        api.get({
            action: 'supportticket',
            operation: 'get',
            ticket_id: ticketId
        }).done(function (data) {
            if (data.ticket) {
                displayTicketDetails(data.ticket);
            } else {
                $('#support-ticket-details').html(
                    '<div class="support-error">' +
                    '<p>' + (mw.msg('supportsystem-error-ticket-not-found') || 'Тикет не найден') + '</p>' +
                    '<button id="support-ticket-details-back" class="support-button-secondary">' +
                    (mw.msg('supportsystem-sd-ticket-back') || 'Назад к списку') + '</button>' +
                    '</div>'
                );
                $('#support-ticket-details-back').on('click', function () {
                    $('#support-ticket-details').hide();
                    $('#support-tickets-list').show();
                });
            }
        }).fail(function () {
            $('#support-ticket-details').html(
                '<div class="support-error">' +
                '<p>' + (mw.msg('supportsystem-sd-ticket-error') || 'Ошибка загрузки тикета') + '</p>' +
                '<button id="support-ticket-details-back" class="support-button-secondary">' +
                (mw.msg('supportsystem-sd-ticket-back') || 'Назад к списку') + '</button>' +
                '</div>'
            );
            $('#support-ticket-details-back').on('click', function () {
                $('#support-ticket-details').hide();
                $('#support-tickets-list').show();
            });
        });
    }

    /**
     * Отображение деталей тикета с упрощенным интерфейсом для конструкторского бюро
     * @param {Object} ticket Данные тикета
     */
    function displayTicketDetails(ticket) {
        if ($('#support-ticket-details-title').length === 0) {
            $('#support-ticket-details').html(`
            <div class="support-ticket-details-header">
                <h3 id="support-ticket-details-title"></h3>
                <button id="support-ticket-details-back" class="support-button-secondary">
                    ${mw.msg('supportsystem-sd-ticket-back') || 'Назад к списку'}
                </button>
            </div>
            
            <div class="support-ticket-details-info">
                <div class="support-ticket-status">
                    <span class="support-label">${mw.msg('supportsystem-sd-ticket-status') || 'Статус'}:</span>
                    <span id="support-ticket-status"></span>
                </div>
                <div class="support-ticket-priority">
                    <span class="support-label">${mw.msg('supportsystem-sd-ticket-priority') || 'Приоритет'}:</span>
                    <span id="support-ticket-priority-value"></span>
                </div>
            </div>
            
            <div class="support-ticket-description-section">
                <h4>${mw.msg('supportsystem-sd-ticket-description') || 'Описание'}</h4>
                <div id="support-ticket-description-text" class="support-ticket-description-content"></div>
            </div>
            
            <!-- Область для кастомных полей -->
            <div id="support-ticket-custom-fields"></div>
            
            <!-- Область для вложений -->
            <div id="support-ticket-attachments" class="support-ticket-attachments-section">
                <h4>${mw.msg('supportsystem-attachment-list') || 'Вложения'}</h4>
                <div class="support-attachments-list"></div>
            </div>
            
            <!-- Область для комментариев -->
            <div class="support-ticket-comments-section">
                <h4>${mw.msg('supportsystem-sd-ticket-comments') || 'Комментарии'}</h4>
                <div id="support-ticket-comments" class="support-ticket-comments-list"></div>
                
                <div class="support-comment-form">
                    <textarea id="support-comment-text" class="support-textarea" 
                        placeholder="${mw.msg('supportsystem-sd-ticket-comment-placeholder') || 'Введите ваш комментарий...'}"></textarea>
                    <button id="support-comment-submit" class="support-button-primary">
                        ${mw.msg('supportsystem-sd-ticket-comment-submit') || 'Отправить'}
                    </button>
                </div>
            </div>
        `);
            $('#support-ticket-details-back').on('click', function () {
                $('#support-ticket-details').hide();
                $('#support-tickets-list').show();
            });
            $('#support-comment-submit').on('click', function () {
                var ticketId = $('#support-ticket-details').data('ticket-id');
                var comment = $('#support-comment-text').val().trim();
                if (comment) {
                    addComment(ticketId, comment);
                } else {
                    mw.notify(mw.msg('supportsystem-sd-ticket-comment-required') || 'Пожалуйста, введите комментарий', { type: 'error' });
                }
            });
        }
        $('#support-ticket-details-title').text('#' + ticket.id + ': ' + ticket.subject);
        var statusName = ((ticket.status || {}).name || 'Новый');
        var statusClass = 'support-status-' + statusName.toLowerCase().replace(/\s+/g, '-');
        $('#support-ticket-status').text(statusName)
            .removeClass()
            .addClass(statusClass);
        var priorityMapping = {
            1: { class: 'support-priority-red', name: 'Критический' },
            2: { class: 'support-priority-orange', name: 'Высокий' },
            6: { class: 'support-priority-normal', name: 'Нормальный' },
            3: { class: 'support-priority-green', name: 'Низкий' }
        };
        var priority = priorityMapping[6];
        if (ticket.priority && ticket.priority.id) {
            priority = priorityMapping[ticket.priority.id] || priority;
        }
        $('#support-ticket-priority-value').text(priority.name)
            .removeClass()
            .addClass(priority.class);
        $('#support-ticket-description-text').text(ticket.description || '');
        var customFieldsHtml = '';
        if (ticket.custom_fields && ticket.custom_fields.length > 0) {
            var hasVisibleFields = false;
            customFieldsHtml = '<div class="support-custom-fields-section"><h4>Дополнительная информация</h4><dl>';
            ticket.custom_fields.forEach(function (field) {
                if (field.value &&
                    (Array.isArray(field.value) ? field.value.length > 0 : field.value.toString().trim() !== '')) {
                    hasVisibleFields = true;
                    var value = Array.isArray(field.value) ? field.value.join(', ') : field.value;
                    customFieldsHtml += '<dt>' + field.name + ':</dt><dd>' + value + '</dd>';
                }
            });
            customFieldsHtml += '</dl></div>';
            if (hasVisibleFields) {
                $('#support-ticket-custom-fields').html(customFieldsHtml);
            } else {
                $('#support-ticket-custom-fields').empty();
            }
        } else {
            $('#support-ticket-custom-fields').empty();
        }
        var attachmentsHtml = '';
        if (ticket.attachments && ticket.attachments.length > 0) {
            ticket.attachments.forEach(function (attachment) {
                attachmentsHtml += '<div class="support-attachment-item">' +
                    '<a href="' + attachment.content_url + '" target="_blank" class="support-attachment-link">' +
                    '<span class="support-attachment-icon">📎</span> ' +
                    attachment.filename + ' (' + formatFileSize(attachment.filesize) + ')' +
                    '</a>' +
                    '</div>';
            });
            $('.support-attachments-list').html(attachmentsHtml);
            $('#support-ticket-attachments').show();
        } else {
            $('.support-attachments-list').html('<p>Нет прикрепленных файлов</p>');
            $('#support-ticket-attachments').show();
        }
        var commentsHtml = '';
        var hasComments = false;
        if (ticket.journals && ticket.journals.length > 0) {
            ticket.journals.forEach(function (journal) {
                if (journal.notes && journal.notes.trim()) {
                    hasComments = true;
                    commentsHtml += '<div class="support-comment">' +
                        '<div class="support-comment-content">' + journal.notes + '</div>' +
                        '<div class="support-comment-meta">' +
                        '<span class="support-comment-author">' +
                        (journal.user && journal.user.name ? journal.user.name : 'Неизвестный') + '</span> ' +
                        '<span class="support-comment-date">' + formatDate(journal.created_on) + '</span>' +
                        '</div>' +
                        '</div>';
                }
            });
        }
        if (!hasComments) {
            commentsHtml = '<p class="support-no-comments">' +
                (mw.msg('supportsystem-sd-ticket-no-comments') || 'Комментариев пока нет') + '</p>';
        }
        $('#support-ticket-comments').html(commentsHtml);
        $('#support-comment-text').val('');
        initFileUpload();
    }
    /**
     * Показать форму создания заявки
     * @param {string} solution Текст решения
     * @param {string} source Источник решения
     */
    function showTicketForm(solution, source) {
        selectedSolution = solution || '';
        selectedSource = source || '';
        $('#support-ticket-subject').val(getMessage('supportsystem-search-default-subject', 'Help Request'));
        if (solution) {
            $('#support-solution-text').text(solution);
            $('#support-solution-source').text(getMessage('supportsystem-search-source', 'Source: {0}')
                .replace('{0}', getSourceLabel(source)));
            $('#support-solution-display').show();
            var description = '';
            if (source === 'dialog') {
                description = getMessage('supportsystem-dt-dialog-history', 'Dialog History:') + '\n\n';
                dialogHistory.forEach(function (item, index) {
                    description += (index + 1) + '. ';
                    description += getMessage('supportsystem-dt-dialog-item', 'Answer: {0}')
                        .replace('{0}', item.selectedOption) + '\n';
                });
                description += '\n' + getMessage('supportsystem-dt-dialog-solution', 'Found Solution:') + '\n';
                description += solution;
            } else {
                description = getMessage('supportsystem-search-default-description', 'I need help with a problem.') + '\n\n';
                description += getMessage('supportsystem-dt-dialog-solution', 'Found Solution:') + '\n';
                description += solution;
            }
            $('#support-ticket-description').val(description);
        } else {
            $('#support-solution-display').hide();
            $('#support-ticket-description').val(getMessage('supportsystem-search-default-description', 'I need help with a problem.'));
        }
        $('#support-ticket-form').show();
    }

    function submitTicket() {
        var subject = $('#support-ticket-subject').val();
        var description = $('#support-ticket-description').val();
        var priority = $('#support-ticket-priority').val();
        if (!subject) {
            mw.notify(getMessage('supportsystem-sd-ticket-subject-required', 'Требуется указать тему тикета'), { type: 'error' });
            $('#support-ticket-subject').focus();
            return;
        }
        if (!description) {
            mw.notify(getMessage('supportsystem-sd-ticket-description-required', 'Требуется указать описание проблемы'), { type: 'error' });
            $('#support-ticket-description').focus();
            return;
        }
        $('#support-ticket-submit').prop('disabled', true);
        $('#support-ticket-submit').text(getMessage('supportsystem-dt-submitting', 'Отправка...'));
        var api = new mw.Api();
        var url = mw.util.wikiScript('api');
        var formData = new FormData();
        formData.append('action', 'supportticket');
        formData.append('operation', 'create');
        formData.append('subject', subject);
        formData.append('description', description);
        formData.append('priority', priority);
        formData.append('format', 'json');
        formData.append('token', mw.user.tokens.get('csrfToken'));
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        form.style.display = 'none';
        document.body.appendChild(form);
        for (var pair of formData.entries()) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = pair[0];
            input.value = pair[1];
            form.appendChild(input);
        }
        var iframe = document.createElement('iframe');
        iframe.name = 'ticket_frame_' + Date.now();
        iframe.style.display = 'none';
        document.body.appendChild(iframe);
        form.target = iframe.name;
        iframe.onload = function () {
            try {
                var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                var response = JSON.parse(iframeDoc.body.innerText);
                if (response && response.ticket) {
                    if (selectedSolution) { attachSolution(response.ticket.id); }
                    else { showTicketSuccess(response.ticket.id); }
                } else {
                    console.error('Ошибка создания тикета:', response);
                    mw.notify(getMessage('supportsystem-search-ticket-error', 'Ошибка создания тикета'),
                        { type: 'error' });
                    $('#support-ticket-submit').prop('disabled', false);
                    $('#support-ticket-submit').text(getMessage('supportsystem-dt-submit', 'Отправить'));
                }
            } catch (e) {
                console.error('Ошибка обработки ответа:', e);
                mw.notify(getMessage('supportsystem-search-ticket-error', 'Ошибка создания тикета'),
                    { type: 'error' });
                $('#support-ticket-submit').prop('disabled', false);
                $('#support-ticket-submit').text(getMessage('supportsystem-dt-submit', 'Отправить'));
            }
            setTimeout(function () {
                document.body.removeChild(form);
                document.body.removeChild(iframe);
            }, 100);
        };
        iframe.onerror = function () {
            mw.notify(getMessage('supportsystem-search-ticket-error', 'Ошибка создания тикета'),
                { type: 'error' });
            $('#support-ticket-submit').prop('disabled', false);
            $('#support-ticket-submit').text(getMessage('supportsystem-dt-submit', 'Отправить'));
            setTimeout(function () {
                document.body.removeChild(form);
                document.body.removeChild(iframe);
            }, 100);
        };
        form.submit();
    }
    /**
     * Прикрепление решения к заявке
     * @param {number} ticketId ID заявки
     */
    function attachSolution(ticketId) {
        var api = new mw.Api();
        console.log('Прикрепление решения к тикету #' + ticketId);
        api.post({
            action: 'supportticket',
            operation: 'solution',
            ticket_id: ticketId,
            solution: selectedSolution,
            source: selectedSource
        }).done(function (data) {
            console.log('Ответ API на прикрепление решения:', data);
            if (data.result === 'success') {
                showTicketSuccess(ticketId);
            } else {
                console.warn('Прикрепление решения могло быть неудачным:', data);
            }
        }).fail(function (xhr, status, error) {
            console.error('Ошибка прикрепления решения:', {
                status: status,
                error: error,
                response: xhr.responseText || 'Нет текста ответа'
            });
        });
    }
    /**
     * Функция добавления комментария к тикету через curl
     * @param {number} ticketId ID тикета
     * @param {string} comment Текст комментария
     */
    function addComment(ticketId, comment) {
        $('#support-comment-submit').prop('disabled', true);
        var api = new mw.Api();
        api.post({
            action: 'supportticket',
            operation: 'comment',
            ticket_id: ticketId,
            comment: comment
        }).done(function (data) {
            if (data.result === 'success') {
                mw.notify(mw.msg('supportsystem-sd-ticket-comment-success') || 'Комментарий успешно добавлен',
                    { type: 'success' });
                $('#support-comment-text').val('');
                viewTicket(ticketId);
            } else {
                mw.notify(mw.msg('supportsystem-sd-ticket-comment-error') || 'Ошибка добавления комментария',
                    { type: 'error' });
            }
        }).fail(function () {
            mw.notify(mw.msg('supportsystem-sd-ticket-comment-error') || 'Ошибка добавления комментария',
                { type: 'error' });
        }).always(function () {
            $('#support-comment-submit').prop('disabled', false);
        });
    }
    /**
     * Функция для инициализации загрузки файлов
     */
    function initFileUpload() {
        if ($('#support-ticket-details').length && !$('#support-file-upload-form').length) {
            var uploadFormHtml = `
            <div class="support-file-upload-section">
                <h4>${mw.msg('supportsystem-attachment-upload') || 'Загрузить файл'}</h4>
                <form id="support-file-upload-form" enctype="multipart/form-data">
                    <div class="support-form-group">
                        <input type="file" id="support-file-input" class="support-file-input" name="file">
                    </div>
                    <div class="support-form-group">
                        <textarea id="support-file-comment" class="support-textarea" rows="2"
                            placeholder="${mw.msg('supportsystem-sd-ticket-comment-placeholder') || 'Комментарий к файлу...'}"></textarea>
                    </div>
                    <button type="submit" id="support-file-upload-button" class="support-button-primary">
                        ${mw.msg('supportsystem-attachment-upload') || 'Загрузить'}
                    </button>
                </form>
                <div id="support-file-upload-progress" class="support-upload-progress" style="display: none;">
                    <div class="support-spinner"></div>
                    <p>${mw.msg('supportsystem-dt-submitting') || 'Загрузка...'}</p>
                </div>
            </div>
        `;
            $('.support-ticket-comments-section').before(uploadFormHtml);

            $('#support-file-upload-form').on('submit', function (e) {
                e.preventDefault();
                uploadFileToTicket();
            });
        }
    }

    function uploadFileToTicket() {
        var ticketId = $('#support-ticket-details').data('ticket-id');
        var fileInput = $('#support-file-input')[0];
        var comment = $('#support-file-comment').val() || 'Файл прикреплен';
        if (!fileInput.files || fileInput.files.length === 0) {
            mw.notify('Пожалуйста, выберите файл для загрузки', { type: 'error' });
            return;
        }
        $('#support-file-upload-form').hide();
        $('#support-file-upload-progress').show();
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = mw.util.wikiScript('api');
        form.enctype = 'multipart/form-data';
        form.style.display = 'none';
        var params = {
            'action': 'supportattachment',
            'operation': 'upload',
            'ticket_id': ticketId,
            'comment': comment,
            'format': 'json',
            'token': mw.user.tokens.get('csrfToken')
        };
        for (var key in params) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = params[key];
            form.appendChild(input);
        }
        var fileInputClone = fileInput.cloneNode(true);
        fileInputClone.name = 'file';
        form.appendChild(fileInputClone);
        document.body.appendChild(form);
        var iframe = document.createElement('iframe');
        iframe.name = 'upload_frame_' + Date.now();
        iframe.style.display = 'none';
        document.body.appendChild(iframe);
        form.target = iframe.name;
        iframe.onload = function () {
            try {
                var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                var response = JSON.parse(iframeDoc.body.innerText);
                if (response && response.result === 'success') {
                    mw.notify(mw.msg('supportsystem-attachment-success') || 'Файл успешно загружен', { type: 'success' });
                    viewTicket(ticketId);
                } else {
                    var errorMsg = response.error ? response.error.info : 'Неизвестная ошибка';
                    mw.notify(mw.msg('supportsystem-attachment-error') || 'Ошибка загрузки файла: ' + errorMsg, { type: 'error' });
                    $('#support-file-upload-progress').hide();
                    $('#support-file-upload-form').show();
                }
            } catch (e) {
                mw.notify(mw.msg('supportsystem-attachment-error') || 'Ошибка обработки ответа сервера', { type: 'error' });
                $('#support-file-upload-progress').hide();
                $('#support-file-upload-form').show();
            }
            setTimeout(function () {
                document.body.removeChild(form);
                document.body.removeChild(iframe);
            }, 100);
        };
        iframe.onerror = function () {
            mw.notify(mw.msg('supportsystem-attachment-error') || 'Ошибка при загрузке файла', { type: 'error' });
            $('#support-file-upload-progress').hide();
            $('#support-file-upload-form').show();
            setTimeout(function () {
                document.body.removeChild(form);
                document.body.removeChild(iframe);
            }, 100);
        };
        form.submit();
    }

    /**
     * Форматирование размера файла
     * @param {number} bytes Размер в байтах
     * @return {string} Отформатированный размер
     */
    function formatFileSize(bytes) {
        if (bytes < 1024) {
            return bytes + ' B';
        } else if (bytes < 1024 * 1024) {
            return (bytes / 1024).toFixed(1) + ' KB';
        } else if (bytes < 1024 * 1024 * 1024) {
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        } else {
            return (bytes / (1024 * 1024 * 1024)).toFixed(1) + ' GB';
        }
    }

    /**
     * Показать сообщение об успешном создании заявки
     * @param {number} ticketId ID заявки
     */
    function showTicketSuccess(ticketId) {
        $('#support-ticket-form').hide();
        $('#support-ticket-submit').prop('disabled', false);
        $('#support-ticket-submit').text(mw.msg('supportsystem-dt-submit') || 'Submit');
        mw.notify(
            (messages.ticket_created || 'Ticket #{0} has been successfully created!').replace('{0}', ticketId),
            { type: 'success', autoHide: false }
        );
        loadTickets();
        showPanel('tickets');
    }

    /**
     * Форматирование даты
     * @param {string} dateStr Строка с датой
     * @return {string} Отформатированная дата
     */
    function formatDate(dateStr) {
        if (!dateStr) { return ''; }
        try {
            var date = new Date(dateStr);
            return date.toLocaleString();
        } catch (e) { return dateStr; }
    }

    /**
     * Вспомогательная функция для получения текста сообщения
     * @param {string} key Ключ сообщения
     * @param {string} defaultValue Значение по умолчанию
     * @return {string} Текст сообщения
     */
    function getMessage(key, defaultValue) {
        if (typeof mw !== 'undefined' && mw.msg) {
            var message = mw.msg(key);
            if (message.indexOf('⧼') === 0 && message.indexOf('⧽') === message.length - 1) {
                return defaultValue;
            }
            return message;
        }
        return defaultValue;
    }
    $(document).ajaxError(function (event, jqXHR) {
        if (jqXHR.status === 0) {
            mw.notify("Проблема с подключением к серверу. Проверьте соединение и попробуйте снова.",
                { type: "error", autoHide: false });
        } else if (jqXHR.status >= 500) {
            mw.notify("Ошибка сервера (HTTP " + jqXHR.status + ").",
                { type: "error", autoHide: false });
        }
    });
    $(init);
}());
