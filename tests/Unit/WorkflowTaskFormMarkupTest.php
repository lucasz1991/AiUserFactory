<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WorkflowTaskFormMarkupTest extends TestCase
{
    public function test_value_source_fields_use_stable_top_level_livewire_properties(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root.'/resources/views/livewire/admin/network/partials/workflow-task-form.blade.php');

        $this->assertStringContainsString('@entangle($valueSourceProperty).live', $source);
        $this->assertStringNotContainsString("@entangle(\$prefix.'Extra.value_source')", $source);
        $this->assertStringContainsString("'value_source' => \$prefix.'ValueSource'", $source);
        $this->assertStringContainsString("'workflow_variable' => \$prefix.'WorkflowVariable'", $source);
        $this->assertStringContainsString("'value_fallback' => \$prefix.'ValueFallback'", $source);
        $this->assertStringContainsString('wire:model.live="{{ $fieldModel }}"', $source);
        $this->assertStringContainsString('wire:model="{{ $fieldModel }}"', $source);
    }

    public function test_primary_task_value_can_be_rendered_as_catalog_backed_select(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root.'/resources/views/livewire/admin/network/partials/workflow-task-form.blade.php');

        $this->assertStringContainsString("'value_type' => 'text'", $source);
        $this->assertStringContainsString("'value_options' => []", $source);
        $this->assertStringContainsString("(\$form['value_type'] ?? 'text') === 'select'", $source);
        $this->assertStringContainsString("@foreach((array) (\$form['value_options'] ?? []) as \$optionValue => \$optionLabel)", $source);
        $this->assertStringContainsString('wire:model.defer="{{ $prefix }}InputValue"', $source);
    }
}
