<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WorkflowIndexToolbarMarkupTest extends TestCase
{
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
