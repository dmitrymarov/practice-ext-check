<?php

namespace MediaWiki\Extension\SupportSystem\Special;

use SpecialPage;
use HTMLForm;

/**
 * Special page for ticket management (Service Desk)
 */
class SpecialServiceDesk extends SpecialPage
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct('ServiceDesk');
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
        $out->setPageTitle($this->msg('supportsystem-sd-title'));

        // Add description
        $out->addWikiTextAsInterface($this->msg('supportsystem-sd-desc')->text());

        // Create the service desk interface
        $out->addHTML($this->createServiceDeskInterface());
    }

    /**
     * Create HTML for the service desk interface
     * @return string HTML
     */
    private function createServiceDeskInterface(): string
    {
        $html = <<<HTML
<div class="support-sd-container">
    <div class="support-sd-actions">
        <button id="support-sd-new-button" class="support-sd-new-button">
            {$this->msg('supportsystem-sd-new')->escaped()}
        </button>
    </div>
    
    <div id="support-sd-ticket-list" class="support-sd-ticket-list">
        <h3>{$this->msg('supportsystem-sd-list')->escaped()}</h3>
        <div class="support-sd-notification">
            <p>{$this->msg('supportsystem-sd-notification')->escaped()}</p>
        </div>
        <div id="support-sd-tickets-container" class="support-sd-tickets-container">
            <div class="support-sd-loading">
                {$this->msg('supportsystem-sd-loading')->escaped()}
            </div>
        </div>
    </div>
    
    <div id="support-sd-ticket-form" class="support-sd-ticket-form support-hidden">
        <h3>{$this->msg('supportsystem-sd-ticket-new')->escaped()}</h3>
        <div class="support-form-group">
            <label for="support-sd-ticket-subject">
                {$this->msg('supportsystem-sd-ticket-subject')->escaped()}
            </label>
            <input type="text" id="support-sd-ticket-subject" class="support-input" required>
        </div>
        
        <div class="support-form-group">
            <label for="support-sd-ticket-description">
                {$this->msg('supportsystem-sd-ticket-description')->escaped()}
            </label>
            <textarea id="support-sd-ticket-description" class="support-textarea" rows="4" required></textarea>
        </div>
        
        <div class="support-form-group">
            <label for="support-sd-ticket-priority">
                {$this->msg('supportsystem-sd-ticket-priority')->escaped()}
            </label>
            <select id="support-sd-ticket-priority" class="support-select">
                <option value="low">{$this->msg('supportsystem-dt-priority-low')->escaped()}</option>
                <option value="normal" selected>{$this->msg('supportsystem-dt-priority-normal')->escaped()}</option>
                <option value="high">{$this->msg('supportsystem-dt-priority-high')->escaped()}</option>
                <option value="urgent">{$this->msg('supportsystem-dt-priority-urgent')->escaped()}</option>
            </select>
        </div>
        
        <div class="support-form-actions">
            <button type="button" id="support-sd-cancel-ticket" class="support-cancel-button">
                {$this->msg('supportsystem-dt-cancel')->escaped()}
            </button>
            <button type="button" id="support-sd-submit-ticket" class="support-submit-button">
                {$this->msg('supportsystem-dt-submit')->escaped()}
            </button>
        </div>
    </div>
    
    <div id="support-sd-ticket-details" class="support-sd-ticket-details support-hidden">
        <div class="support-sd-details-header">
            <h3 id="support-sd-details-subject"></h3>
            <button id="support-sd-back-button" class="support-sd-back-button">
                {$this->msg('supportsystem-sd-ticket-back')->escaped()}
            </button>
        </div>
        
        <div class="support-sd-details-meta">
            <span id="support-sd-details-status" class="support-sd-status-badge"></span>
            <span id="support-sd-details-priority" class="support-sd-priority-badge"></span>
            <span id="support-sd-details-date" class="support-sd-date"></span>
        </div>
        
        <div class="support-sd-details-body">
            <h4>{$this->msg('supportsystem-sd-ticket-description')->escaped()}</h4>
            <div id="support-sd-details-description" class="support-sd-description"></div>
        </div>
        
        <div class="support-sd-comments-section">
            <h4>{$this->msg('supportsystem-sd-ticket-comments')->escaped()}</h4>
            <div id="support-sd-comments" class="support-sd-comments"></div>
            
            <div class="support-sd-add-comment">
                <h4>{$this->msg('supportsystem-sd-ticket-add-comment')->escaped()}</h4>
                <div class="support-form-group">
                    <textarea id="support-sd-new-comment" class="support-textarea" rows="3" 
                        placeholder="{$this->msg('supportsystem-sd-ticket-comment-placeholder')->escaped()}"></textarea>
                </div>
                <button id="support-sd-add-comment-button" class="support-submit-button">
                    {$this->msg('supportsystem-sd-ticket-comment-submit')->escaped()}
                </button>
            </div>
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