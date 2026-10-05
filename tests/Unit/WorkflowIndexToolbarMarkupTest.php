<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WorkflowIndexToolbarMarkupTest extends TestCase
{
    public function test_editor_combines_identity_actions_and_kpis_with_one_responsive_overview_surface(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/resources/views/livewire/admin/network/workflow-manager.blade.php');
        $start = strpos($view, '<header class="ff-workflow-overview__header"');
        $header = substr($view, $start, strpos($view, '</header>', $start) - $start);
        $this->assertSame(1, substr_count($view, 'data-workflow-overview-card'));
        $this->assertSame(1, substr_count($header, '<x-ui.dropdown '));
        $this->assertStringContainsString('id="workflow-manager-title" title="{{ $selectedWorkflow?->name }}"', $header);
        $this->assertStringNotContainsString('class="sr-only"', $header);
        $this->assertStringContainsString('aria-label="Workflow-Aktionen"', $header);
        $this->assertStringContainsString('role="menuitem"', $header);
        $this->assertStringNotContainsString('Workflow-Karte</p>', $view);
        $this->assertStringNotContainsString('Ablauf auf einen Blick', $view);
        $this->assertStringNotContainsString('Task-Routen per Hover ansehen.', $view);
        $this->assertLessThan(strpos($header, 'ff-workflow-overview__actions'), strpos($header, 'ff-workflow-back'));
        $this->assertLessThan(strpos($header, 'ff-workflow-overview__stats'), strpos($header, 'ff-workflow-overview__actions'));
        $this->assertStringNotContainsString('ff-page-copy', $header);
        $this->assertStringNotContainsString('ff-metric', $header);
        foreach (['actions', 'lists', 'task_cards', 'runs', 'successful_runs', 'failed_runs'] as $key) {
            $this->assertStringContainsString("\$summary['{$key}']", $header);
        }
        foreach (['Workflow gesperrt', 'lock_reason', 'openTestWorkbench', 'openDefinitionWorkbench', 'openRevisionHistory', 'exportWorkflow', 'downloadLatestRunDebugPackage', 'showWorkflowModal', 'showCopilotRunsModal', 'showActionLibraryModal', 'network.workflows', '@if(! $workflowLocked)'] as $action) {
            $this->assertStringContainsString($action, $header);
        }
    }

    public function test_overview_canvas_uses_the_available_viewport_height_without_affecting_other_minimaps(): void
    {
        $styles = file_get_contents(dirname(__DIR__, 2).'/resources/css/workflow-experience.css');
        $this->assertStringContainsString('grid-template-columns: 44px minmax(0, 1fr) auto;', $styles);
        $this->assertStringContainsString('height: calc(100dvh - var(--ff-shell-topbar-height, 70px) - var(--ff-overview-page-space) - 1rem);', $styles);
        $this->assertStringContainsString('.ff-workflow-overview__map [data-workflow-minimap-scroll-container] { height: 100%; overflow: auto !important; }', $styles);
        $this->assertStringContainsString('.ff-workflow-overview__stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));', $styles);
        $this->assertStringNotContainsString('ff-workflow-manager-bar', $styles);
    }

    public function test_index_header_is_an_inline_stat_bar_with_existing_actions(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/livewire/admin/network/workflows-index.blade.php');
        $header = substr($view, 0, strpos($view, '</section>'));

        $this->assertStringContainsString('data-workflow-index-toolbar', $header);
        $this->assertStringContainsString('class="sr-only">Workflows</h1>', $header);
        $this->assertStringNotContainsString('Automation Workspace', $header);
        $this->assertStringNotContainsString('ff-metric', $header);
        foreach (['workflows', 'active_workflows', 'lists', 'task_cards'] as $key) {
            $this->assertStringContainsString("\$summary['{$key}']", $header);
        }
        foreach (['showCreateWorkflowModal', 'showImportWorkflowModal', 'showCopilotRunsModal', 'network.actions', 'network.portal-profiles', 'operations.dashboard', 'processes.index'] as $action) {
            $this->assertStringContainsString($action, $header);
        }
        $this->assertLessThan(strpos($header, 'ff-workflow-index-bar__actions'), strpos($header, 'ff-workflow-index-bar__stats'));
        $this->assertStringContainsString('ff-command-surface overflow-visible', $header);
    }
}
