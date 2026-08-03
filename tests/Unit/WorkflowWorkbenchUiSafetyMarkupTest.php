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

        $this->assertStringContainsString("shell.querySelectorAll('.jetstream-modal, [role=\"dialog\"][aria-modal=\"true\"]')", $manager);
        $this->assertStringContainsString('if (childDialog || openMenu) return;', $manager);
        $this->assertLessThan(
            strpos($manager, '[data-workflow-mobile-library][data-open="true"]'),
            strpos($manager, 'if (childDialog || openMenu) return;')
        );

        foreach ([$definition, $studio, $toolModal] as $markup) {
            $this->assertStringContainsString('x-on:keydown.escape.prevent.stop=', $markup);
            $this->assertStringContainsString('x-trap.inert.noscroll="true"', $markup);
        }

        foreach ([$stepCard, $taskCard] as $markup) {
            $this->assertStringContainsString('x-on:keydown.escape.stop.prevent=', $markup);
            $this->assertStringContainsString('actionsTrigger?.focus({ preventScroll: true })', $markup);
        }

        $this->assertStringContainsString('data-studio-tool-trigger=', $toolModal);
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

        $this->assertStringContainsString("\$managerWorkbenchPollEnabled = \$workbenchSurface === 'definition';", $manager);
        $this->assertStringContainsString("? 2\n        : 15;", $manager);
        $this->assertStringContainsString('data-workflow-manager-poll=', $manager);
        $this->assertStringContainsString('wire:target.except="taskSearch,selectTaskGroup,catalogTargetStepId,refreshWorkbenchContext"', $manager);
        $this->assertSame(0, substr_count($manager, 'wire:poll.visible.2s="refreshWorkbenchContext"'));
    }
}
