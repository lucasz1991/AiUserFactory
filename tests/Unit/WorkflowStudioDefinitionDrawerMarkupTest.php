<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WorkflowStudioDefinitionDrawerMarkupTest extends TestCase
{
    public function test_studio_offers_a_builder_trigger_only_while_the_user_controls_the_session(): void
    {
        $studio = file_get_contents($this->viewPath('workflow-studio.blade.php'));

        $this->assertStringContainsString('wire:click="openDefinitionBuilder"', $studio);
        $this->assertStringContainsString('data-workflow-studio-builder-trigger', $studio);

        $triggerPosition = strpos($studio, 'data-workflow-studio-builder-trigger');
        $interactiveBlockStart = strpos($studio, '@if(! $autonomousMode)');
        // Der autonome Zweig beginnt mit diesem Hinweis; der Ausloeser muss davor liegen,
        // damit er im Copilot-Modus gar nicht erst gerendert wird.
        $autonomousBranch = strpos($studio, '<strong>Autonome Steuerung:</strong>');

        $this->assertIsInt($triggerPosition);
        $this->assertIsInt($interactiveBlockStart);
        $this->assertIsInt($autonomousBranch);
        $this->assertGreaterThan($interactiveBlockStart, $triggerPosition);
        $this->assertLessThan($autonomousBranch, $triggerPosition);
    }

    public function test_shared_editor_wraps_library_and_canvas_into_an_overlay_for_the_studio_only(): void
    {
        $source = file_get_contents($this->viewPath('partials/workflow-definition-editor.blade.php'));
        $wrapper = file_get_contents($this->viewPath('workflow-studio-task-editor.blade.php'));

        $this->assertStringContainsString('$definitionDrawerOpen = (bool) ($definitionDrawerOpen ?? false);', $source);
        $this->assertStringContainsString('$showDefinitionSurface = ! $modalOnly || $definitionDrawerOpen;', $source);
        $this->assertStringContainsString('data-studio-definition-drawer', $source);
        $this->assertStringContainsString('wire:click="closeDefinitionDrawer"', $source);
        $this->assertStringContainsString("'definitionDrawerOpen' => \$definitionDrawerOpen ?? false,", $wrapper);

        // Der Manager-Pfad darf keine Overlay-Huelle bekommen: die Drawer-Chrome haengt
        // ausschliesslich an $modalOnly, waehrend Bibliothek und Canvas geteilt bleiben.
        $this->assertStringNotContainsString('@unless($modalOnly)', $source);
        $this->assertSame(2, substr_count($source, '@if($modalOnly)'));
        $this->assertSame(3, substr_count($source, '@if($showDefinitionSurface)'));
        $this->assertStringContainsString('data-studio-task-catalog', $source);
        $this->assertStringContainsString('data-studio-workflow-canvas', $source);
    }

    public function test_child_dialogs_carry_instance_scoped_ids_so_manager_and_studio_can_coexist(): void
    {
        $source = file_get_contents($this->viewPath('partials/workflow-definition-editor.blade.php'));

        foreach ([
            'showAddStepModal' => '-add-step-modal',
            'showEditStepModal' => '-edit-step-modal',
            'showAddTaskModal' => '-add-task-modal',
            'showMoveTaskModal' => '-move-task-modal',
        ] as $model => $suffix) {
            $this->assertSame(1, substr_count($source, '<x-ui.dialog-modal wire:model="'.$model.'"'));
            $this->assertStringContainsString(':id="$routeMarkerId.\''.$suffix.'\'"', $source);
        }
    }

    private function viewPath(string $relative): string
    {
        return dirname(__DIR__, 2).'/resources/views/livewire/admin/network/'.$relative;
    }
}
