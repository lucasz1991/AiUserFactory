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

        $this->assertStringContainsString('wire:click="openCopilotOptimization"', $view);
        $this->assertStringContainsString('Mit Copilot optimieren', $view);
        $this->assertStringContainsString('Ausschliesslich System-Ausfuehrung', $view);
        $this->assertStringContainsString('System-Optimierung starten', $view);
        $this->assertStringContainsString('wire:poll.2s="refreshCopilotSession"', $view);
        $this->assertStringContainsString('Bereinigte DOM-Elementkarte', $view);
        $this->assertStringContainsString('Workflow-Revisionen', $view);
        $this->assertStringContainsString('Zum Checkpoint zurueckspulen', $view);
        $this->assertStringContainsString('wire:click="openCopilotChat"', $view);
        $this->assertStringContainsString('Autonome Aktionen sind freigegeben', $view);
        $this->assertStringContainsString('@disabled(! $copilotAutoExecute)', $view);
        $this->assertStringNotContainsString('copilotExecutionTarget', $view);
        $this->assertStringNotContainsString('copilotNetworkNode', $view);
        $this->assertStringContainsString("'execution_target' => 'system'", $component);
        $this->assertStringNotContainsString("'execution_target' => \$validated['copilot", $component);
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
        $this->assertStringContainsString('assistantCopilotAutoExecute', $view);
        $this->assertStringContainsString('nie Client', $view);
        $this->assertStringContainsString("'vision_fallback_models'", $component);
        $this->assertStringContainsString("'optimization_defaults'", $component);
        $this->assertStringContainsString("'auto_execute_workflow_actions'", $component);
    }
}
