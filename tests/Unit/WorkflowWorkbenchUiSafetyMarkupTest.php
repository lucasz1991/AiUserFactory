<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WorkflowWorkbenchUiSafetyMarkupTest extends TestCase
{
    public function test_escape_closes_the_deepest_workbench_surface_and_restores_local_focus(): void
    {
        $root = dirname(__DIR__, 2);
        $manager = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-manager.blade.php');
        $definition = file_get_contents($root.'/resources/views/livewire/admin/network/partials/workflow-definition-editor.blade.php');
        $studio = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio.blade.php');
        $toolModal = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio/tool-modal.blade.php');
        $stepCard = file_get_contents($root.'/resources/views/components/workflows/step-card.blade.php');
        $taskCard = file_get_contents($root.'/resources/views/components/workflows/task-card.blade.php');

        $this->assertStringContainsString("shell.querySelectorAll('.jetstream-modal, [role=dialog][aria-modal=true]')", $manager);
        $this->assertStringContainsString("document.querySelector('.ff-copilot-panel')", $manager);
        $this->assertStringContainsString('if (copilotPanel && this.elementIsVisible(copilotPanel)) return;', $manager);
        $this->assertStringContainsString('if (childDialog || openMenu) return;', $manager);
        $this->assertLessThan(
            strpos($manager, "shell.querySelectorAll('.jetstream-modal, [role=dialog][aria-modal=true]')"),
            strpos($manager, "document.querySelector('.ff-copilot-panel')")
        );
        $this->assertLessThan(
            strpos($manager, '[data-workflow-mobile-library][data-open=true]'),
            strpos($manager, 'if (childDialog || openMenu) return;')
        );

        foreach ([$definition, $studio, $toolModal] as $markup) {
            $this->assertStringContainsString('<x-ui.modal', $markup);
        }
        $modal = file_get_contents($root.'/resources/views/components/ui/modal.blade.php');
        $this->assertStringContainsString('x-on:keydown.escape.prevent.stop="closeModal()"', $modal);
        $this->assertStringContainsString('x-trap.inert.noscroll="show"', $modal);
        $this->assertStringContainsString('x-show.important="show"', $modal);

        foreach ([$stepCard, $taskCard] as $markup) {
            $this->assertStringContainsString('x-on:keydown.escape.stop.prevent=', $markup);
            $this->assertStringContainsString('actionsTrigger?.focus({ preventScroll: true })', $markup);
        }

        // Menu items are hidden again after opening a tool. Restore focus to
        // their visible summary, not to an inert/hidden former trigger.
        $this->assertStringContainsString("'browser' => '[data-studio-browser-preview-trigger]'", $toolModal);
        $this->assertStringContainsString("'[data-studio-tool-group=\"data\"] > summary'", $toolModal);
        $this->assertStringContainsString("'[data-studio-tool-group=\"diagnostics\"] > summary'", $toolModal);
        $this->assertStringContainsString(':return-focus="$toolReturnFocus"', $toolModal);
        $this->assertStringContainsString("\$toolReturnFocus = '[data-workflow-studio-session=", $toolModal);
        $this->assertStringContainsString('data-studio-run-start-trigger', $studio);
        $this->assertStringContainsString('data-studio-copilot-settings-trigger', $studio);
    }

    public function test_locked_definition_surface_removes_client_side_mutation_affordances(): void
    {
        $root = dirname(__DIR__, 2);
        $definition = file_get_contents($root.'/resources/views/livewire/admin/network/partials/workflow-definition-editor.blade.php');
        $stepCard = file_get_contents($root.'/resources/views/components/workflows/step-card.blade.php');
        $taskCard = file_get_contents($root.'/resources/views/components/workflows/task-card.blade.php');

        $this->assertStringContainsString('data-definition-read-only="{{ $canEdit ? \'false\' : \'true\' }}"', $definition);
        $this->assertStringContainsString('if (! @js($canEdit)) return;', $definition);
        $this->assertMatchesRegularExpression('/@if\(\$canEdit\)\s+x-sort=/', $definition);
        $this->assertMatchesRegularExpression('/@if\(\$canEdit\)\s+x-sort:item=/', $definition);
        $this->assertStringContainsString(':locked="! $canEdit"', $definition);
        $this->assertStringContainsString('@if($showDefinitionSurface && $canEdit)', $definition);

        $this->assertMatchesRegularExpression('/@if\(! \$locked\)\s+@isset\(\$actions\)/', $stepCard);
        $this->assertStringContainsString(':locked="$locked"', $stepCard);
        $this->assertStringContainsString("'locked' => false", $taskCard);
        $this->assertMatchesRegularExpression('/@if\(! \$locked\)\s+<div class="flex h-6/', $taskCard);
    }

    public function test_touch_and_narrow_studio_controls_receive_complete_44_pixel_targets(): void
    {
        $styles = file_get_contents(dirname(__DIR__, 2).'/resources/css/workflow-experience.css');
        $selector = "[data-workflow-studio-shell] :is(\n    button,\n    select,\n    textarea,";

        $this->assertStringContainsString('@media (hover: none), (pointer: coarse)', $styles);
        $this->assertStringContainsString('@media (max-width: 767px)', $styles);
        $this->assertGreaterThanOrEqual(2, substr_count($styles, $selector));
        $this->assertGreaterThanOrEqual(2, substr_count($styles, "[data-workflow-studio-shell] :is(button, a[href], [role='button'], [role='tab'])"));
        $this->assertGreaterThanOrEqual(2, substr_count($styles, 'min-height: 44px !important;'));
        $this->assertGreaterThanOrEqual(2, substr_count($styles, 'min-width: 44px !important;'));
    }

    public function test_historical_runs_are_visibly_read_only_but_keep_definition_navigation(): void
    {
        $root = dirname(__DIR__, 2);
        $studio = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio.blade.php');
        $tools = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio/tool-bar.blade.php');

        $this->assertStringContainsString('data-workflow-historical-run-readonly', $studio);
        $this->assertStringContainsString('@disabled($modeLocked || $historicalRunView)', $studio);
        $this->assertStringContainsString('@disabled($historicalRunView || $isActive || $isPaused)', $studio);
        $this->assertStringContainsString('@disabled($historicalRunView || (! $isActive && ! $isPaused))', $studio);
        $this->assertStringContainsString('wire:click="openDefinitionBuilder"', $studio);
        $this->assertStringContainsString('wire:click="editSelectedTask"', $studio);
        $this->assertStringContainsString('@if($showCopilotSettingsModal && ! $historicalRunView)', $studio);
        $this->assertStringContainsString('@disabled($historicalRunView)', $tools);
    }

    public function test_overview_map_uses_its_separate_keyboard_cta_and_manager_polling_is_surface_aware(): void
    {
        $root = dirname(__DIR__, 2);
        $manager = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-manager.blade.php');
        $minimap = file_get_contents($root.'/resources/views/components/workflows/minimap.blade.php');

        $this->assertStringContainsString('data-workflow-edit-cta', $manager);
        $this->assertStringContainsString('rememberWorkbenchTrigger($refs.overviewEditCta);', $manager);
        $this->assertStringNotContainsString('x-ref="overviewMapTrigger"', $manager);
        $this->assertStringContainsString("closest('[data-workflow-minimap-zoom]')", $manager);
        $this->assertStringContainsString('x-on:click.stop="setZoom(', $minimap);
        $this->assertStringContainsString("wire:click=\"openTestWorkbench('interactive')\" x-on:click=\"rememberWorkbenchTrigger(\$el); open = false\"", $manager);
        $this->assertStringContainsString("wire:click=\"openTestWorkbench('autonomous')\" x-on:click=\"rememberWorkbenchTrigger(\$el); open = false\"", $manager);
        $this->assertStringContainsString('wire:click="openDefinitionWorkbench" x-on:click="rememberWorkbenchTrigger($el); open = false"', $manager);
        $this->assertStringContainsString("wire:click=\"openDefinitionWorkbench('add-step')\" x-on:click=\"rememberWorkbenchTrigger(\$el); open = false\"", $manager);
        $this->assertStringContainsString("requested?.closest?.('.ff-menu')", $manager);
        $this->assertStringContainsString("querySelector(':scope > button[aria-expanded]')", $manager);

        $this->assertStringContainsString('$managerWorkbenchPollEnabled = false;', $manager);
        $this->assertMatchesRegularExpression('/\?\s*2\s*:\s*15;/', $manager);
        $this->assertStringContainsString('data-workflow-manager-poll=', $manager);
        $this->assertStringContainsString('wire:target.except="taskSearch,selectTaskGroup,catalogTargetStepId,refreshWorkbenchContext"', $manager);
        $this->assertSame(0, substr_count($manager, 'wire:poll.visible.2s="refreshWorkbenchContext"'));
    }

    public function test_parent_livewire_loading_never_makes_the_fullscreen_workbench_transparent(): void
    {
        $root = dirname(__DIR__, 2);
        $manager = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-manager.blade.php');

        $this->assertStringNotContainsString(
            'wire:loading.class="opacity-60 pointer-events-none"',
            $manager,
        );
        $this->assertStringContainsString('wire:loading.class="cursor-wait"', $manager);
        $this->assertStringContainsString('wire:loading.attr="aria-busy"', $manager);
        $this->assertStringContainsString("classList.toggle('workflow-workbench-open', open)", $manager);
        $this->assertStringContainsString('data-open="{{ $workbenchOpen ? \'true\' : \'false\' }}"', $manager);
        $this->assertStringContainsString('x-bind:data-open="workbenchOpen ? \'true\' : \'false\'"', $manager);
        $this->assertStringContainsString("{{ \$workbenchOpen ? '' : ' display: none !important;' }}", $manager);
        $this->assertDoesNotMatchRegularExpression('/x-cloak\s+x-show\.important="workbenchOpen"/', $manager);
        $this->assertStringContainsString('fixed inset-0 top-0 z-[70]', $manager);
        $this->assertStringContainsString('overflow-hidden bg-slate-100', $manager);
    }

    public function test_minimal_controls_preserve_execution_guards_and_group_secondary_tools_into_native_disclosures(): void
    {
        $root = dirname(__DIR__, 2);
        $studio = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio.blade.php');
        $tools = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio/tool-bar.blade.php');
        $browser = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio/browser-windows.blade.php');

        foreach (['startRun', 'pauseRun', 'resumeRun', 'stopRun', 'runSingleTask', 'runRealPlayback', 'restartRun'] as $action) {
            $this->assertSame(1, substr_count($studio, 'wire:click="'.$action.'"'));
        }
        $this->assertStringContainsString('aria-label="Test steuern"', $studio);
        $this->assertStringContainsString('data-studio-run-stop-trigger wire:confirm=', $studio);
        $this->assertStringContainsString('@disabled($historicalRunView || ! $isPaused)', $studio);
        $this->assertStringContainsString('@disabled($historicalRunView || ! $isActive)', $studio);
        $this->assertStringContainsString('<details data-studio-test-options', $studio);
        $this->assertStringContainsString('wire:model="personId" @disabled($historicalRunView || $isActive || $isPaused)', $studio);
        $this->assertStringContainsString('data-workflow-studio-builder-trigger @disabled($isActive)', $studio);
        $this->assertStringContainsString('<details data-studio-tool-group=', $tools);
        $this->assertStringContainsString('<details data-studio-additional-browser-windows', $browser);
        foreach ([$studio, $tools, $browser] as $markup) {
            $this->assertStringContainsString('x-on:keydown.escape.prevent.stop="open = false; $refs.summary.focus()"', $markup);
            $this->assertStringContainsString('x-bind:open="open"', $markup);
            $this->assertStringContainsString('x-on:toggle="open = $el.open"', $markup);
        }
        $this->assertStringContainsString('data-studio-browser-preview-trigger', $browser);
        $this->assertStringNotContainsString('animate-ping', $browser);
        $this->assertStringContainsString('$browserConnected = $isActive &&', $browser);
    }
}
