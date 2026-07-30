<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WorkflowDomInspectorMarkupTest extends TestCase
{
    public function test_shared_inspector_exposes_search_multi_selection_click_copy_and_poll_persistence(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio/dom-inspector.blade.php');
        $javascript = file_get_contents($root.'/resources/js/components/workflow-dom-inspector.js');
        $toolModal = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio/tool-modal.blade.php');
        $runPreview = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-run-preview.blade.php');

        $this->assertStringContainsString('workflow-studio.dom-inspector', $toolModal);
        $this->assertStringContainsString('workflow-studio.dom-inspector', $runPreview);
        $this->assertStringContainsString('data-workflow-dom-search', $view);
        $this->assertStringContainsString('data-workflow-screenshot-picker', $view);
        $this->assertStringContainsString('data-workflow-dom-match-overlay', $view);
        $this->assertStringContainsString('data-workflow-dom-row', $view);
        $this->assertStringContainsString('data-workflow-selector-suggestions', $view);
        $this->assertStringContainsString('x-show.important="query && !searchError && matchedRefs.length === 0"', $view);
        $this->assertStringContainsString('x-bind:disabled="!selectedNodeProbeable()"', $view);
        $this->assertStringContainsString('Body-DOM', $view);
        $this->assertStringContainsString('Selektor-Vorschläge', $view);
        $this->assertStringNotContainsString('workflow-dom-node-highlight', $view.$javascript);

        $this->assertStringContainsString('bodyOnlyNodes', $javascript);
        $this->assertStringContainsString('querySelectorAll', $javascript);
        $this->assertStringContainsString('matchedRefs', $javascript);
        $this->assertStringContainsString('matchNumber(node)', $view);
        $this->assertStringContainsString('childrenByParent', $javascript);
        $this->assertStringContainsString('snapshotTruncated', $javascript);
        $this->assertStringContainsString('selectFromScreenshot', $javascript);
        $this->assertStringContainsString('scrollSelectedIntoView', $javascript);
        $this->assertStringContainsString('navigator.clipboard.writeText', $javascript);
        $this->assertStringContainsString('window.sessionStorage', $javascript);
        $this->assertStringContainsString('selectorCandidates', $javascript);
        $this->assertStringNotContainsString('highlight: true', $javascript);

        $this->assertStringContainsString("'canProbe' => ! \$autonomousMode && \$isPaused", $toolModal);
        $this->assertStringContainsString("'canProbe' => \$selectableTasks && (string) \$workflowRun->status === 'paused'", $runPreview);
        $this->assertStringContainsString('data-workflow-browser-tool', $toolModal);
        $this->assertStringNotContainsString('<div class="grid gap-4 lg:grid-cols-2">', $toolModal);
    }
}
