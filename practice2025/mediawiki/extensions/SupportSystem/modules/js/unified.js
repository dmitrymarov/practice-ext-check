
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
        $('#support-wiki-search-button').on('click', function () {
            $('#support-search-input').val(currentSolution);
            showPanel('search');
            searchSolutions(currentSolution);
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
        $('#support-solution-container').show();
        currentSolution = text;
        searchState.graphSearchDone = true;
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

        // Добавляем примеры запросов
        var searchExamples = [
            "Не работает docker что делать?",
            "Как настроить MediaWiki",
            "Проблемы с OpenSearch",
            "Docker установка"
        ];

        var examplesHtml = '<div class="support-search-examples">' +
            '<p>Примеры запросов:</p>' +
            '<ul class="support-example-list">';

        searchExamples.forEach(function (example) {
            examplesHtml += '<li><a href="#" class="support-example-query">' + example + '</a></li>';
        });

        examplesHtml += '</ul></div>';

        // Добавляем примеры запросов под полем поиска
        $('.support-search-box').after(examplesHtml);

        // Обработчик клика по примеру запроса
        $('.support-example-query').on('click', function (e) {
            e.preventDefault();
            var query = $(this).text();
            $('#support-search-input').val(query);
            searchSolutions(query);
        });

    }

    /**
     * Поиск решений через API
     * @param {string} query Поисковый запрос
     */
    function searchSolutions(query) {
        $('#support-search-results').html(
            '<div class="support-loading">' +
            '<div class="support-spinner"></div>' +
            '<p>' + (mw.msg('supportsystem-search-loading') || 'Поиск...') + '</p>' +
            '</div>'
        );

        console.log('Выполняется поиск:', query);

        // Используем обработку ошибок и таймауты для более надежного поиска
        var api = new mw.Api({
            ajax: {
                timeout: 30000, // 30 секунд таймаут
                headers: {
                    'Cache-Control': 'no-cache'
                }
            }
        });

        api.get({
            action: 'unifiedsearch',
            query: query,
            sources: 'opensearch|mediawiki',
            limit: 10,
            format: 'json'
        })
            .done(function (data) {
                console.log('Результаты поиска:', data);

                if (!data || !data.results) {
                    console.error('Неверный формат ответа:', data);
                    showSearchError('Получен неверный формат ответа от сервера');
                    return;
                }

                var results = [];

                // Обработка результатов из OpenSearch
                if (data.results.opensearch && Array.isArray(data.results.opensearch)) {
                    data.results.opensearch.forEach(function (item) {
                        if (item) {
                            item.source = 'opensearch';
                            results.push(item);
                        }
                    });
                }

                // Обработка результатов из MediaWiki
                if (data.results.mediawiki && Array.isArray(data.results.mediawiki)) {
                    data.results.mediawiki.forEach(function (item) {
                        if (item) {
                            item.source = 'mediawiki';
                            results.push(item);
                        }
                    });
                }

                // Сортировка результатов по релевантности
                results.sort(function (a, b) {
                    var scoreA = typeof a.score === 'number' ? a.score : 0;
                    var scoreB = typeof b.score === 'number' ? b.score : 0;
                    return scoreB - scoreA;
                });

                if (results.length > 0) {
                    displaySearchResults(results, query);
                } else {
                    $('#support-search-results').html(
                        '<div class="support-no-results">' +
                        '<p>' + (mw.msg('supportsystem-search-noresults') || 'Результатов не найдено. Попробуйте изменить запрос.') + '</p>' +
                        '<p><button id="support-try-different-search" class="support-button-secondary">Попробовать другой запрос</button></p>' +
                        '</div>'
                    );

                    $('#support-try-different-search').on('click', function () {
                        $('#support-search-input').val('').focus();
                    });
                }
            })
            .fail(function (error, details) {
                console.error('API error:', error, details);

                var errorMessage = 'Произошла ошибка при поиске. Пожалуйста, попробуйте позже.';

                // Пытаемся извлечь детали ошибки
                if (error && error.error && error.error.info) {
                    errorMessage = 'Ошибка поиска: ' + error.error.info;
                } else if (error && error.textStatus) {
                    if (error.textStatus === 'timeout') {
                        errorMessage = 'Время ожидания ответа от сервера истекло. Пожалуйста, попробуйте позже.';
                    } else {
                        errorMessage = 'Ошибка соединения: ' + error.textStatus;
                    }
                }

                showSearchError(errorMessage);
            });
    }

    /**
 * Отображает сообщение об ошибке поиска
 * @param {string} message Сообщение об ошибке
 */
    function showSearchError(message) {
        $('#support-search-results').html(
            '<div class="support-error">' +
            '<p>' + (mw.msg('supportsystem-search-error') || 'Произошла ошибка при поиске. Пожалуйста, попробуйте позже.') + '</p>' +
            '<p class="support-error-details">Детали ошибки: <span>' + message + '</span></p>' +
            '<p><button id="support-try-different-search" class="support-button-primary">Попробовать снова</button></p>' +
            '</div>'
        );

        $('#support-try-different-search').on('click', function () {
            var query = $('#support-search-input').val().trim();
            if (query) {
                searchSolutions(query);
            } else {
                $('#support-search-input').focus();
            }
        });
    }

    /**
     * Отображение результатов поиска
     * @param {Array} results Результаты поиска
     * @param {string} query Поисковый запрос
     */
    function displaySearchResults(results, query) {
        var resultsHtml = '';
        resultsHtml += '<h3>' + (mw.msg('supportsystem-search-results-count', results.length) || 'Найдено решений: ' + results.length) + '</h3>';
        resultsHtml += '<p>По запросу: <strong>' + query + '</strong></p>';

        results.forEach(function (result) {
            // Безопасно получаем значения
            var source = result.source || 'unknown';
            var sourceLabel = getSourceLabel(source);
            var badgeClass = 'support-badge-' + source;
            var resultTitle = result.title || '';
            var resultContent = '';

            // Обрабатываем содержимое для отображения
            if (result.highlight) {
                resultContent = result.highlight;
            } else if (result.content) {
                resultContent = result.content;
                if (resultContent.length > 300) {
                    resultContent = resultContent.substring(0, 300) + '...';
                }
            }

            // Выделяем слова запроса в результатах, если они не выделены
            if (resultContent && !/<strong>/i.test(resultContent)) {
                // Разбиваем запрос на термины
                var terms = query.split(/\s+/).filter(function (term) {
                    // Игнорируем короткие слова и стоп-слова
                    return term.length > 2 && !/^(как|что|где|кто|почему|зачем|когда|не|и|в|на|с|по|к|у|о|от|для|до|при)$/i.test(term);
                });

                // Выделяем каждый термин в контенте
                terms.forEach(function (term) {
                    // Создаем регулярное выражение для поиска слова с учетом регистра и словоформ
                    var regex = new RegExp('(' + escapeRegExp(term) + '[а-яё]*)', 'gi');
                    resultContent = resultContent.replace(regex, '<strong>$1</strong>');
                });
            }

            // Проверяем, есть ли у результата URL
            var hasUrl = result.url && result.url.length > 0;

            // Форматирование результата поиска
            resultsHtml +=
                '<div class="support-result-card">' +
                '<div class="support-result-header">' +
                '<h4>' + resultTitle + '</h4>' +
                '<div class="support-result-meta">' +
                '<span class="support-badge ' + badgeClass + '">' + sourceLabel + '</span>' +
                (result.score ? '<span class="support-score-badge">Релевантность: ' + result.score.toFixed(2) + '</span>' : '') +
                '</div>' +
                '</div>' +
                '<div class="support-result-body">' +
                '<div class="support-result-content">' + resultContent + '</div>' +
                (result.tags && result.tags.length ? '<div class="support-result-tags">' + formatTags(result.tags) + '</div>' : '') +
                '<div class="support-result-actions">' +
                (hasUrl ? '<a href="' + result.url + '" target="_blank" class="support-button-secondary">' +
                    (mw.msg('supportsystem-search-source-link') || 'Перейти к источнику') + '</a>' : '') +
                '<button class="support-create-ticket-btn support-button-primary" data-solution="' + escapeAttr(result.content || '') + '" data-source="' + source + '">' +
                (mw.msg('supportsystem-search-create-ticket') || 'Создать заявку с этим решением') + '</button>' +
                '</div>' +
                '</div>' +
                '</div>';
        });

        // Добавляем кнопку для нового поиска
        resultsHtml += '<div class="support-search-actions">' +
            '<button id="support-new-search" class="support-button-secondary">Новый поиск</button>' +
            '</div>';

        $('#support-search-results').html(resultsHtml);

        // Привязываем обработчики событий
        $('.support-create-ticket-btn').on('click', function () {
            var solution = $(this).data('solution');
            var source = $(this).data('source');
            showTicketForm(solution, source);
        });

        $('#support-new-search').on('click', function () {
            $('#support-search-input').val('').focus();
        });
    }

    /**
 * Форматирует теги для отображения
 * @param {Array|string} tags Теги
 * @return {string} HTML с тегами
 */
    function formatTags(tags) {
        if (!tags) return '';

        // Преобразуем строку в массив, если это строка
        if (typeof tags === 'string') {
            tags = tags.split(/,\s*/);
        }

        var html = '';
        if (Array.isArray(tags)) {
            tags.forEach(function (tag) {
                html += '<span class="support-tag">' + tag + '</span>';
            });
        }
        return html;
    }

    /**
     * Экранирует строку для безопасного использования в атрибутах data-
     * @param {string} str Строка для экранирования
     * @return {string} Экранированная строка
     */
    function escapeAttr(str) {
        if (!str) return '';
        return str.replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /**
     * Экранирование символов регулярных выражений
     * @param {string} str Строка для экранирования
     * @return {string} Экранированная строка
     */
    function escapeRegExp(str) {
        return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function isNaturalLanguageQuery(query) {
        // Проверяем наличие вопросительных слов или знаков
        var questionWords = ['что', 'как', 'почему', 'где', 'когда', 'кто', 'зачем'];
        var hasQuestionMark = query.indexOf('?') !== -1;

        for (var i = 0; i < questionWords.length; i++) {
            if (query.toLowerCase().indexOf(questionWords[i] + ' ') !== -1) {
                return true;
            }
        }

        // Проверяем фразы типа "не работает X"
        if (/не\s+работает/i.test(query)) {
            return true;
        }

        return hasQuestionMark;
    }

    /**
     * Добавляет наводящий текст, если в исходном запросе нет результатов
     * @param {string} query Исходный запрос
     * @return {string} Запрос с добавленными ключевыми словами
     */
    function addHelpfulKeywords(query) {
        var originalQuery = query;

        // Удаляем вопросительные знаки
        query = query.replace(/\?/g, '');

        // Если в запросе есть слово "docker"
        if (/docker/i.test(query)) {
            if (!/ошибка|проблема|установка|настройка/i.test(query)) {
                return query + ' проблема ошибка настройка установка';
            }
        }

        // Если в запросе есть слова "не работает"
        if (/не\s+работает/i.test(query)) {
            var match = query.match(/не\s+работает\s+([^\s,.?!]+)/i);
            if (match && match[1]) {
                var subject = match[1];
                return query + ' ' + subject + ' проблема ошибка решение';
            }
            return query + ' проблема ошибка решение';
        }

        // Если запрос - вопрос
        if (isNaturalLanguageQuery(originalQuery)) {
            return query + ' инструкция руководство помощь';
        }

        return query;
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
        if (ticket.priority && ticket.priority.id) { priority = priorityMapping[ticket.priority.id] || priority; }
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
            if (hasVisibleFields) { $('#support-ticket-custom-fields').html(customFieldsHtml); } 
            else { $('#support-ticket-custom-fields').empty(); }
        } else { $('#support-ticket-custom-fields').empty(); }
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
     * Показывает форму создания тикета
     * @param {string} solution Текст решения
     * @param {string} source Источник решения
     */
    function showTicketForm(solution, source) {
        selectedSolution = solution || '';
        selectedSource = source || '';
        if ($('#support-ticket-form').length === 0) {
            var formHtml = `
        <div id="support-ticket-form" class="support-form-overlay" style="display: none;">
            <div class="support-form-container">
                <div class="support-form-header">
                    <h3>Создание тикета</h3>
                    <button id="support-ticket-close" class="support-close-button">&times;</button>
                </div>
                <div id="support-solution-display" class="support-solution-display" style="display: none;">
                    <h4>Найденное решение</h4>
                    <p id="support-solution-text"></p>
                    <p id="support-solution-source" class="support-source"></p>
                </div>
                <form id="support-ticket-form-element" enctype="multipart/form-data">
                    <div class="support-form-group">
                        <label for="support-ticket-subject">Тема тикета</label>
                        <input type="text" id="support-ticket-subject" name="subject" class="support-input" required>
                    </div>
                    <div class="support-form-group">
                        <label for="support-ticket-description">Описание проблемы</label>
                        <textarea id="support-ticket-description" name="description" class="support-textarea" rows="5" required></textarea>
                    </div>
                    <div class="support-form-group">
                        <label for="support-ticket-priority">Приоритет</label>
                        <select id="support-ticket-priority" name="priority" class="support-select">
                            <option value="green">Низкий</option>
                            <option value="yellow" selected>Нормальный</option>
                            <option value="orange">Высокий</option>
                            <option value="red">Критический</option>
                        </select>
                    </div>
                    <!-- Добавляем поле для загрузки файлов -->
                    <div class="support-form-group">
                        <label for="support-ticket-files">Прикрепить файлы</label>
                        <input type="file" id="support-ticket-files" name="ticket_files[]" class="support-file-input" multiple>
                        <div id="support-file-list" class="support-file-list"></div>
                        <small class="support-file-help">Максимальный размер файла: 10 МБ</small>
                    </div>
                    <div class="support-form-actions">
                        <button type="button" id="support-ticket-cancel" class="support-button-secondary">
                            Отмена
                        </button>
                        <button type="submit" id="support-ticket-submit" class="support-button-primary">
                            Отправить
                        </button>
                    </div>
                </form>
            </div>
        </div>
        `;
            $('body').append(formHtml);
            $('#support-ticket-close, #support-ticket-cancel').on('click', function () {
                $('#support-ticket-form').hide();
            });
            $('#support-ticket-form-element').on('submit', function (e) {
                e.preventDefault();
                submitTicket();
            });
            $('#support-ticket-files').on('change', function () {
                var fileList = $('#support-file-list');
                fileList.empty();
                if (this.files && this.files.length > 0) {
                    var fileNames = '<p><strong>Выбранные файлы:</strong></p><ul>';
                    var hasLargeFiles = false;
                    for (var i = 0; i < this.files.length; i++) {
                        var file = this.files[i];
                        var fileSize = formatFileSize(file.size);
                        var className = '';
                        if (file.size > 10 * 1024 * 1024) {
                            className = 'support-file-too-large';
                            hasLargeFiles = true;
                        }
                        fileNames += '<li class="' + className + '">' + file.name + ' (' + fileSize + ')</li>';
                    }
                    fileNames += '</ul>';
                    if (hasLargeFiles) {
                        fileNames += '<p class="support-file-error">Некоторые файлы превышают максимальный размер 10 МБ и не будут загружены.</p>';
                    }
                    fileList.html(fileNames);
                }
            });
        }
        $('#support-ticket-subject').val('Запрос в поддержку');
        if (solution) {
            $('#support-solution-text').text(solution);
            $('#support-solution-source').text('Источник: ' + (source || 'неизвестный'));
            $('#support-solution-display').show();
            var description = '';
            if (source === 'dialog') {
                description = 'История диалога:\n\n';
                if (typeof dialogHistory !== 'undefined' && dialogHistory.length > 0) {
                    dialogHistory.forEach(function (item, index) {
                        description += (index + 1) + '. Ответ: ' + item.selectedOption + '\n';
                    });
                }
                description += '\nНайденное решение:\n' + solution;
            } else {
                description = 'У меня возникла проблема, требующая помощи.\n\n';
                description += 'Найденное решение:\n' + solution;
            }
            $('#support-ticket-description').val(description);
        } else {
            $('#support-solution-display').hide();
            $('#support-ticket-description').val('У меня возникла проблема, требующая помощи.');
        }
        $('#support-ticket-files').val('');
        $('#support-file-list').empty();
        $('#support-ticket-submit').prop('disabled', false);
        $('#support-ticket-form').show();
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
                mw.notify('Проверяем статус добавления комментария...', { type: 'info' });
                setTimeout(function () {
                    viewTicket(ticketId);
                }, 1000);
            }
        }).fail(function () {
            mw.notify('Проверяем, был ли добавлен комментарий...', { type: 'info' });
            setTimeout(function () {
                viewTicket(ticketId);
                $('#support-comment-submit').prop('disabled', false);
            }, 1000);
        });
    }

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
            $('#support-file-input').on('change', function () {
                if (this.files && this.files.length > 0) {
                    var file = this.files[0];
                    var fileSize = formatFileSize(file.size);
                    if (file.size > 10 * 1024 * 1024) {
                        mw.notify('Файл ' + file.name + ' превышает максимальный размер 10 МБ', { type: 'error' });
                        $(this).val('');
                    } else {
                        $('#support-file-upload-button').text('Загрузить ' + file.name + ' (' + fileSize + ')');
                    }
                } else {
                    $('#support-file-upload-button').text(mw.msg('supportsystem-attachment-upload') || 'Загрузить');
                }
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
        var file = fileInput.files[0];
        if (file.size > 10 * 1024 * 1024) {
            mw.notify('Файл превышает максимальный размер 10 МБ', { type: 'error' });
            return;
        }
        $('#support-file-upload-form').hide();
        $('#support-file-upload-progress').show();
        var formData = new FormData();
        formData.append('action', 'supportticket');
        formData.append('format', 'json');
        formData.append('operation', 'attachment');
        formData.append('ticket_id', ticketId);
        formData.append('comment', comment);
        formData.append('token', mw.user.tokens.get('csrfToken'));
        formData.append('file', file);
        fetch(mw.util.wikiScript('api'), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data && data.result === 'success') {
                    mw.notify('Файл успешно загружен', { type: 'success' });
                    viewTicket(ticketId);
                } else {
                    var errorMsg = data.error ? data.error.info : 'Неизвестная ошибка';
                    mw.notify('Ошибка загрузки файла: ' + errorMsg, { type: 'error' });
                    $('#support-file-upload-progress').hide();
                    $('#support-file-upload-form').show();
                }
            })
            .catch(function (error) {
                console.error('Ошибка при загрузке файла:', error);
                mw.notify('Ошибка при загрузке файла', { type: 'error' });
                $('#support-file-upload-progress').hide();
                $('#support-file-upload-form').show();
            });
    }

    /**
     * Format file size for display
     * @param {number} bytes File size in bytes
     * @return {string} Formatted file size
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
    function displayNode(node) {
        const chatContainer = document.getElementById('support-chat-container');
        const nodeElement = document.createElement('div');
        nodeElement.className = 'support-node support-node-' + node.type;
        const contentElement = document.createElement('div');
        contentElement.className = 'support-node-content';
        contentElement.textContent = node.content;
        nodeElement.appendChild(contentElement);
        if (node.type === 'solution' && node.article_link) {
            const articleLinkContainer = document.createElement('div');
            articleLinkContainer.className = 'support-article-links';
            const mainLink = document.createElement('a');
            mainLink.href = node.article_url || '/wiki/' + node.article_link.replace(/ /g, '_');
            mainLink.className = 'support-article-link-button';
            mainLink.textContent = mw.msg('supportsystem-read-full-article');
            mainLink.target = '_blank';
            articleLinkContainer.appendChild(mainLink);
            if (node.related_articles && node.related_articles.length > 0) {
                const relatedHeader = document.createElement('div');
                relatedHeader.className = 'support-related-header';
                relatedHeader.textContent = mw.msg('supportsystem-related-articles');
                articleLinkContainer.appendChild(relatedHeader);
                const relatedList = document.createElement('ul');
                relatedList.className = 'support-related-list';
                node.related_articles.forEach(function (article) {
                    const li = document.createElement('li');
                    const link = document.createElement('a');
                    link.href = '/wiki/' + article.replace(/ /g, '_');
                    link.textContent = article.replace(/_/g, ' ');
                    link.target = '_blank';
                    li.appendChild(link);
                    relatedList.appendChild(li);
                });
                articleLinkContainer.appendChild(relatedList);
            }

            nodeElement.appendChild(articleLinkContainer);
        }
        if (node.children && node.children.length > 0) {
            const optionsContainer = document.createElement('div');
            optionsContainer.className = 'support-options';

            node.children.forEach(function (child) {
                const button = document.createElement('button');
                button.className = 'support-option-button';
                button.textContent = child.label;
                button.onclick = function () {
                    loadNode(child.id);
                };
                optionsContainer.appendChild(button);
            });
            nodeElement.appendChild(optionsContainer);
        }
        chatContainer.appendChild(nodeElement);
    }
    /**
     * Экранирование символов регулярных выражений
     * @param {string} str Строка для экранирования
     * @return {string} Экранированная строка
     */
    function escapeRegExp(str) {
        return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function submitTicket() {
        var subject = $('#support-ticket-subject').val();
        var description = $('#support-ticket-description').val();
        var priority = $('#support-ticket-priority').val();
        if (!subject) {
            mw.notify('Необходимо указать тему тикета', { type: 'error' });
            $('#support-ticket-subject').focus();
            return;
        }
        if (!description) {
            mw.notify('Необходимо указать описание проблемы', { type: 'error' });
            $('#support-ticket-description').focus();
            return;
        }
        var fileInput = document.getElementById('support-ticket-files');
        if (fileInput && fileInput.files.length > 0) {
            var hasLargeFiles = false;
            for (var i = 0; i < fileInput.files.length; i++) {
                if (fileInput.files[i].size > 10 * 1024 * 1024) {
                    hasLargeFiles = true;
                    mw.notify('Файл ' + fileInput.files[i].name + ' превышает максимальный размер 10 МБ', { type: 'error' });
                }
            }
            if (hasLargeFiles) {
                return;
            }
        }
        $('#support-ticket-submit').prop('disabled', true);
        $('#support-ticket-submit').text('Отправка...');
        var formData = new FormData();
        formData.append('action', 'supportticket');
        formData.append('format', 'json');
        formData.append('operation', 'create');
        formData.append('token', mw.user.tokens.get('csrfToken'));
        formData.append('subject', subject);
        formData.append('description', description);
        formData.append('priority', priority);
        if (selectedSolution) {
            formData.append('solution', selectedSolution);
            formData.append('source', selectedSource || 'unknown');
        }
        if (fileInput && fileInput.files.length > 0) {
            for (var i = 0; i < fileInput.files.length; i++) {
                formData.append('ticket_files[]', fileInput.files[i]);
            }
            $('#support-file-list').html(
                '<div class="support-upload-progress">' +
                '<div class="support-spinner"></div>' +
                '<p>Загрузка файлов...</p>' +
                '</div>'
            );
        }
        fetch(mw.util.wikiScript('api'), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data && data.ticket) {
                    $('#support-ticket-form').hide();
                    mw.notify(
                        'Тикет #' + data.ticket.id + ' успешно создан!',
                        { type: 'success' }
                    );
                    loadTickets();
                    showPanel('tickets');
                } else if (data && data.error) {
                    mw.notify(
                        'Ошибка создания тикета: ' + (data.error.info || 'Неизвестная ошибка'),
                        { type: 'error' }
                    );
                } else {
                    $('#support-ticket-form').hide();
                    mw.notify(
                        'Проверяем, создан ли тикет...',
                        { type: 'info' }
                    );
                    loadTickets();
                    showPanel('tickets');
                }
            })
            .catch(function (error) {
                console.error('Ошибка при создании тикета:', error);
                mw.notify(
                    'Ошибка отправки формы. Проверяем, создан ли тикет...',
                    { type: 'error' }
                );
                $('#support-ticket-form').hide();
                loadTickets();
                showPanel('tickets');
            })
            .finally(function () {
                $('#support-ticket-submit').prop('disabled', false).text('Отправить');
            });
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
    $(init);
}());
