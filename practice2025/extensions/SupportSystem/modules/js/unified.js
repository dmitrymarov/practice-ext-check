/**
 * JavaScript for the unified support system interface
 */
(function () {
    'use strict';

    // Общие переменные для всех вкладок
    var config = mw.config.get('supportsystemConfig') || {};
    var messages = config.messages || {};
    var currentSolution = '';
    var selectedSolution = '';
    var selectedSource = '';
    var dialogHistory = [];
    var currentNodeId = 'root';

    /**
     * Инициализация интерфейса
     */
    function init() {
        // Обработчики вкладок
        $('.support-tab').on('click', function () {
            var panelId = $(this).data('panel');
            showPanel(panelId);
        });

        // Инициализация диалогового модуля
        initDialogTab();

        // Инициализация поискового модуля
        initSearchTab();

        // Инициализация модуля заявок
        initTicketsTab();

        // Общий обработчик формы создания заявки
        $('#support-ticket-form-element').on('submit', function (e) {
            e.preventDefault();
            submitTicket();
        });

        // Закрытие формы заявки
        $('#support-ticket-close, #support-ticket-cancel').on('click', function () {
            $('#support-ticket-form').hide();
        });

        // Загрузка заявок при первом открытии
        loadTickets();
    }

    /**
     * Показать определенную панель/вкладку
     * @param {string} panelId Идентификатор панели
     */
    function showPanel(panelId) {
        // Деактивировать все вкладки и скрыть все панели
        $('.support-tab').removeClass('active');
        $('.support-panel').removeClass('active');

        // Активировать выбранную вкладку и показать соответствующую панель
        $('#support-tab-' + panelId).addClass('active');
        $('#support-panel-' + panelId).addClass('active');
    }

    /**
     * Инициализация диалогового модуля
     */
    function initDialogTab() {
        // Обработчик кнопки запуска диалога
        $('#support-start-button').on('click', function () {
            $(this).remove();
            $('.support-welcome-message').remove();
            loadNode('root');
        });

        // Обработчик кнопки перезапуска диалога
        $('#support-restart-button').on('click', function () {
            $('#support-chat-container').empty();
            $('#support-solution-container').hide();
            $('#support-options-container').show();
            dialogHistory = [];
            loadNode('root');
        });

        // Обработчик кнопки создания заявки из решения
        $('#support-create-ticket-button').on('click', function () {
            showTicketForm(currentSolution, 'dialog');
        });

        // Обработчик кнопки поиска AI
        $('#support-ai-search-button').on('click', function () {
            searchAI(currentSolution);
        });

        // Обработчик кнопки поиска в Wiki
        $('#support-wiki-search-button').on('click', function () {
            $('#support-search-input').val(currentSolution);
            showPanel('search');
            searchSolutions(currentSolution);
        });

        // Обработчик кнопки "Это помогло" в AI результатах
        $('#support-ai-accept-button').on('click', function () {
            $('#support-ai-container').hide();
            $('#support-solution-container').show();
        });

        // Обработчик кнопки создания заявки из AI решения
        $('#support-ai-ticket-button').on('click', function () {
            var aiText = $('#support-ai-text').text();
            showTicketForm(aiText, 'ai');
        });

        // Обработчик кнопки возврата из AI решения
        $('#support-ai-back-button').on('click', function () {
            $('#support-ai-container').hide();
            $('#support-solution-container').show();
        });

        // Динамическое добавление опций выбора
        $(document).on('click', '.support-option-btn', function () {
            var childId = $(this).data('child-id');
            var optionText = $(this).text();

            // Добавить сообщение пользователя в чат
            addMessage(optionText, 'user');

            // Очистить опции
            $('#support-options-container').empty();

            // Сохранить выбранную опцию в истории
            dialogHistory.push({
                nodeId: currentNodeId,
                selectedOption: optionText,
                selectedNodeId: childId
            });

            // Загрузить следующий узел
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

            // Добавить системное сообщение в чат
            addMessage(data.supportnode.content, 'system');

            // Обработка узла в зависимости от типа
            if (data.supportnode.type === 'question') {
                // Показать опции для вопроса
                showOptions(data.supportnode.children);
            } else {
                // Показать решение
                currentSolution = data.supportnode.content;
                showSolution(data.supportnode.content);
            }

            // Прокрутить чат вниз
            scrollChatToBottom();
        }).fail(function (error) {
            mw.notify(messages.error_loading_node || 'Error loading node', { type: 'error' });
            console.error('Error loading node:', error);
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

    var searchState = {
        graphSearchDone: false,
        wikiSearchDone: false,
        aiSearchDone: false,
        searchQuery: ''
    };

    // Функция для инициации процесса поиска решения
    function startSolutionSearch() {
        // Сначала сбрасываем состояние
        searchState = {
            graphSearchDone: false,
            wikiSearchDone: false,
            aiSearchDone: false,
            searchQuery: ''
        };

        // Начинаем с графа
        $('#support-chat-container').empty();
        $('.support-welcome-message').remove();
        loadNode('root');
    }

    // Модифицируем showSolution, чтобы предлагать альтернативные варианты поиска
    function showSolution(text) {
        $('#support-solution-text').text(text);
        $('#support-options-container').hide();

        // Добавляем опцию поиска в MediaWiki, если решение не подходит
        $('#support-wiki-search-button').show();
        $('#support-ai-search-button').show();

        $('#support-solution-container').show();

        // Запоминаем найденное решение
        currentSolution = text;
        searchState.graphSearchDone = true;
    }

    // Функция для поиска по MediaWiki, если решение через граф не подошло
    function searchWiki(query) {
        searchState.searchQuery = query || currentSolution;

        // Показываем индикатор загрузки
        $('#support-search-results').html(
            '<div class="support-loading">' +
            '<div class="support-spinner"></div>' +
            '<p>' + (messages.search_loading || 'Searching...') + '</p>' +
            '</div>'
        );

        // Выполняем поиск через MediaWiki
        var api = new mw.Api();
        api.get({
            action: 'supportsearch',
            query: searchState.searchQuery,
            sources: 'opensearch|mediawiki',
            use_ai: 0
        }).done(function (data) {
            searchState.wikiSearchDone = true;

            if (data.results && data.results.length > 0) {
                displaySearchResults(data.results, searchState.searchQuery);
            } else {
                // Если результатов нет, предлагаем поиск через AI
                $('#support-search-results').html(
                    '<div class="support-no-results">' +
                    '<p>' + (messages.search_noresults || 'No results found.') + '</p>' +
                    '<button id="support-ai-search-button" class="support-button-primary">' +
                    (mw.msg('supportsystem-dt-ai-search') || 'Try AI Search') + '</button>' +
                    '</div>'
                );

                // Добавляем обработчик для кнопки AI поиска
                $('#support-ai-search-button').on('click', function () {
                    searchAI(searchState.searchQuery);
                });
            }
        }).fail(function (error) {
            // В случае ошибки также предлагаем поиск через AI
            $('#support-search-results').html(
                '<div class="support-error">' +
                '<p>' + (messages.search_error || 'An error occurred during the search.') + '</p>' +
                '<button id="support-ai-search-button" class="support-button-primary">' +
                (mw.msg('supportsystem-dt-ai-search') || 'Try AI Search') + '</button>' +
                '</div>'
            );

            // Добавляем обработчик для кнопки AI поиска
            $('#support-ai-search-button').on('click', function () {
                searchAI(searchState.searchQuery);
            });

            console.error('Search error:', error);
        });
    }
    /**
     * Search using AI
     * @param {string} query
     */
    function searchAI(query) {
        var api = new mw.Api();
        searchState.searchQuery = query || searchState.searchQuery || currentSolution;

        // Подготовить контекст из истории диалога
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

        console.log('AI search params:', {
            query: searchState.searchQuery,
            context: context
        });

        api.get({
            action: 'supportsearch',
            query: searchState.searchQuery,
            use_ai: 1,
            context: JSON.stringify(context)
        }).done(function (data) {
            console.log('AI search response:', data);

            searchState.aiSearchDone = true;

            if (data.ai_result && data.ai_result.success) {
                // Показать ответ AI
                $('#support-ai-text').text(data.ai_result.answer);
                $('#support-ai-loading').hide();
                $('#support-ai-content').show();

                // Показать источники, если есть
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
                        } else {
                            li.text(source.title);
                        }

                        sourcesList.append(li);
                    });

                    $('#support-ai-sources').show();
                } else {
                    $('#support-ai-sources').hide();
                }

                // Всегда показываем кнопку создания заявки как последний шаг
                $('#support-ai-ticket-button').show();
            } else {
                // Показать сообщение об ошибке и кнопку создания заявки
                $('#support-ai-text').text(data.ai_result && data.ai_result.answer ||
                    getMessage('supportsystem-dt-ai-error', 'An error occurred while processing the AI request.'));
                $('#support-ai-loading').hide();
                $('#support-ai-content').show();
                $('#support-ai-sources').hide();
                $('#support-ai-ticket-button').show();
            }
        }).fail(function (error) {
            console.error('AI search error:', error);

            searchState.aiSearchDone = true;

            $('#support-ai-text').text(getMessage('supportsystem-dt-ai-error', 'An error occurred while processing the AI request.'));
            $('#support-ai-loading').hide();
            $('#support-ai-content').show();
            $('#support-ai-sources').hide();
            $('#support-ai-ticket-button').show();
        });
    }

    /**
     * Прокрутить чат вниз
     */
    function scrollChatToBottom() {
        var container = document.getElementById('support-chat-container');
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
    }

    /**
     * Инициализация поискового модуля
     */
    function initSearchTab() {
        // Обработчик кнопки поиска
        $('#support-search-button').on('click', function () {
            var query = $('#support-search-input').val().trim();
            if (query) {
                searchSolutions(query);
            } else {
                mw.notify(messages.search_empty || 'Please enter a search query', { type: 'error' });
            }
        });

        // Обработчик поиска по Enter
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
    * Search for solutions
    * @param {string} query
    */
    function searchSolutions(query) {
        var api = new mw.Api();
        var useAI = $('#support-search-use-ai').is(':checked');

        // Показать индикатор загрузки
        $('#support-search-results').html(
            '<div class="support-loading">' +
            '<div class="support-spinner"></div>' +
            '<p>' + getMessage('supportsystem-search-loading', 'Searching...') + '</p>' +
            '</div>'
        );

        console.log('Search params:', {
            query: query,
            useAI: useAI
        });

        api.get({
            action: 'supportsearch',
            query: query,
            sources: 'opensearch|mediawiki',
            use_ai: useAI
        }).done(function (data) {
            console.log('Search response:', data);

            if (useAI && data.ai_result) {
                displayAIResult(data.ai_result, query);
            } else if (data.results && data.results.length > 0) {
                displaySearchResults(data.results, query);
            } else {
                $('#support-search-results').html(
                    '<div class="support-no-results">' +
                    '<p>' + getMessage('supportsystem-search-noresults', 'No results found. Try changing your query.') + '</p>' +
                    '</div>' +
                    (useAI ? '' : '<div class="support-try-ai">' +
                        '<button id="support-search-ai-button" class="support-button-primary">' +
                        getMessage('supportsystem-search-try-ai', 'Try AI-powered search') + '</button>' +
                        '</div>')
                );

                // Добавляем обработчик для кнопки AI поиска
                $('#support-search-ai-button').on('click', function () {
                    $('#support-search-use-ai').prop('checked', true);
                    searchSolutions(query);
                });
            }
        }).fail(function (error) {
            console.error('Search error:', error);
            $('#support-search-results').html(
                '<div class="support-error">' +
                '<p>' + getMessage('supportsystem-search-error', 'An error occurred during the search.') + '</p>' +
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

        // Форматирование ответа с правильными переносами и списками
        var formattedAnswer = aiResult.answer
            .replace(/\n/g, '<br>')
            .replace(/(\d+\. )/g, '<br>$1');

        resultsHtml += '<div class="support-ai-answer">' + formattedAnswer + '</div>';

        // Показать источники, если есть
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

        // Добавить кнопку для создания заявки с этим ответом
        resultsHtml += '<div class="support-ai-actions">';
        resultsHtml += '<button class="support-create-ticket-btn support-button-primary" data-solution="' +
            aiResult.answer.replace(/"/g, '&quot;') + '" data-source="ai">' +
            (mw.msg('supportsystem-search-create-ticket-ai') || 'Create ticket with this answer') + '</button>';
        resultsHtml += '</div>';

        resultsHtml += '</div>';

        $('#support-search-results').html(resultsHtml);

        // Добавить обработчик кнопки создания заявки
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

            // Использовать highlight, если есть
            if (result.highlight) {
                content = result.highlight.replace(/\n/g, '<br>');
            } else {
                // Обрезать длинное содержимое
                var maxLength = 300;
                if (content && content.length > maxLength) {
                    content = content.substring(0, maxLength) + '...';
                }
            }

            // Создать HTML для тегов, если есть
            var tagsHtml = '';
            if (result.tags && result.tags.length > 0) {
                tagsHtml = '<div class="support-result-tags"><strong>' +
                    (mw.msg('supportsystem-search-tags') || 'Tags') + ':</strong> ';

                tagsHtml += result.tags.map(function (tag) {
                    return '<span class="support-tag">' + tag + '</span>';
                }).join(' ');

                tagsHtml += '</div>';
            }

            // Создать карточку результата
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

        // Добавить обработчик кнопки создания заявки
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
        switch (source) {
            case 'opensearch':
                return mw.msg('supportsystem-search-source-opensearch') || 'Knowledge Base';
            case 'mediawiki':
                return mw.msg('supportsystem-search-source-mediawiki') || 'MediaWiki';
            case 'ai':
                return mw.msg('supportsystem-search-source-ai') || 'AI Analysis';
            default:
                return mw.msg('supportsystem-search-source-unknown') || 'Unknown Source';
        }
    }

    /**
     * Инициализация модуля заявок
     */
    function initTicketsTab() {
        // Обработчик кнопки создания новой заявки
        $('#support-tickets-create').on('click', function () {
            showTicketForm('', 'new');
        });

        // Обработчик кнопки возврата к списку заявок
        $('#support-ticket-details-back').on('click', function () {
            $('#support-ticket-details').hide();
            $('#support-tickets-list').show();
        });

        // Обработчик отправки комментария к заявке
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
     * Загрузка списка заявок
     */
    function loadTickets() {
        var api = new mw.Api();

        api.get({
            action: 'supportticket',
            operation: 'list'
        }).done(function (data) {
            if (data.tickets && data.tickets.length > 0) {
                displayTickets(data.tickets);
            } else {
                $('#support-tickets-list').html(
                    '<div class="support-empty-list">' +
                    '<p>' + (mw.msg('supportsystem-sd-empty') || 'You don\'t have any tickets yet') + '</p>' +
                    '</div>'
                );
            }
        }).fail(function (error) {
            $('#support-tickets-list').html(
                '<div class="support-error">' +
                '<p>' + (mw.msg('supportsystem-sd-error') || 'Error loading tickets') + '</p>' +
                '</div>'
            );
            console.error('Error loading tickets:', error);
        });
    }

    /**
     * Отображение списка заявок
     * @param {Array} tickets Список заявок
     */
    function displayTickets(tickets) {
        var listHtml = '';

        tickets.sort(function (a, b) {
            return new Date(b.created_on) - new Date(a.created_on);
        });

        tickets.forEach(function (ticket) {
            var statusClass = 'support-status-' + (ticket.status || 'new').replace(' ', '-').toLowerCase();
            var priorityClass = 'support-priority-' + (ticket.priority || 'normal').toLowerCase();

            listHtml +=
                '<div class="support-ticket-item" data-ticket-id="' + ticket.id + '">' +
                '<div class="support-ticket-header">' +
                '<h4>#' + ticket.id + ': ' + ticket.subject + '</h4>' +
                '<div class="support-ticket-meta">' +
                '<span class="support-status-badge ' + statusClass + '">' +
                (ticket.status || 'new') + '</span>' +
                '</div>' +
                '</div>' +
                '<div class="support-ticket-info">' +
                '<span class="support-priority-badge ' + priorityClass + '">' +
                ticket.priority + '</span>' +
                '<span class="support-ticket-date">' + formatDate(ticket.created_on) + '</span>' +
                '</div>' +
                '</div>';
        });

        $('#support-tickets-list').html(listHtml);

        // Добавить обработчик клика по заявке
        $('.support-ticket-item').on('click', function () {
            var ticketId = $(this).data('ticket-id');
            viewTicket(ticketId);
        });
    }

    /**
     * Просмотр заявки
     * @param {number} ticketId ID заявки
     */
    function viewTicket(ticketId) {
        var api = new mw.Api();

        $('#support-tickets-list').hide();
        $('#support-ticket-details').show().data('ticket-id', ticketId);

        // Показать индикатор загрузки
        $('#support-ticket-details').html(
            '<div class="support-loading">' +
            '<div class="support-spinner"></div>' +
            '<p>' + (mw.msg('supportsystem-sd-loading') || 'Loading ticket...') + '</p>' +
            '</div>'
        );

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
                    '<p>' + (mw.msg('supportsystem-error-ticket-not-found') || 'Ticket not found') + '</p>' +
                    '<button id="support-ticket-details-back" class="support-button-secondary">' +
                    (mw.msg('supportsystem-sd-ticket-back') || 'Back to List') + '</button>' +
                    '</div>'
                );

                // Добавить обработчик для кнопки возврата
                $('#support-ticket-details-back').on('click', function () {
                    $('#support-ticket-details').hide();
                    $('#support-tickets-list').show();
                });
            }
        }).fail(function (error) {
            $('#support-ticket-details').html(
                '<div class="support-error">' +
                '<p>' + (mw.msg('supportsystem-sd-ticket-error') || 'Error loading ticket') + '</p>' +
                '<button id="support-ticket-details-back" class="support-button-secondary">' +
                (mw.msg('supportsystem-sd-ticket-back') || 'Back to List') + '</button>' +
                '</div>'
            );

            // Добавить обработчик для кнопки возврата
            $('#support-ticket-details-back').on('click', function () {
                $('#support-ticket-details').hide();
                $('#support-tickets-list').show();
            });

            console.error('Error loading ticket:', error);
        });
    }

    /**
     * Отображение деталей заявки
     * @param {Object} ticket Данные заявки
     */
    function displayTicketDetails(ticket) {
        $('#support-ticket-details-title').text('#' + ticket.id + ': ' + ticket.subject);

        var statusClass = 'support-status-' + (ticket.status || 'new').replace(' ', '-').toLowerCase();
        $('#support-ticket-status').text(ticket.status || 'new').addClass(statusClass);

        var priorityClass = 'support-priority-' + (ticket.priority || 'normal').toLowerCase();
        $('#support-ticket-priority-value').text(ticket.priority || 'normal').addClass(priorityClass);

        $('#support-ticket-created-date').text(formatDate(ticket.created_on));

        $('#support-ticket-description-text').text(ticket.description);

        // Отображение комментариев
        var commentsHtml = '';

        if (ticket.comments && ticket.comments.length > 0) {
            ticket.comments.forEach(function (comment) {
                commentsHtml +=
                    '<div class="support-comment">' +
                    '<div class="support-comment-content">' + comment.text + '</div>' +
                    '<div class="support-comment-meta">' + '<div class="support-comment-meta">' +
                    '<span class="support-comment-date">' + formatDate(comment.created_on) + '</span>' +
                    '</div>' +
                    '</div>';
            });
        } else {
            commentsHtml = '<p class="support-no-comments">' +
                (mw.msg('supportsystem-sd-ticket-no-comments') || 'No comments yet') + '</p>';
        }

        $('#support-ticket-comments').html(commentsHtml);

        // Очистить поле ввода комментария
        $('#support-comment-text').val('');
    }

    function showTicketForm(solution, source) {
        selectedSolution = solution || '';
        selectedSource = source || '';

        // Вместо использования переменных шаблона напрямую
        // используем функцию getMessage для получения текста сообщения или значения по умолчанию
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

    /**
     * Вспомогательная функция для получения текста сообщения
     * @param {string} key Ключ сообщения
     * @param {string} defaultValue Значение по умолчанию
     * @return {string} Текст сообщения
     */
    function getMessage(key, defaultValue) {
        // Проверяем доступность mw.msg
        if (typeof mw !== 'undefined' && mw.msg) {
            var message = mw.msg(key);
            // Проверяем, не является ли возвращаемое значение самим ключом
            if (message.indexOf('⧼') === 0 && message.indexOf('⧽') === message.length - 1) {
                return defaultValue;
            }
            return message;
        }
        return defaultValue;
    }

    // Улучшенная функция getSourceLabel
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
    /**
     * Submit a ticket with the selected solution
     */
    function submitTicket() {
        var api = new mw.Api();

        var subject = $('#support-ticket-subject').val();
        var description = $('#support-ticket-description').val();
        var priority = $('#support-ticket-priority').val();

        // Добавляем логирование для отладки
        console.log('Submitting ticket:', {
            subject: subject,
            description: description,
            priority: priority
        });

        if (!subject) {
            mw.notify(getMessage('supportsystem-sd-ticket-subject-required', 'Ticket subject is required'), { type: 'error' });
            $('#support-ticket-subject').focus();
            return;
        }

        if (!description) {
            mw.notify(getMessage('supportsystem-sd-ticket-description-required', 'Problem description is required'), { type: 'error' });
            $('#support-ticket-description').focus();
            return;
        }

        $('#support-ticket-submit').prop('disabled', true);
        $('#support-ticket-submit').text(getMessage('supportsystem-dt-submitting', 'Submitting...'));

        api.post({
            action: 'supportticket',
            operation: 'create',  // Используем правильное имя параметра
            subject: subject,
            description: description,
            priority: priority
        }).done(function (data) {
            console.log('Ticket creation response:', data);

            if (data.ticket) {
                // Если есть решение, прикрепить его к заявке
                if (selectedSolution) {
                    attachSolution(data.ticket.id);
                } else {
                    showTicketSuccess(data.ticket.id);
                }
            } else {
                mw.notify(getMessage('supportsystem-search-ticket-error', 'Error creating ticket'), { type: 'error' });
                $('#support-ticket-submit').prop('disabled', false);
                $('#support-ticket-submit').text(getMessage('supportsystem-dt-submit', 'Submit'));
            }
        }).fail(function (error) {
            console.error('Error creating ticket:', error);
            mw.notify(getMessage('supportsystem-search-ticket-error', 'Error creating ticket'), { type: 'error' });
            $('#support-ticket-submit').prop('disabled', false);
            $('#support-ticket-submit').text(getMessage('supportsystem-dt-submit', 'Submit'));
        });
    }
    /**
     * Attach a solution to a ticket
     * @param {number} ticketId
     */
    function attachSolution(ticketId) {
        var api = new mw.Api();

        api.post({
            action: 'supportticket',
            operation: 'solution',  // Используем правильное имя параметра
            ticket_id: ticketId,
            solution: selectedSolution,
            source: selectedSource
        }).done(function () {
            showTicketSuccess(ticketId);
        }).fail(function (error) {
            // Даже если не удалось прикрепить решение, тикет всё равно создан
            showTicketSuccess(ticketId);
            console.error('Error attaching solution:', error);
        });
    }
    /**
     * Add a comment to a ticket
     * @param {number} ticketId
     * @param {string} comment
     */
    function addComment(ticketId, comment) {
        var api = new mw.Api();

        $('#support-comment-submit').prop('disabled', true);

        api.post({
            action: 'supportticket',
            operation: 'comment',  // Используем правильное имя параметра
            ticket_id: ticketId,
            comment: comment
        }).done(function (data) {
            if (data.result === 'success') {
                mw.notify(getMessage('supportsystem-sd-ticket-comment-success', 'Comment added successfully'), { type: 'success' });
                $('#support-comment-text').val('');

                // Обновить данные заявки
                viewTicket(ticketId);
            } else {
                mw.notify(getMessage('supportsystem-sd-ticket-comment-error', 'Error adding comment'), { type: 'error' });
            }
        }).fail(function (error) {
            mw.notify(getMessage('supportsystem-sd-ticket-comment-error', 'Error adding comment'), { type: 'error' });
            console.error('Error adding comment:', error);
        }).always(function () {
            $('#support-comment-submit').prop('disabled', false);
        });
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

        // Обновить список заявок и переключиться на вкладку заявок
        loadTickets();
        showPanel('tickets');
    }

    /**
     * Форматирование даты
     * @param {string} dateStr Строка с датой
     * @return {string} Отформатированная дата
     */
    function formatDate(dateStr) {
        if (!dateStr) {
            return '';
        }

        try {
            var date = new Date(dateStr);
            return date.toLocaleString();
        } catch (e) {
            return dateStr;
        }
    }

    // Инициализация при загрузке DOM
    $(init);
}());