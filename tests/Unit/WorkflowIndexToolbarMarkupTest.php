<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WorkflowIndexToolbarMarkupTest extends TestCase
{
    public function test_editor_reuses_the_compact_bar_and_preserves_actions_and_lock_state(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/resources/views/livewire/admin/network/workflow-manager.blade.php');
        $start = strpos($view, '<section class="ff-command-surface');
        $header = substr($view, $start, strpos($view, '</section>', $start) - $start);
        $this->assertStringContainsString('ff-workflow-index-bar ff-workflow-manager-bar', $header);
        $this->assertStringContainsString('id="workflow-manager-title" class="sr-only"', $header);
        $this->assertStringNotContainsString('ff-page-copy', $header);
        $this->assertStringNotContainsString('ff-metric', $header);
        foreach (['actions', 'lists', 'task_cards', 'runs', 'successful_runs', 'failed_runs'] as $key) {
            $this->assertStringContainsString("\$summary['{$key}']", $header);
        }
        foreach (['Workflow gesperrt', 'lock_reason', 'openTestWorkbench', 'openDefinitionWorkbench', 'openRevisionHistory', 'exportWorkflow', 'network.workflows', '@if(! $workflowLocked)'] as $action) {
            $this->assertStringContainsString($action, $header);
        }
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
