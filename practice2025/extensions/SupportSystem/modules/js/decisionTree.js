/**
 * JavaScript for the decision tree dialog
 */
(function () {
    'use strict';

    // Current node and solution
    var currentNodeId = 'root';
    var currentSolution = '';
    var dialogHistory = [];

    /**
     * Initialize the dialog
     */
    function init() {
        // Start button
        $('#support-dt-start-button').on('click', function () {
            $(this).remove();
            $('.support-dt-welcome-message').remove();
            loadNode('root');
        });

        // Restart button
        $('#support-dt-restart-button').on('click', function () {
            $('#support-dt-chat-container').empty();
            $('#support-dt-solution-container').hide();
            $('#support-dt-options-container').show();
            dialogHistory = [];
            loadNode('root');
        });

        // AI button
        $('#support-dt-ai-button').on('click', function () {
            searchAI();
        });

        // Ticket button
        $('#support-dt-ticket-button, #support-dt-ai-ticket-button').on('click', function () {
            showTicketForm();
        });

        // AI accept button
        $('#support-dt-ai-accept-button').on('click', function () {
            $('#support-dt-ai-container').hide();
            $('#support-dt-solution-container').show();
        });

        // Ticket form cancel button
        $('#support-dt-ticket-cancel').on('click', function () {
            $('#support-dt-ticket-form').hide();
        });

        // Ticket form submit
        $('#support-dt-ticket-submit-form').on('submit', function (e) {
            e.preventDefault();
            submitTicket();
        });

        // Option buttons (will be added dynamically)
        $(document).on('click', '.support-dt-option-btn', function () {
            var childId = $(this).data('child-id');
            var optionText = $(this).text();

            // Add user message to chat
            addMessage(optionText, 'user');

            // Clear options
            $('#support-dt-options-container').empty();

            // Save selected option to history
            dialogHistory.push({
                nodeId: currentNodeId,
                selectedOption: optionText,
                selectedNodeId: childId
            });

            // Load next node
            loadNode(childId);
        });
    }

    /**
     * Load a node from the API
     * @param {string} nodeId
     */
    function loadNode(nodeId) {
        var api = new mw.Api();

        api.get({
            action: 'supportnode',
            node_id: nodeId
        }).done(function (data) {
            currentNodeId = data.supportnode.id;

            // Add system message to chat
            addMessage(data.supportnode.content, 'system');

            // Handle the node based on type
            if (data.supportnode.type === 'question') {
                // Show options for the question
                showOptions(data.supportnode.children);
            } else {
                // Show solution
                currentSolution = data.supportnode.content;
                showSolution(data.supportnode.content);
            }

            // Scroll chat to bottom
            scrollToBottom();
        }).fail(function (error) {
            mw.notify(mw.msg('supportsystem-dt-error-loading-node'), { type: 'error' });
            console.error('Error loading node:', error);
        });
    }

    /**
     * Add a message to the chat
     * @param {string} text
     * @param {string} sender 'system' or 'user'
     */
    function addMessage(text, sender) {
        var className = sender === 'system' ? 'support-dt-system-message' : 'support-dt-user-message';
        var align = sender === 'system' ? 'left' : 'right';
        var bubbleClass = sender === 'system' ? 'support-dt-system-bubble' : 'support-dt-user-bubble';

        var html = '<div class="' + className + '">' +
            '<div class="support-dt-message-align-' + align + '">' +
            '<div class="' + bubbleClass + '">' + text + '</div>' +
            '</div>' +
            '</div>';

        $('#support-dt-chat-container').append(html);
    }

    /**
     * Show options for a question
     * @param {Array} options
     */
    function showOptions(options) {
        var container = $('#support-dt-options-container');
        container.empty();

        options.forEach(function (option) {
            var button = $('<button>')
                .addClass('support-dt-option-btn')
                .text(option.label)
                .data('child-id', option.id);

            container.append(button);
        });

        container.show();
    }

    /**
     * Show a solution
     * @param {string} text
     */
    function showSolution(text) {
        $('#support-dt-solution-text').text(text);
        $('#support-dt-options-container').hide();
        $('#support-dt-solution-container').show();
    }

    /**
     * Show the ticket form
     */
    function showTicketForm() {
        // Pre-fill form
        $('#support-dt-ticket-subject').val(mw.msg('supportsystem-dt-default-subject'));
        $('#support-dt-ticket-description').val(buildTicketDescription());

        $('#support-dt-ticket-form').show();

        // Scroll to form
        $('html, body').animate({
            scrollTop: $('#support-dt-ticket-form').offset().top - 20
        }, 500);
    }

    /**
     * Build ticket description with dialog history
     * @return {string}
     */
    function buildTicketDescription() {
        var description = mw.msg('supportsystem-dt-dialog-history') + '\n\n';

        dialogHistory.forEach(function (item, index) {
            description += (index + 1) + '. ';
            description += mw.msg('supportsystem-dt-dialog-item', item.selectedOption) + '\n';
        });

        if (currentSolution) {
            description += '\n' + mw.msg('supportsystem-dt-dialog-solution') + '\n';
            description += currentSolution;
        }

        return description;
    }

    /**
     * Submit the ticket
     */
    function submitTicket() {
        var api = new mw.Api();

        var subject = $('#support-dt-ticket-subject').val();
        var description = $('#support-dt-ticket-description').val();
        var priority = $('#support-dt-ticket-priority').val();

        api.post({
            action: 'supportticket',
            action: 'create',
            subject: subject,
            description: description,
            priority: priority
        }).done(function (data) {
            if (data.ticket) {
                // If we have a solution, attach it to the ticket
                if (currentSolution) {
                    attachSolution(data.ticket.id);
                } else {
                    showTicketSuccess(data.ticket.id);
                }
            }
        }).fail(function (error) {
            mw.notify(mw.msg('supportsystem-dt-error-creating-ticket'), { type: 'error' });
            console.error('Error creating ticket:', error);
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
            action: 'solution',
            ticket_id: ticketId,
            solution: currentSolution,
            source: 'dialog'
        }).done(function () {
            showTicketSuccess(ticketId);
        }).fail(function (error) {
            // Even if attaching the solution fails, the ticket was created
            showTicketSuccess(ticketId);
            console.error('Error attaching solution:', error);
        });
    }

    /**
     * Show success message after ticket creation
     * @param {number} ticketId
     */
    function showTicketSuccess(ticketId) {
        $('#support-dt-ticket-form').hide();

        mw.notify(mw.msg('supportsystem-dt-ticket-created', ticketId), {
            type: 'success',
            autoHide: false
        });
    }

    /**
     * Search for a solution using AI
     */
    function searchAI() {
        var api = new mw.Api();

        // Build context from dialog history
        var context = [];
        dialogHistory.forEach(function (item) {
            context.push({
                question: item.nodeId,
                answer: item.selectedOption
            });
        });

        // Show loading message
        $('#support-dt-ai-text').text(mw.msg('supportsystem-dt-ai-loading'));
        $('#support-dt-ai-sources').hide();
        $('#support-dt-ai-container').show();
        $('#support-dt-solution-container').hide();

        api.get({
            action: 'supportsearch',
            query: currentSolution,
            use_ai: 1,
            context: JSON.stringify(context)
        }).done(function (data) {
            if (data.ai_result && data.ai_result.success) {
                // Show AI answer
                $('#support-dt-ai-text').text(data.ai_result.answer);

                // Show sources if available
                if (data.ai_result.sources && data.ai_result.sources.length > 0) {
                    var sourcesList = $('#support-dt-ai-sources-list');
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

                    $('#support-dt-ai-sources').show();
                }
            } else {
                // Show error message
                $('#support-dt-ai-text').text(data.ai_result.answer || mw.msg('supportsystem-dt-ai-error'));
                $('#support-dt-ai-sources').hide();
            }
        }).fail(function (error) {
            $('#support-dt-ai-text').text(mw.msg('supportsystem-dt-ai-error'));
            $('#support-dt-ai-sources').hide();
            console.error('Error in AI search:', error);
        });
    }

    /**
     * Scroll the chat container to the bottom
     */
    function scrollToBottom() {
        var container = document.getElementById('support-dt-chat-container');
        container.scrollTop = container.scrollHeight;
    }

    // Initialize when DOM is ready
    $(init);

}());