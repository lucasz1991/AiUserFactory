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

    public function test_primary_task_value_can_be_rendered_as_grouped_catalog_select_or_literal_input(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root.'/resources/views/livewire/admin/network/partials/workflow-task-form.blade.php');

        $this->assertStringContainsString("'value_type' => 'text'", $source);
        $this->assertStringContainsString("'value_options' => []", $source);
        $this->assertStringContainsString("'value_option_groups' => []", $source);
        $this->assertStringContainsString("'literal_value_label' => 'Freier Text'", $source);
        $this->assertStringContainsString("(\$form['value_type'] ?? 'text') === 'select'", $source);
        $this->assertStringContainsString('$usesGroupedFixedValue', $source);
        $this->assertStringContainsString('$hasInvalidGroupedFixedValue', $source);
        $this->assertStringContainsString('data-workflow-input-data-value="{{ $prefix }}"', $source);
        $this->assertStringContainsString('@foreach($groupedValueOptions as $group)', $source);
        $this->assertStringContainsString('<optgroup label="{{ $group[\'label\'] }}">', $source);
        $this->assertStringContainsString('aria-invalid="{{ $hasInvalidGroupedFixedValue ? \'true\' : \'false\' }}"', $source);
        $this->assertStringContainsString('data-workflow-input-literal-value="{{ $prefix }}"', $source);
        $this->assertStringContainsString("String(valueSource || 'fixed') === 'literal'", $source);
        $this->assertStringContainsString('wire:model.defer="{{ $prefix }}InputValue"', $source);
        $this->assertGreaterThanOrEqual(2, substr_count($source, 'min-h-11'));
    }

    public function test_selector_fields_use_live_syntax_feedback_and_accessible_help(): void
    {
        $root = dirname(__DIR__, 2);
        $form = file_get_contents($root.'/resources/views/livewire/admin/network/partials/workflow-task-form.blade.php');
        $field = file_get_contents($root.'/resources/views/components/workflows/selector-field.blade.php');
        $tabs = file_get_contents($root.'/resources/views/components/ui/accordion/tabs.blade.php');
        $panel = file_get_contents($root.'/resources/views/components/ui/accordion/tab-panel.blade.php');

        $this->assertStringContainsString('WorkflowSelectorSyntaxService::class', $form);
        $this->assertStringContainsString('<x-workflows.selector-field', $form);
        $this->assertStringContainsString('data-workflow-selector-field', $field);
        $this->assertStringContainsString('x-bind:aria-invalid', $field);
        $this->assertStringContainsString('aria-live="polite"', $field);
        $this->assertStringContainsString('Syntaxhilfe fuer', $field);
        $this->assertStringContainsString("'idSuffix' => ''", $field);
        $this->assertStringContainsString(':id-suffix="$formInstance"', $form);
        $this->assertStringContainsString('$formInstance', $form);
        $this->assertStringContainsString('data-workflow-selector-help-trigger', $field);
        $this->assertGreaterThanOrEqual(2, substr_count($field, 'min-h-11'));
        $this->assertStringContainsString('overflow-x-auto', $tabs);
        $this->assertStringContainsString('@keydown.arrow-right', $tabs);
        $this->assertStringContainsString('@keydown.home', $tabs);
        $this->assertStringContainsString('x-show.important="openTab', $panel);
    }
}
