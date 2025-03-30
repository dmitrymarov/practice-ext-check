<?php

namespace MediaWiki\Extension\SupportSystem\Special;

use SpecialPage;
use HTMLForm;

/**
 * Special page for the decision tree dialog
 */
class SpecialDecisionTree extends SpecialPage
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct('DecisionTree');
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
        $out->setPageTitle($this->msg('supportsystem-dt-title'));

        // Add description
        $out->addWikiTextAsInterface($this->msg('supportsystem-dt-desc')->text());

        // Create the dialog container
        $out->addHTML($this->createDialogContainer());
    }

    /**
     * Create HTML for the dialog container
     * @return string HTML
     */
    private function createDialogContainer(): string
    {
        $html = <<<HTML
<div class="support-dt-container">
    <div class="support-dt-chat-container" id="support-dt-chat-container">
        <div class="support-dt-welcome-message">
            <p class="support-dt-welcome-text">
                {$this->msg('supportsystem-dt-welcome')->escaped()}
            </p>
        </div>
    </div>
    
    <div class="support-dt-options-container" id="support-dt-options-container">
        <button id="support-dt-start-button" class="support-dt-start-button">
            {$this->msg('supportsystem-dt-start')->escaped()}
        </button>
    </div>
    
    <div class="support-dt-solution-container" id="support-dt-solution-container" style="display: none;">
        <div class="support-dt-solution-box">
            <h4>{$this->msg('supportsystem-dt-solution-header')->escaped()}</h4>
            <p id="support-dt-solution-text"></p>
        </div>
        
        <div class="support-dt-solution-actions">
            <button id="support-dt-restart-button" class="support-dt-restart-button">
                {$this->msg('supportsystem-dt-restart')->escaped()}
            </button>
            <button id="support-dt-ticket-button" class="support-dt-ticket-button">
                {$this->msg('supportsystem-dt-create-ticket')->escaped()}
            </button>
            <button id="support-dt-ai-button" class="support-dt-ai-button">
                {$this->msg('supportsystem-dt-ai-search')->escaped()}
            </button>
        </div>
    </div>
    
    <div class="support-dt-ai-container" id="support-dt-ai-container" style="display: none;">
        <div class="support-dt-ai-box">
            <h4>{$this->msg('supportsystem-dt-ai-header')->escaped()}</h4>
            <p id="support-dt-ai-text"></p>
            <div id="support-dt-ai-sources" class="support-dt-ai-sources" style="display: none;">
                <h5>{$this->msg('supportsystem-dt-ai-sources')->escaped()}</h5>
                <ul id="support-dt-ai-sources-list"></ul>
            </div>
        </div>
        
        <div class="support-dt-ai-actions">
            <button id="support-dt-ai-accept-button" class="support-dt-ai-accept-button">
                {$this->msg('supportsystem-dt-ai-accept')->escaped()}
            </button>
            <button id="support-dt-ai-ticket-button" class="support-dt-ai-ticket-button">
                {$this->msg('supportsystem-dt-create-ticket')->escaped()}
            </button>
        </div>
    </div>
    
    <div class="support-dt-ticket-form" id="support-dt-ticket-form" style="display: none;">
        <h4>{$this->msg('supportsystem-dt-ticket-header')->escaped()}</h4>
        <form id="support-dt-ticket-submit-form">
            <div class="support-dt-form-group">
                <label for="support-dt-ticket-subject">
                    {$this->msg('supportsystem-dt-ticket-subject')->escaped()}
                </label>
                <input type="text" id="support-dt-ticket-subject" class="support-dt-input" required>
            </div>
            
            <div class="support-dt-form-group">
                <label for="support-dt-ticket-description">
                    {$this->msg('supportsystem-dt-ticket-description')->escaped()}
                </label>
                <textarea id="support-dt-ticket-description" class="support-dt-textarea" rows="4" required></textarea>
            </div>
            
            <div class="support-dt-form-group">
                <label for="support-dt-ticket-priority">
                    {$this->msg('supportsystem-dt-ticket-priority')->escaped()}
                </label>
                <select id="support-dt-ticket-priority" class="support-dt-select">
                    <option value="low">{$this->msg('supportsystem-dt-priority-low')->escaped()}</option>
                    <option value="normal" selected>{$this->msg('supportsystem-dt-priority-normal')->escaped()}</option>
                    <option value="high">{$this->msg('supportsystem-dt-priority-high')->escaped()}</option>
                    <option value="urgent">{$this->msg('supportsystem-dt-priority-urgent')->escaped()}</option>
                </select>
            </div>
            
            <div class="support-dt-form-actions">
                <button type="button" id="support-dt-ticket-cancel" class="support-dt-cancel-button">
                    {$this->msg('supportsystem-dt-cancel')->escaped()}
                </button>
                <button type="submit" id="support-dt-ticket-submit" class="support-dt-submit-button">
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