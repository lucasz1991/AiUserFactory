<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WorkflowCopilotUiMarkupTest extends TestCase
{
    public function test_workflow_manager_exposes_system_only_copilot_start_and_live_controls(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-manager.blade.php');
        $component = file_get_contents($root.'/app/Livewire/Admin/Network/WorkflowManager.php');

        $this->assertStringContainsString("route('network.workflows.studio'", $view);
        $this->assertStringContainsString('Mit Copilot im Studio optimieren', $view);
        $this->assertStringContainsString('Ausschliesslich System-Ausfuehrung', $view);
        $this->assertStringContainsString('System-Optimierung starten', $view);
        $this->assertStringContainsString('wire:poll.2s="refreshRunPreview"', $view);
        $this->assertStringContainsString('<x-workflows.run-preview :workflow-run="$previewWorkflowRun" />', $view);
        $this->assertStringContainsString('wire:model="showRunPreviewModal"', $view);
        $this->assertStringNotContainsString('wire:model="showCopilotPreviewModal"', $view);
        $this->assertStringContainsString('Bereinigte DOM-Elementkarte', $view);
        $this->assertStringContainsString('Workflow-Revisionen', $view);
        $this->assertStringContainsString('Zum Checkpoint zurueckspulen', $view);
        $this->assertStringContainsString('wire:click="openCopilotChat"', $view);
        $this->assertStringContainsString('wire:click="restartCopilotOptimization"', $view);
        $this->assertStringContainsString('Normalen Testdurchlauf starten', $view);
        $this->assertStringContainsString('wire:click="terminateCopilotOptimization"', $view);
        $this->assertStringContainsString('wire:click="terminatePreviewWorkflowRun"', $view);
        $this->assertStringContainsString('wire:click="downloadCopilotOptimizationLog"', $view);
        $this->assertStringContainsString('wire:model="showCopilotRunsModal"', $view);
        $this->assertStringContainsString('admin.network.workflow-copilot-runs', $view);
        $this->assertStringContainsString(':interactive-aside="true"', $view);
        $this->assertSame(4, substr_count($view, ':interactive-aside="true"'));
        $this->assertStringContainsString('data-workflow-copilot-completed-state', $view);
        $this->assertStringContainsString('data-workflow-copilot-vision-analysis', $view);
        $this->assertStringContainsString('Vorgeschlagene Workflow-Aktionen', $view);
        $this->assertStringContainsString('Autonome Aktionen sind freigegeben', $view);
        $this->assertStringContainsString('@disabled(! $copilotAutoExecute)', $view);
        $this->assertStringNotContainsString('copilotExecutionTarget', $view);
        $this->assertStringNotContainsString('copilotNetworkNode', $view);
        $this->assertStringContainsString("'execution_target' => 'system'", $component);
        $this->assertStringContainsString('WorkflowCopilotLaunchService::class', $component);
        $this->assertStringContainsString('function restartCopilotOptimization()', $component);
        $this->assertStringNotContainsString("'execution_target' => \$validated['copilot", $component);
    }

    public function test_studio_and_docked_chat_expose_separate_graceful_stop_and_force_termination_controls(): void
    {
        $root = dirname(__DIR__, 2);
        $studio = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio.blade.php');
        $studioCopilot = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio/copilot.blade.php');
        $chat = file_get_contents($root.'/resources/views/livewire/tools/chatbot.blade.php');

        $this->assertStringContainsString('wire:click="stopRun"', $studio);
        $this->assertStringContainsString('wire:click="terminateRun"', $studio);
        $this->assertStringContainsString('wire:click="stopCopilot"', $studioCopilot);
        $this->assertStringContainsString('wire:click="terminateCopilot"', $studioCopilot);
        $this->assertStringContainsString('wire:click="stopCopilotSession"', $chat);
        $this->assertStringContainsString('wire:click="terminateCopilotSession"', $chat);
    }

    public function test_copilot_settings_expose_vision_fallback_order_and_default_budgets(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/livewire/admin/config/settings-page.blade.php');
        $component = file_get_contents($root.'/app/Livewire/Admin/Config/SettingsPage.php');

        $this->assertStringContainsString('Vision-Fallback-Modelle', $view);
        $this->assertStringContainsString('assistantVisionFallbackModels', $view);
        $this->assertStringContainsString('assistantCopilotMaxMinutes', $view);
        $this->assertStringContainsString('assistantCopilotMaxRepairIterations', $view);
        $this->assertStringContainsString('assistantCopilotMaxProbeActions', $view);
        $this->assertStringContainsString('assistantCopilotMaxSameStateRepeats', $view);
        $this->assertStringContainsString('assistantCopilotMaxCostUsd', $view);
        $this->assertStringContainsString('assistantCopilotPermissionMode', $view);
        $this->assertStringContainsString('Kritisch nachfragen', $view);
        $this->assertStringContainsString("'vision_fallback_models'", $component);
        $this->assertStringContainsString("'optimization_defaults'", $component);
        $this->assertStringContainsString("'max_cost_usd'", $component);
        $this->assertStringContainsString("'auto_execute_workflow_actions'", $component);
        $this->assertStringContainsString("'permission_mode'", $component);
    }
}
