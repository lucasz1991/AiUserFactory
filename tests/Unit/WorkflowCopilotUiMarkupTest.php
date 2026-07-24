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

        $this->assertStringContainsString("openTestWorkbench('interactive')", $view);
        $this->assertStringContainsString("openTestWorkbench('autonomous')", $view);
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
        $this->assertStringContainsString('Interaktiv testen', $view);
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

    public function test_interactive_studio_exposes_user_stop_while_autonomous_controls_stay_outside_the_modal(): void
    {
        $root = dirname(__DIR__, 2);
        $studio = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio.blade.php');
        $chat = file_get_contents($root.'/resources/views/livewire/tools/chatbot.blade.php');

        $this->assertStringContainsString('wire:click="stopRun"', $studio);
        $this->assertStringNotContainsString('wire:click="terminateRun"', $studio);
        $this->assertStringContainsString('Autonome Steuerung', $studio);
        $this->assertStringContainsString('Autonome Optimierung starten', file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio/copilot-rail.blade.php'));
        $this->assertStringContainsString('wire:click="stopCopilotSession"', $chat);
        $this->assertStringContainsString('wire:click="terminateCopilotSession"', $chat);
    }

    public function test_workflow_studio_uses_a_diagram_first_tool_layout_with_docked_chat_and_toasts(): void
    {
        $root = dirname(__DIR__, 2);
        $studio = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio.blade.php');
        $toolbar = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio/tool-bar.blade.php');
        $browserWindows = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio/browser-windows.blade.php');
        $browser = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio/browser.blade.php');
        $runPreview = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-run-preview.blade.php');
        $manager = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-manager.blade.php');
        $chat = file_get_contents($root.'/resources/views/livewire/tools/chatbot.blade.php');
        $javascript = file_get_contents($root.'/resources/js/app.js');

        foreach (['Browser', 'Selektoren', 'Daten', 'Checkpoints', 'Logs', 'Debug', 'Schritte', 'Tasks', 'Variablen', 'Artefakte', 'Copilot-Einstellungen'] as $label) {
            $this->assertStringContainsString($label, $toolbar);
        }
        $this->assertStringContainsString(':diagram-only="true"', $browser);
        $this->assertStringContainsString('background-size: 20px 20px, 20px 20px, 100px 100px, 100px 100px', $runPreview);
        $this->assertStringContainsString('h-20 w-36', $browserWindows);
        $this->assertStringContainsString('data-workflow-test-workbench', $manager);
        $this->assertStringContainsString('fixed inset-0 top-0', $manager);
        $this->assertStringContainsString('workflow-studio-pin-copilot', $studio);
        $this->assertStringContainsString('workflow-studio-unpin-copilot', $studio);
        $this->assertStringContainsString('[data-workflow-test-workbench]', $chat);
        $this->assertStringContainsString('right: 30rem !important', $chat);
        $this->assertStringContainsString('this.studioChatWasOpen = storedChatOpen', $chat);
        $this->assertStringContainsString('toast: true', $studio);
        $this->assertStringContainsString("position: 'top'", $studio);
        $this->assertStringContainsString('timerProgressBar: true', $studio);
        $this->assertStringContainsString("import Swal from 'sweetalert2'", $javascript);
        $this->assertStringContainsString("import 'sweetalert2/dist/sweetalert2.min.css'", $javascript);

        $domInspector = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio/dom-inspector.blade.php');
        $toolModal = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio/tool-modal.blade.php');
        $this->assertStringContainsString('workflow-studio.dom-inspector', $runPreview);
        $this->assertStringContainsString('workflow-studio.dom-inspector', $toolModal);
        $this->assertStringContainsString('data-workflow-dom-inspector', $domInspector);
        $this->assertStringContainsString('workflow-dom-node-selected', $domInspector);
        $this->assertStringContainsString('workflow-dom-node-highlight', $domInspector);
        $this->assertStringContainsString('overlayStyle(rect', $domInspector);
        $this->assertStringContainsString('cursorStyle()', $domInspector);
    }

    public function test_studio_overlays_use_standard_z_scale_and_toolbar_offers_person_context(): void
    {
        $root = dirname(__DIR__, 2);
        $studio = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio.blade.php');
        $toolModal = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio/tool-modal.blade.php');

        // Studio-Modale muessen auf der Standard-z-Skala liegen: Arbitrary-Klassen
        // wie z-[64] fehlen in aelteren bzw. browser-gecachten CSS-Builds, fallen
        // dann auf z-index:auto zurueck und geraten hinter die Diagramm-Tasks.
        $this->assertStringNotContainsString('z-[64]', $studio.$toolModal);
        $this->assertStringNotContainsString('z-[65]', $studio.$toolModal);
        $this->assertStringContainsString('z-40', $toolModal);
        $this->assertStringContainsString('relative isolate', $studio);

        // Die interaktive Toolbar muss die Personen-Auswahl fuer den Teststart anbieten.
        $this->assertStringContainsString('wire:model="personId"', $studio);
        $this->assertStringContainsString('Keine Person', $studio);
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
