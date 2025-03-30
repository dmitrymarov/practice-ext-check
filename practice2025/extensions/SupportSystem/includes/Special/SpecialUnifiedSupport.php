<?php

namespace MediaWiki\Extension\SupportSystem\Special;

use SpecialPage;
use HTMLForm;
use MediaWiki\Extension\SupportSystem\DecisionGraph;
use MediaWiki\Extension\SupportSystem\SearchModule;

/**
 * Special page for the unified support system interface
 */
class SpecialUnifiedSupport extends SpecialPage
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct('UnifiedSupport');
    }

    /**
     * Show the special page
     * @param string|null $par Parameter passed to the page
     */
    public function execute($par)
    {
        $this->setHeaders();
        $this->outputHeader();

        $out = $this->getOutput();
        $out->setPageTitle($this->msg('supportsystem-unified-title'));

        // Add modules and styles
        $out->addModules('ext.supportSystem.unified');
        
        // Add description
        $out->addWikiTextAsInterface($this->msg('supportsystem-unified-desc')->text());

        // Create the main container
        $html = $this->createMainContainer();
        $out->addHTML($html);
        
        // Add JS config
        $out->addJsConfigVars([
            'supportsystemConfig' => [
                'messages' => [
                    'error_loading_node' => $this->msg('supportsystem-dt-error-loading-node')->text(),
                    'error_creating_ticket' => $this->msg('supportsystem-dt-error-creating-ticket')->text(),
                    'ai_error' => $this->msg('supportsystem-dt-ai-error')->text(),
                    'ai_loading' => $this->msg('supportsystem-dt-ai-loading')->text(),
                    'search_loading' => $this->msg('supportsystem-search-loading')->text(),
                    'search_empty' => $this->msg('supportsystem-search-empty-query')->text(),
                    'search_error' => $this->msg('supportsystem-search-error')->text(),
                    'ticket_created' => $this->msg('supportsystem-dt-ticket-created')->text(),
                ]
            ]
        ]);
    }

    /**
     * Create HTML for the main container
     * @return string HTML
     */
    private function createMainContainer(): string
    {
        $html = <<<HTML
<div class="support-unified-container">
    <div class="support-tabs">
        <button id="support-tab-dialog" class="support-tab active" data-panel="dialog">
            {$this->msg('supportsystem-tab-dialog')->escaped()}
        </button>
        <button id="support-tab-search" class="support-tab" data-panel="search">
            {$this->msg('supportsystem-tab-search')->escaped()}
        </button>
        <button id="support-tab-tickets" class="support-tab" data-panel="tickets">
            {$this->msg('supportsystem-tab-tickets')->escaped()}
        </button>
    </div>
    
    <div class="support-panels">
        <div id="support-panel-dialog" class="support-panel active">
            {$this->createDialogPanel()}
        </div>
        
        <div id="support-panel-search" class="support-panel">
            {$this->createSearchPanel()}
        </div>
        
        <div id="support-panel-tickets" class="support-panel">
            {$this->createTicketsPanel()}
        </div>
    </div>

    <div id="support-ticket-form" class="support-form-overlay" style="display: none;">
        <div class="support-form-container">
            <div class="support-form-header">
                <h3>{$this->msg('supportsystem-dt-ticket-header')->escaped()}</h3>
                <button id="support-ticket-close" class="support-close-button">&times;</button>
            </div>
            <div id="support-solution-display" class="support-solution-display" style="display: none;">
                <h4>{$this->msg('supportsystem-dt-solution-header')->escaped()}</h4>
                <p id="support-solution-text"></p>
                <p id="support-solution-source" class="support-source"></p>
            </div>
            <form id="support-ticket-form-element">
                <div class="support-form-group">
                    <label for="support-ticket-subject">{$this->msg('supportsystem-dt-ticket-subject')->escaped()}</label>
                    <input type="text" id="support-ticket-subject" class="support-input" required>
                </div>
                <div class="support-form-group">
                    <label for="support-ticket-description">{$this->msg('supportsystem-dt-ticket-description')->escaped()}</label>
                    <textarea id="support-ticket-description" class="support-textarea" rows="5" required></textarea>
                </div>
                <div class="support-form-group">
                    <label for="support-ticket-priority">{$this->msg('supportsystem-dt-ticket-priority')->escaped()}</label>
                    <select id="support-ticket-priority" class="support-select">
                        <option value="low">{$this->msg('supportsystem-dt-priority-low')->escaped()}</option>
                        <option value="normal" selected>{$this->msg('supportsystem-dt-priority-normal')->escaped()}</option>
                        <option value="high">{$this->msg('supportsystem-dt-priority-high')->escaped()}</option>
                        <option value="urgent">{$this->msg('supportsystem-dt-priority-urgent')->escaped()}</option>
                    </select>
                </div>
                <div class="support-form-actions">
                    <button type="button" id="support-ticket-cancel" class="support-button-secondary">
                        {$this->msg('supportsystem-dt-cancel')->escaped()}
                    </button>
                    <button type="submit" id="support-ticket-submit" class="support-button-primary">
                        {$this->msg('supportsystem-dt-submit')->escaped()}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
HTML;

        return $html;
    }

    /**
     * Create HTML for the dialog panel
     * @return string HTML
     */
    private function createDialogPanel(): string
    {
        $html = <<<HTML
<div class="support-dialog-container">
    <div class="support-panel-header">
        <h3>{$this->msg('supportsystem-dialog-title')->escaped()}</h3>
        <p>{$this->msg('supportsystem-dialog-desc')->escaped()}</p>
    </div>
    
    <div class="support-chat-container" id="support-chat-container">
        <div class="support-welcome-message">
            <p class="support-welcome-text">
                {$this->msg('supportsystem-dt-welcome')->escaped()}
            </p>
        </div>
    </div>
    
    <div class="support-options-container" id="support-options-container">
        <button id="support-start-button" class="support-button-primary">
            {$this->msg('supportsystem-dt-start')->escaped()}
        </button>
    </div>
    
    <div class="support-solution-container" id="support-solution-container" style="display: none;">
        <div class="support-solution-box">
            <h4>{$this->msg('supportsystem-dt-solution-header')->escaped()}</h4>
            <p id="support-solution-text"></p>
        </div>
        
        <div class="support-solution-actions">
            <button id="support-restart-button" class="support-button-secondary">
                {$this->msg('supportsystem-dt-restart')->escaped()}
            </button>
            <button id="support-create-ticket-button" class="support-button-primary">
                {$this->msg('supportsystem-dt-create-ticket')->escaped()}
            </button>
            <button id="support-ai-search-button" class="support-button-accent">
                {$this->msg('supportsystem-dt-ai-search')->escaped()}
            </button>
            <button id="support-wiki-search-button" class="support-button-accent">
                {$this->msg('supportsystem-dt-wiki-search')->escaped()}
            </button>
        </div>
    </div>
    
    <div class="support-ai-container" id="support-ai-container" style="display: none;">
        <div class="support-ai-box">
            <h4>{$this->msg('supportsystem-dt-ai-header')->escaped()}</h4>
            <div id="support-ai-loading" class="support-loading">
                <div class="support-spinner"></div>
                <p>{$this->msg('supportsystem-dt-ai-loading')->escaped()}</p>
            </div>
            <div id="support-ai-content" style="display: none;">
                <p id="support-ai-text"></p>
                <div id="support-ai-sources" class="support-ai-sources" style="display: none;">
                    <h5>{$this->msg('supportsystem-dt-ai-sources')->escaped()}</h5>
                    <ul id="support-ai-sources-list"></ul>
                </div>
            </div>
        </div>
        
        <div class="support-ai-actions">
            <button id="support-ai-accept-button" class="support-button-primary">
                {$this->msg('supportsystem-dt-ai-accept')->escaped()}
            </button>
            <button id="support-ai-ticket-button" class="support-button-primary">
                {$this->msg('supportsystem-dt-create-ticket')->escaped()}
            </button>
            <button id="support-ai-back-button" class="support-button-secondary">
                {$this->msg('supportsystem-dt-back')->escaped()}
            </button>
        </div>
    </div>
</div>
HTML;

        return $html;
    }

    /**
     * Create HTML for the search panel
     * @return string HTML
     */
    private function createSearchPanel(): string
    {
        $html = <<<HTML
<div class="support-search-container">
    <div class="support-panel-header">
        <h3>{$this->msg('supportsystem-search-title')->escaped()}</h3>
        <p>{$this->msg('supportsystem-search-desc')->escaped()}</p>
    </div>
    
    <div class="support-search-box">
        <input type="text" id="support-search-input" class="support-search-input" 
            placeholder="{$this->msg('supportsystem-search-placeholder')->escaped()}">
        <button id="support-search-button" class="support-button-primary">
            {$this->msg('supportsystem-search-button')->escaped()}
        </button>
    </div>
    
    <div class="support-search-options">
        <label>
            <input type="checkbox" id="support-search-use-ai" class="support-checkbox">
            {$this->msg('supportsystem-search-use-ai')->escaped()}
        </label>
    </div>
    
    <div id="support-search-results" class="support-search-results">
        <p class="support-search-initial-message">
            {$this->msg('supportsystem-search-initial')->escaped()}
        </p>
    </div>
</div>
HTML;

        return $html;
    }

    /**
     * Create HTML for the tickets panel
     * @return string HTML
     */
    private function createTicketsPanel(): string
    {
        $html = <<<HTML
<div class="support-tickets-container">
    <div class="support-panel-header">
        <h3>{$this->msg('supportsystem-tickets-title')->escaped()}</h3>
        <p>{$this->msg('supportsystem-tickets-desc')->escaped()}</p>
        <button id="support-tickets-create" class="support-button-primary">
            {$this->msg('supportsystem-sd-new')->escaped()}
        </button>
    </div>
    
    <div id="support-tickets-list" class="support-tickets-list">
        <div class="support-loading">
            <div class="support-spinner"></div>
            <p>{$this->msg('supportsystem-sd-loading')->escaped()}</p>
        </div>
    </div>
    
    <div id="support-ticket-details" class <div id="support-ticket-details" class="support-ticket-details" style="display: none;">
        <div class="support-ticket-details-header">
            <h3 id="support-ticket-details-title"></h3>
            <button id="support-ticket-details-back" class="support-button-secondary">
                {$this->msg('supportsystem-sd-ticket-back')->escaped()}
            </button>
        </div>
        
        <div class="support-ticket-details-info">
            <div class="support-ticket-status">
                <span class="support-label">{$this->msg('supportsystem-sd-ticket-status')->escaped()}:</span>
                <span id="support-ticket-status"></span>
            </div>
            <div class="support-ticket-priority">
                <span class="support-label">{$this->msg('supportsystem-sd-ticket-priority')->escaped()}:</span>
                <span id="support-ticket-priority-value"></span>
            </div>
            <div class="support-ticket-created">
                <span class="support-label">{$this->msg('supportsystem-sd-ticket-created')->escaped()}:</span>
                <span id="support-ticket-created-date"></span>
            </div>
        </div>
        
        <div class="support-ticket-description-section">
            <h4>{$this->msg('supportsystem-sd-ticket-description')->escaped()}</h4>
            <div id="support-ticket-description-text" class="support-ticket-description-content"></div>
        </div>
        
        <div class="support-ticket-comments-section">
            <h4>{$this->msg('supportsystem-sd-ticket-comments')->escaped()}</h4>
            <div id="support-ticket-comments" class="support-ticket-comments-list"></div>
            
            <div class="support-comment-form">
                <h5>{$this->msg('supportsystem-sd-ticket-add-comment')->escaped()}</h5>
                <textarea id="support-comment-text" class="support-textarea" 
                    placeholder="{$this->msg('supportsystem-sd-ticket-comment-placeholder')->escaped()}"></textarea>
                <button id="support-comment-submit" class="support-button-primary">
                    {$this->msg('supportsystem-sd-ticket-comment-submit')->escaped()}
                </button>
            </div>
        </div>
    </div>
</div>
HTML;

        return $html;
    }

    private function createTicketForm(): string
    {
        $html = <<<HTML
<div id="support-ticket-form" class="support-form-overlay" style="display: none;">
    <div class="support-form-container">
        <div class="support-form-header">
            <h3>{$this->msg('supportsystem-dt-ticket-header')->escaped()}</h3>
            <button id="support-ticket-close" class="support-close-button">&times;</button>
        </div>
        <div id="support-solution-display" class="support-solution-display" style="display: none;">
            <h4>{$this->msg('supportsystem-dt-solution-header')->escaped()}</h4>
            <p id="support-solution-text"></p>
            <p id="support-solution-source" class="support-source"></p>
        </div>
        <form id="support-ticket-form-element">
            <div class="support-form-group">
                <label id="support-ticket-subject-label" for="support-ticket-subject">{$this->msg('supportsystem-dt-ticket-subject')->escaped()}</label>
                <input type="text" id="support-ticket-subject" class="support-input" required>
            </div>
            <div class="support-form-group">
                <label id="support-ticket-description-label" for="support-ticket-description">{$this->msg('supportsystem-dt-ticket-description')->escaped()}</label>
                <textarea id="support-ticket-description" class="support-textarea" rows="5" required></textarea>
            </div>
            <div class="support-form-group">
                <label id="support-ticket-priority-label" for="support-ticket-priority">{$this->msg('supportsystem-dt-ticket-priority')->escaped()}</label>
                <select id="support-ticket-priority" class="support-select">
                    <option value="low">{$this->msg('supportsystem-dt-priority-low')->escaped()}</option>
                    <option value="normal" selected>{$this->msg('supportsystem-dt-priority-normal')->escaped()}</option>
                    <option value="high">{$this->msg('supportsystem-dt-priority-high')->escaped()}</option>
                    <option value="urgent">{$this->msg('supportsystem-dt-priority-urgent')->escaped()}</option>
                </select>
            </div>
            <div class="support-form-actions">
                <button type="button" id="support-ticket-cancel" class="support-button-secondary">
                    {$this->msg('supportsystem-dt-cancel')->escaped()}
                </button>
                <button type="submit" id="support-ticket-submit" class="support-button-primary">
                    {$this->msg('supportsystem-dt-submit')->escaped()}
                </button>
            </div>
        </form>
    </div>
</div>
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