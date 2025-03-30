/**
 * JavaScript for the search solutions page
 */
(function () {
    'use strict';

    // Current search results and selected solution
    var allResults = [];
    var selectedSolution = '';
    var selectedSource = '';
    var searchContext = [];

    /**
     * Initialize the search page
     */
    function init() {
        // Search button click
        $('#support-search-button').on('click', function () {
            var query = $('#support-search-input').val().trim();
            if (query) {
                searchSolutions(query);
            } else {
                mw.notify(mw.msg('supportsystem-search-empty-query'), { type: 'error' });
            }
        });

        // Search on Enter key
        $('#support-search-input').on('keypress', function (e) {
            if (e.which === 13) {
                var query = $(this).val().trim();
                if (query) {
                    searchSolutions(query);
                } else {
                    mw.notify(mw.msg('supportsystem-search-empty-query'), { type: 'error' });
                }
            }
        });

        // Source filter change
        $('.support-source-filter').on('change', function () {
            applyFilters();
        });

        // "All sources" filter
        $('#support-filter-all').on('change', function () {
            var isChecked = $(this).prop('checked');
            $('.support-source-filter').prop('checked', isChecked);
            applyFilters();
        });

        // Create ticket from solution
        $(document).on('click', '.support-create-ticket-btn', function () {
            selectedSolution = $(this).data('solution');
            selectedSource = $(this).data('source');

            $('#support-solution-content').text(selectedSolution);
            $('#support-solution-source').text(mw.msg('supportsystem-search-source', getSourceLabel(selectedSource)));
            $('#support-selected-solution').removeClass('support-hidden');
            $('#support-ticket-form').removeClass('support-hidden');

            // Prefill the form
            $('#support-ticket-subject').val(mw.msg('supportsystem-search-default-subject'));
            $('#support-ticket-description').val(mw.msg('supportsystem-search-default-description'));

            // Scroll to form
            $('html, body').animate({
                scrollTop: $('#support-ticket-form').offset().top - 20
            }, 500);
        });

        // Cancel ticket creation
        $('#support-cancel-ticket').on('click', function () {
            $('#support-ticket-form').addClass('support-hidden');
            selectedSolution = '';
            selectedSource = '';
        });

        // Submit ticket
        $('#support-submit-ticket').on('click', function () {
            submitTicket();
        });

        // Try AI search button
        $(document).on('click', '#support-search-ai-button', function () {
            var query = $('#support-search-input').val().trim();
            if (query) {
                searchWithAI(query);
            } else {
                mw.notify(mw.msg('supportsystem-search-empty-query'), { type: 'error' });
            }
        });
    }

    /**
     * Search for solutions
     * @param {string} query
     */
    function searchSolutions(query) {
        // Show loading indicator
        $('#support-search-results').html(
            '<div class="support-loading">' +
            mw.msg('supportsystem-search-loading') +
            '</div>'
        );

        // Get selected sources
        var sources = [];
        if ($('#support-filter-opensearch').prop('checked')) sources.push('opensearch');
        if ($('#support-filter-mediawiki').prop('checked')) sources.push('mediawiki');

        var api = new mw.Api();

        api.get({
            action: 'supportsearch',
            query: query,
            sources: sources.join('|'),
            use_ai: 0
        }).done(function (data) {
            if (data.results && data.results.length > 0) {
                allResults = data.results;
                displayResults(allResults);
            } else {
                $('#support-search-results').html('<div class="support-no-results">' +
                    mw.msg('supportsystem-search-noresults') +
                    '</div>' +
                    '<div class="support-try-ai">' +
                    '<button id="support-search-ai-button" class="support-search-ai-button">' +
                    mw.msg('supportsystem-search-try-ai') +
                    '</button>' +
                    '</div>'
                );
            }
        }).fail(function (error) {
            $('#support-search-results').html(
                '<div class="support-error">' +
                mw.msg('supportsystem-search-error') +
                '</div>'
            );
            console.error('Search error:', error);
        });
    }

    /**
     * Search with AI
     * @param {string} query
     */
    function searchWithAI(query) {
        // Show loading indicator
        $('#support-search-results').html(
            '<div class="support-loading">' +
            mw.msg('supportsystem-search-ai-loading') +
            '</div>'
        );

        var api = new mw.Api();

        api.get({
            action: 'supportsearch',
            query: query,
            use_ai: 1,
            context: JSON.stringify(searchContext)
        }).done(function (data) {
            if (data.ai_result && data.ai_result.success) {
                // Update search context with this query
                if (searchContext.length >= 5) {
                    searchContext.shift(); // Remove oldest query if we have more than 5
                }

                searchContext.push({
                    query: query,
                    timestamp: new Date().toISOString()
                });

                displayAIResult(data.ai_result);
            } else {
                $('#support-search-results').html(
                    '<div class="support-error">' +
                    (data.ai_result && data.ai_result.answer ? data.ai_result.answer : mw.msg('supportsystem-search-ai-error')) +
                    '</div>'
                );
            }
        }).fail(function (error) {
            $('#support-search-results').html(
                '<div class="support-error">' +
                mw.msg('supportsystem-search-ai-error') +
                '</div>'
            );
            console.error('AI search error:', error);
        });
    }

    /**
     * Display AI search result
     * @param {Object} aiResult
     */
    function displayAIResult(aiResult) {
        var resultsHtml = '<div class="support-ai-result">';

        resultsHtml += '<h3>' + mw.msg('supportsystem-search-ai-result-title') + '</h3>';

        // Format answer with proper line breaks and lists
        var formattedAnswer = aiResult.answer
            .replace(/\n/g, '<br>')
            .replace(/(\d+\. )/g, '<br>$1');

        resultsHtml += '<div class="support-ai-answer">' + formattedAnswer + '</div>';

        // Show sources if available
        if (aiResult.sources && aiResult.sources.length > 0) {
            resultsHtml += '<div class="support-ai-sources">';
            resultsHtml += '<h4>' + mw.msg('supportsystem-search-ai-sources') + '</h4>';
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

        // Add a button to create a ticket with this answer
        resultsHtml += '<div class="support-ai-actions">';
        resultsHtml += '<button class="support-create-ticket-btn" data-solution="' +
            aiResult.answer.replace(/"/g, '&quot;') + '" data-source="ai">' +
            mw.msg('supportsystem-search-create-ticket-ai') + '</button>';
        resultsHtml += '</div>';

        resultsHtml += '</div>';

        $('#support-search-results').html(resultsHtml);
    }

    /**
     * Apply source filters to search results
     */
    function applyFilters() {
        if (!allResults || allResults.length === 0) {
            return;
        }

        var showOpensearch = $('#support-filter-opensearch').prop('checked');
        var showMediawiki = $('#support-filter-mediawiki').prop('checked');

        var filteredResults = allResults.filter(function (result) {
            var source = result.source || 'unknown';

            if (source === 'opensearch' && !showOpensearch) return false;
            if (source === 'mediawiki' && !showMediawiki) return false;

            return true;
        });

        displayResults(filteredResults);
    }

    /**
     * Display search results
     * @param {Array} results
     */
    function displayResults(results) {
        var resultsContainer = $('#support-search-results');
        resultsContainer.empty();

        if (results.length === 0) {
            resultsContainer.html(
                '<div class="support-no-filtered-results">' +
                mw.msg('supportsystem-search-no-filtered-results') +
                '</div>'
            );
            return;
        }

        resultsContainer.append(
            '<h3>' + mw.msg('supportsystem-search-results-count', results.length) + '</h3>'
        );

        results.forEach(function (result) {
            var source = result.source || 'unknown';
            var sourceLabel = getSourceLabel(source);
            var sourceBadgeClass = getSourceBadgeClass(source);

            var content = result.content;

            // Use highlight if available
            if (result.highlight) {
                content = result.highlight.replace(/\n/g, '<br>');
            } else {
                // Truncate long content
                var maxLength = 300;
                if (content && content.length > maxLength) {
                    content = content.substring(0, maxLength) + '...';
                }
            }

            // Create tags HTML if available
            var tagsHtml = '';
            if (result.tags && result.tags.length > 0) {
                tagsHtml = '<div class="support-result-tags"><strong>' +
                    mw.msg('supportsystem-search-tags') + ':</strong> ';

                tagsHtml += result.tags.map(function (tag) {
                    return '<span class="support-tag">' + tag + '</span>';
                }).join(' ');

                tagsHtml += '</div>';
            }

            // Create result card
            var cardHtml =
                '<div class="support-result-card">' +
                '<div class="support-result-header">' +
                '<h4>' + result.title + '</h4>' +
                '<div class="support-result-meta">' +
                '<span class="' + sourceBadgeClass + '">' + sourceLabel + '</span>' +
                '<span class="support-score-badge">' +
                mw.msg('supportsystem-search-relevance', Math.round(result.score * 10) / 10) +
                '</span>' +
                '</div>' +
                '</div>' +
                '<div class="support-result-body">' + '<div class="support-result-content">' + content + '</div>' +
                tagsHtml +
                '<div class="support-result-actions">' +
                (result.url ? '<a href="' + result.url + '" target="_blank" class="support-source-link">' +
                    mw.msg('supportsystem-search-source-link') + '</a>' : '') +
                '<button class="support-create-ticket-btn" data-solution="' +
                result.content.replace(/"/g, '&quot;') + '" data-source="' + source + '">' +
                mw.msg('supportsystem-search-create-ticket') + '</button>' +
                '</div>' +
                '</div>' +
                '</div>';

            resultsContainer.append(cardHtml);
        });

        // Add option to try AI search
        resultsContainer.append(
            '<div class="support-try-ai">' +
            '<button id="support-search-ai-button" class="support-search-ai-button">' +
            mw.msg('supportsystem-search-try-ai') +
            '</button>' +
            '</div>'
        );
    }

    /**
     * Submit a ticket with the selected solution
     */
    function submitTicket() {
        var api = new mw.Api();

        var ticketData = {
            subject: $('#support-ticket-subject').val(),
            description: $('#support-ticket-description').val(),
            priority: $('#support-ticket-priority').val()
        };

        $('#support-submit-ticket').prop('disabled', true);
        $('#support-submit-ticket').text(mw.msg('supportsystem-dt-submitting'));

        api.post({
            action: 'supportticket',
            action: 'create',
            subject: ticketData.subject,
            description: ticketData.description,
            priority: ticketData.priority
        }).done(function (data) {
            if (data.ticket) {
                // Attach solution to the ticket
                attachSolution(data.ticket.id);
            } else {
                mw.notify(mw.msg('supportsystem-search-ticket-error'), { type: 'error' });
                $('#support-submit-ticket').prop('disabled', false);
                $('#support-submit-ticket').text(mw.msg('supportsystem-dt-submit'));
            }
        }).fail(function (error) {
            mw.notify(mw.msg('supportsystem-search-ticket-error'), { type: 'error' });
            console.error('Error creating ticket:', error);
            $('#support-submit-ticket').prop('disabled', false);
            $('#support-submit-ticket').text(mw.msg('supportsystem-dt-submit'));
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
            solution: selectedSolution,
            source: selectedSource
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
        $('#support-ticket-form').addClass('support-hidden');
        $('#support-submit-ticket').prop('disabled', false);
        $('#support-submit-ticket').text(mw.msg('supportsystem-dt-submit'));

        mw.notify(mw.msg('supportsystem-search-ticket-created', ticketId), {
            type: 'success',
            autoHide: false
        });
    }

    /**
     * Get the display label for a source
     * @param {string} source
     * @return {string}
     */
    function getSourceLabel(source) {
        switch (source) {
            case 'opensearch':
                return mw.msg('supportsystem-search-source-opensearch');
            case 'mediawiki':
                return mw.msg('supportsystem-search-source-mediawiki');
            case 'ai':
                return mw.msg('supportsystem-search-source-ai');
            default:
                return mw.msg('supportsystem-search-source-unknown');
        }
    }

    /**
     * Get the CSS class for a source badge
     * @param {string} source
     * @return {string}
     */
    function getSourceBadgeClass(source) {
        switch (source) {
            case 'opensearch':
                return 'support-badge support-badge-opensearch';
            case 'mediawiki':
                return 'support-badge support-badge-mediawiki';
            case 'ai':
                return 'support-badge support-badge-ai';
            default:
                return 'support-badge support-badge-unknown';
        }
    }

    // Initialize when DOM is ready
    $(init);

}());