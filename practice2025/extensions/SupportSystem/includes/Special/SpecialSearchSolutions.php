<?php

namespace MediaWiki\Extension\SupportSystem\Special;

use SpecialPage;
use HTMLForm;

/**
 * Special page for searching solutions
 */
class SpecialSearchSolutions extends SpecialPage
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct('SearchSolutions');
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
        $out->setPageTitle($this->msg('supportsystem-search-title'));

        // Add description
        $out->addWikiTextAsInterface($this->msg('supportsystem-search-desc')->text());

        // Create the search interface
        $out->addHTML($this->createSearchInterface());
    }

    /**
     * Create HTML for the search interface
     * @return string HTML
     */
    private function createSearchInterface(): string
    {
        $html = <<<HTML
<div class="support-search-container">
    <div class="support-search-box">
        <input type="text" id="support-search-input" class="support-search-input" 
            placeholder="{$this->msg('supportsystem-search-placeholder')->escaped()}">
        <button id="support-search-button" class="support-search-button">
            {$this->msg('supportsystem-search-button')->escaped()}
        </button>
    </div>
    
    <div class="support-search-filters">
        <div class="support-search-filter-item">
            <input type="checkbox" id="support-filter-all" checked>
            <label for="support-filter-all">{$this->msg('supportsystem-search-source-all')->escaped()}</label>
        </div>
        <div class="support-search-filter-item">
            <input type="checkbox" id="support-filter-opensearch" class="support-source-filter" checked>
            <label for="support-filter-opensearch">{$this->msg('supportsystem-search-source-opensearch')->escaped()}</label>
        </div>
        <div class="support-search-filter-item">
            <input type="checkbox" id="support-filter-mediawiki" class="support-source-filter" checked>
            <label for="support-filter-mediawiki">{$this->msg('supportsystem-search-source-mediawiki')->escaped()}</label>
        </div>
    </div>
    
    <div id="support-search-results" class="support-search-results">
        <p class="support-search-initial-message">
            {$this->msg('supportsystem-search-initial')->escaped()}
        </p>
    </div>
    
    <div id="support-ticket-form" class="support-ticket-form support-hidden">
        <h3>{$this->msg('supportsystem-dt-ticket-header')->escaped()}</h3>
        
        <div id="support-selected-solution" class="support-selected-solution support-hidden">
            <h4>{$this->msg('supportsystem-dt-solution-header')->escaped()}</h4>
            <p id="support-solution-content"></p>
            <p id="support-solution-source" class="support-solution-source"></p>
        </div>
        
        <div class="support-form-group">
            <label for="support-ticket-subject">
                {$this->msg('supportsystem-dt-ticket-subject')->escaped()}
            </label>
            <input type="text" id="support-ticket-subject" class="support-input" required>
        </div>
        
        <div class="support-form-group">
            <label for="support-ticket-description">
                {$this->msg('supportsystem-dt-ticket-description')->escaped()}
            </label>
            <textarea id="support-ticket-description" class="support-textarea" rows="4" required></textarea>
        </div>
        
        <div class="support-form-group">
            <label for="support-ticket-priority">
                {$this->msg('supportsystem-dt-ticket-priority')->escaped()}
            </label>
            <select id="support-ticket-priority" class="support-select">
                <option value="low">{$this->msg('supportsystem-dt-priority-low')->escaped()}</option>
                <option value="normal" selected>{$this->msg('supportsystem-dt-priority-normal')->escaped()}</option>
                <option value="high">{$this->msg('supportsystem-dt-priority-high')->escaped()}</option>
                <option value="urgent">{$this->msg('supportsystem-dt-priority-urgent')->escaped()}</option>
            </select>
        </div>
        
        <div class="support-form-actions">
            <button type="button" id="support-cancel-ticket" class="support-cancel-button">
                {$this->msg('supportsystem-dt-cancel')->escaped()}
            </button>
            <button type="button" id="support-submit-ticket" class="support-submit-button">
                {$this->msg('supportsystem-dt-submit')->escaped()}
            </button>
        </div>
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