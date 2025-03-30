/**
 * JavaScript for the service desk page
 */
(function () {
    'use strict';

    // Current ticket ID and all tickets
    var currentTicketId = null;
    var allTickets = [];

    /**
     * Initialize the service desk page
     */
    function init() {
        // Load tickets when the page loads
        loadTickets();

        // New ticket button
        $('#support-sd-new-button').on('click', function () {
            showTicketForm();
        });

        // Back to list button
        $('#support-sd-back-button').on('click', function () {
            $('#support-sd-ticket-details').addClass('support-hidden');
            currentTicketId = null;
        });

        // Cancel ticket creation
        $('#support-sd-cancel-ticket').on('click', function () {
            $('#support-sd-ticket-form').addClass('support-hidden');
            clearTicketForm();
        });

        // Submit ticket
        $('#support-sd-submit-ticket').on('click', function () {
            submitTicket();
        });

        // Add comment
        $('#support-sd-add-comment-button').on('click', function () {
            addComment();
        });

        // Click on a ticket to view details
        $(document).on('click', '.support-sd-ticket-item', function () {
            var ticketId = parseInt($(this).data('ticket-id'));
            viewTicket(ticketId);
        });
    }

    /**
     * Load all tickets
     */
    function loadTickets() {
        var api = new mw.Api();

        $('#support-sd-tickets-container').html(
            '<div class="support-sd-loading">' +
            mw.msg('supportsystem-sd-loading') +
            '</div>'
        );

        api.get({
            action: 'supportticket',
            action: 'list'
        }).done(function (data) {
            allTickets = data.tickets || [];

            if (allTickets.length === 0) {
                $('#support-sd-tickets-container').html(
                    '<div class="support-sd-empty">' +
                    mw.msg('supportsystem-sd-empty') +
                    '</div>'
                );
                return;
            }

            updateTicketsList();

            // If we were viewing a ticket, refresh its details
            if (currentTicketId) {
                viewTicket(currentTicketId);
            }
        }).fail(function (error) {
            $('#support-sd-tickets-container').html(
                '<div class="support-sd-error">' +
                mw.msg('supportsystem-sd-error') +
                '</div>'
            );
            console.error('Error loading tickets:', error);
        });
    }

    /**
     * Update the tickets list
     */
    function updateTicketsList() {
        var container = $('#support-sd-tickets-container');
        container.empty();

        // Sort tickets by date (newest first)
        allTickets.sort(function (a, b) {
            return new Date(b.created_on) - new Date(a.created_on);
        });

        allTickets.forEach(function (ticket) {
            var statusClass = getStatusClass(ticket.status);
            var priorityClass = getPriorityClass(ticket.priority);

            var ticketHtml =
                '<div class="support-sd-ticket-item" data-ticket-id="' + ticket.id + '">' +
                '<div class="support-sd-ticket-header">' +
                '<h4>#' + ticket.id + ': ' + ticket.subject + '</h4>' +
                '<div class="support-sd-ticket-meta">' +
                '<span class="support-sd-status-badge ' + statusClass + '">' +
                (ticket.status || 'new') + '</span>' +
                '</div>' +
                '</div>' +
                '<div class="support-sd-ticket-info">' +
                '<span class="support-sd-priority-badge ' + priorityClass + '">' +
                ticket.priority + '</span>' +
                '<span class="support-sd-date">' + formatDate(ticket.created_on) + '</span>' +
                '</div>' +
                '</div>';

            container.append(ticketHtml);
        });
    }

    /**
     * Show the ticket creation form
     */
    function showTicketForm() {
        $('#support-sd-ticket-form').removeClass('support-hidden');
        $('#support-sd-ticket-details').addClass('support-hidden');

        // Focus on the subject field
        $('#support-sd-ticket-subject').focus();
    }

    /**
     * Clear the ticket form
     */
    function clearTicketForm() {
        $('#support-sd-ticket-subject').val('');
        $('#support-sd-ticket-description').val('');
        $('#support-sd-ticket-priority').val('normal');
    }

    /**
     * Submit a new ticket
     */
    function submitTicket() {
        var api = new mw.Api();

        var subject = $('#support-sd-ticket-subject').val();
        var description = $('#support-sd-ticket-description').val();
        var priority = $('#support-sd-ticket-priority').val();

        // Validate form
        if (!subject) {
            mw.notify(mw.msg('supportsystem-sd-ticket-subject-required'), { type: 'error' });
            $('#support-sd-ticket-subject').focus();
            return;
        }

        if (!description) {
            mw.notify(mw.msg('supportsystem-sd-ticket-description-required'), { type: 'error' });
            $('#support-sd-ticket-description').focus();
            return;
        }

        // Show loading state
        $('#support-sd-submit-ticket').prop('disabled', true);

        api.post({
            action: 'supportticket',
            action: 'create',
            subject: subject,
            description: description,
            priority: priority
        }).done(function (data) {
            if (data.ticket) {
                $('#support-sd-ticket-form').addClass('support-hidden');
                clearTicketForm();

                mw.notify(mw.msg('supportsystem-sd-ticket-created', data.ticket.id), {
                    type: 'success',
                    autoHide: false
                });

                // Reload tickets to show the new one
                loadTickets();
            }
        }).fail(function (error) {
            mw.notify(mw.msg('supportsystem-sd-ticket-error-creating'), { type: 'error' });
            console.error('Error creating ticket:', error);
        }).always(function () {
            $('#support-sd-submit-ticket').prop('disabled', false);
        });
    }

    /**
     * View a ticket's details
     * @param {number} ticketId
     */
    function viewTicket(ticketId) {
        var api = new mw.Api();

        currentTicketId = ticketId;

        // Show loading state
        $('#support-sd-details-subject').text(mw.msg('supportsystem-sd-loading'));
        $('#support-sd-details-description').empty();
        $('#support-sd-comments').empty();
        $('#support-sd-ticket-details').removeClass('support-hidden');
        $('#support-sd-ticket-form').addClass('support-hidden');

        // Try to find the ticket in the loaded tickets first for better UX
        var ticket = allTickets.find(function (t) {
            return t.id === ticketId;
        });

        if (ticket) {
            displayTicketDetails(ticket);
        }

// Still fetch the latest ticket data from the API
        api.get({
            action: 'supportticket',
            action: 'get',
            ticket_id: ticketId
        }).done(function (data) {
            if (data.ticket) {
                displayTicketDetails(data.ticket);
            } else {
                mw.notify(mw.msg('supportsystem-sd-ticket-error-not-found'), { type: 'error' });
            }
        }).fail(function (error) {
            mw.notify(mw.msg('supportsystem-sd-ticket-error'), { type: 'error' });
            console.error('Error loading ticket:', error);
        });
    }

    /**
     * Display ticket details
     * @param {Object} ticket
     */
    function displayTicketDetails(ticket) {
        // Update basic ticket info
        $('#support-sd-details-subject').text('#' + ticket.id + ': ' + ticket.subject);

        var statusClass = getStatusClass(ticket.status);
        $('#support-sd-details-status')
            .text(ticket.status || 'new')
            .removeClass()
            .addClass('support-sd-status-badge ' + statusClass);

        var priorityClass = getPriorityClass(ticket.priority);
        $('#support-sd-details-priority')
            .text(ticket.priority)
            .removeClass()
            .addClass('support-sd-priority-badge ' + priorityClass);

        $('#support-sd-details-date').text(formatDate(ticket.created_on));
        $('#support-sd-details-description').text(ticket.description);

        // Update comments
        $('#support-sd-comments').empty();

        if (ticket.comments && ticket.comments.length > 0) {
            ticket.comments.forEach(function (comment) {
                var commentHtml =
                    '<div class="support-sd-comment">' +
                    '<div class="support-sd-comment-content">' + comment.text + '</div>' +
                    '<div class="support-sd-comment-meta">' +
                    '<span class="support-sd-comment-date">' + formatDate(comment.created_on) + '</span>' +
                    '</div>' +
                    '</div>';

                $('#support-sd-comments').append(commentHtml);
            });
        } else {
            $('#support-sd-comments').html(
                '<p class="support-sd-no-comments">' +
                mw.msg('supportsystem-sd-ticket-no-comments') +
                '</p>'
            );
        }

        // Clear comment input
        $('#support-sd-new-comment').val('');
    }

    /**
     * Add a comment to the current ticket
     */
    function addComment() {
        if (!currentTicketId) {
            return;
        }

        var commentText = $('#support-sd-new-comment').val().trim();

        if (!commentText) {
            mw.notify(mw.msg('supportsystem-sd-ticket-comment-required'), { type: 'error' });
            $('#support-sd-new-comment').focus();
            return;
        }

        var api = new mw.Api();

        // Show loading state
        $('#support-sd-add-comment-button').prop('disabled', true);

        api.post({
            action: 'supportticket',
            action: 'comment',
            ticket_id: currentTicketId,
            comment: commentText
        }).done(function (data) {
            if (data.result === 'success') {
                mw.notify(mw.msg('supportsystem-sd-ticket-comment-success'), { type: 'success' });
                $('#support-sd-new-comment').val('');

                // Reload tickets to get the updated comment
                loadTickets();
            }
        }).fail(function (error) {
            mw.notify(mw.msg('supportsystem-sd-ticket-comment-error'), { type: 'error' });
            console.error('Error adding comment:', error);
        }).always(function () {
            $('#support-sd-add-comment-button').prop('disabled', false);
        });
    }

    /**
     * Get the CSS class for a ticket status
     * @param {string} status
     * @return {string}
     */
    function getStatusClass(status) {
        switch (status) {
            case 'new':
                return 'support-sd-status-new';
            case 'in_progress':
                return 'support-sd-status-in-progress';
            case 'resolved':
                return 'support-sd-status-resolved';
            case 'closed':
                return 'support-sd-status-closed';
            default:
                return 'support-sd-status-new';
        }
    }

    /**
     * Get the CSS class for a ticket priority
     * @param {string} priority
     * @return {string}
     */
    function getPriorityClass(priority) {
        switch (priority) {
            case 'low':
                return 'support-sd-priority-low';
            case 'normal':
                return 'support-sd-priority-normal';
            case 'high':
                return 'support-sd-priority-high';
            case 'urgent':
                return 'support-sd-priority-urgent';
            default:
                return 'support-sd-priority-normal';
        }
    }

    /**
     * Format a date string
     * @param {string} dateStr
     * @return {string}
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

    // Initialize when DOM is ready
    $(init);

}());