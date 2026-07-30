<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WorkflowTouchReorderMarkupTest extends TestCase
{
    public function test_coarse_pointer_controls_emit_semantic_task_and_step_move_requests(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/workflows/step-card.blade.php');

        $this->assertStringContainsString("workflow-step-move-requested', { stepId:", $source);
        $this->assertStringContainsString("direction: 'left'", $source);
        $this->assertStringContainsString("direction: 'right'", $source);
        $this->assertStringContainsString("workflow-task-move-requested', { stepId:", $source);
        $this->assertStringContainsString("direction: 'up'", $source);
        $this->assertStringContainsString("direction: 'down'", $source);
        $this->assertStringContainsString("direction: 'another-list'", $source);
        $this->assertStringContainsString('aria-label="Task nach oben verschieben"', $source);
        $this->assertStringContainsString('aria-label="Task in eine andere Liste verschieben"', $source);
        $this->assertStringContainsString('aria-label="Liste nach links verschieben"', $source);
    }

    public function test_drag_suppresses_click_and_double_click_activation_until_release(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/workflows/step-card.blade.php');

        $this->assertStringContainsString('beginTaskDrag(event, taskKey, sourceStepId)', $source);
        $this->assertStringContainsString('taskActivationBlocked: false', $source);
        $this->assertStringContainsString('taskActivationAllowed(event)', $source);
        $this->assertStringContainsString('x-on:dragend.window="finishTaskDrag()"', $source);
        $this->assertStringContainsString('if (! taskActivationAllowed($event)) return; focusedTask', $source);
        $this->assertStringContainsString('if (! taskActivationAllowed($event)) return; $wire.openEditTaskCard', $source);
        $this->assertStringContainsString('draggable="true"', $source);
    }

    public function test_touch_controls_are_hidden_by_default_and_have_44_pixel_targets_on_coarse_pointers(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/css/workflow-experience.css');

        $this->assertStringContainsString(".ff-touch-step-reorder,\n.ff-touch-task-reorder {\n  display: none;", $source);
        $this->assertStringContainsString('@media (hover: none), (pointer: coarse)', $source);
        $this->assertStringContainsString('grid-template-columns: repeat(3, minmax(44px, 1fr));', $source);
        $this->assertStringContainsString('min-width: 44px;', $source);
        $this->assertStringContainsString('min-height: 44px;', $source);
        $this->assertStringContainsString('touch-action: manipulation;', $source);
    }
}
