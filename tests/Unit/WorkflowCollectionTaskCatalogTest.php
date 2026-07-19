<?php

namespace Tests\Unit;

use App\Services\Workflows\WorkflowTaskCatalog;
use PHPUnit\Framework\TestCase;

class WorkflowCollectionTaskCatalogTest extends TestCase
{
    public function test_generic_collection_tasks_are_registered_with_existing_node_scripts(): void
    {
        $catalog = new WorkflowTaskCatalog;
        $expected = [
            'browser.scroll' => 'node/workflows/tasks/browser/scroll.cjs',
            'browser.open_browser_session' => 'node/workflows/tasks/browser/open_browser_session.cjs',
            'loop.for_each_element' => 'node/workflows/tasks/loop/for_each_element.cjs',
            'loop.end' => 'node/workflows/tasks/loop/end.cjs',
            'browser.read_element_fields' => 'node/workflows/tasks/browser/read_element_fields.cjs',
            'data.append_to_array' => 'node/workflows/tasks/data/append_to_array.cjs',
            'decision.array_length' => 'node/workflows/tasks/decision/array_length.cjs',
            'browser.read_searchengine_result' => 'node/workflows/tasks/browser/read_searchengine_result.cjs',
        ];

        foreach ($expected as $taskKey => $script) {
            $definition = $catalog->task($taskKey);

            $this->assertNotNull($definition, $taskKey);
            $this->assertSame('node', $definition['runner']);
            $this->assertSame($script, $definition['node_script']);
            $this->assertFileExists(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $script));
        }
    }

    public function test_browser_navigation_tasks_are_registered_with_node_runner(): void
    {
        $catalog = new WorkflowTaskCatalog;
        $expected = [
            'browser.navigate_back' => 'node/workflows/tasks/browser/navigate_back.cjs',
            'browser.navigate_forward' => 'node/workflows/tasks/browser/navigate_forward.cjs',
            'browser.reload' => 'node/workflows/tasks/browser/reload.cjs',
        ];

        foreach ($expected as $taskKey => $script) {
            $definition = $catalog->task($taskKey);

            $this->assertNotNull($definition, $taskKey);
            $this->assertSame('node', $definition['runner'], $taskKey);
            $this->assertSame($script, $definition['node_script'], $taskKey);
        }
    }

    public function test_catalog_cards_include_task_specific_defaults(): void
    {
        $catalog = new WorkflowTaskCatalog;

        $loop = $catalog->cardFromDefinition('loop.for_each_element');
        $reader = $catalog->cardFromDefinition('browser.read_element_fields');
        $append = $catalog->cardFromDefinition('data.append_to_array');

        $this->assertSame('current_result', $loop['store_current_element_as']);
        $this->assertSame('result_index', $loop['store_index_as']);
        $this->assertSame('current_result', $loop['collect_from_variable']);
        $this->assertSame('current_result', $reader['scope_variable']);
        $this->assertSame('top_results', $append['array_name']);
        $this->assertSame('current_result', $append['value_from_variable']);
    }

    public function test_every_catalog_task_has_complete_structured_documentation(): void
    {
        $catalog = (new WorkflowTaskCatalog)->all();

        $this->assertNotEmpty($catalog);

        foreach ($catalog as $taskKey => $definition) {
            $documentation = $definition['documentation'] ?? [];

            $this->assertNotEmpty($documentation['purpose'] ?? null, $taskKey.' purpose');
            $this->assertNotEmpty($documentation['use_when'] ?? null, $taskKey.' use_when');
            $this->assertNotEmpty($documentation['workflow_role'] ?? null, $taskKey.' workflow_role');
            $this->assertNotEmpty($documentation['execution'] ?? null, $taskKey.' execution');
            $this->assertIsArray($documentation['inputs'] ?? null, $taskKey.' inputs');
            $this->assertNotEmpty($documentation['outputs'] ?? null, $taskKey.' outputs');
            $this->assertNotEmpty($documentation['routing'] ?? null, $taskKey.' routing');
            $this->assertNotEmpty($documentation['important_notes'] ?? null, $taskKey.' notes');
        }

        $loopFields = collect(data_get($catalog['loop.for_each_element'], 'form.extra_fields'))->keyBy('name');
        $this->assertNotNull($loopFields->get('collect_to_array'));
        $this->assertNotNull($loopFields->get('completion_target'));
        $this->assertSame('Zielkarte bei leerer Liste', data_get($loopFields->get('empty_target'), 'label'));
    }

    public function test_fill_field_exposes_explicit_fixed_or_workflow_variable_sources(): void
    {
        $definition = (new WorkflowTaskCatalog)->task('input.fill_field');
        $fields = collect(data_get($definition, 'form.extra_fields'))->keyBy('name');

        $this->assertTrue((bool) data_get($definition, 'form.value_source_control'));
        $this->assertFalse((bool) data_get($definition, 'form.value_required'));
        $this->assertSame([
            'fixed' => 'Fester Wert',
            'workflow_variable' => 'Workflow-Variable',
        ], data_get($fields->get('value_source'), 'options'));
        $this->assertSame(
            'workflow_variable',
            data_get($fields->get('workflow_variable'), 'required_when.equals'),
        );
        $this->assertNotNull($fields->get('value_fallback'));
    }

    public function test_validate_inputs_uses_the_structured_variable_editor(): void
    {
        $definition = (new WorkflowTaskCatalog)->task('data.validate_inputs');
        $fields = collect(data_get($definition, 'form.extra_fields'))->keyBy('name');

        $this->assertSame('workflow_input_definitions', data_get($fields->get('input_definitions'), 'type'));
        $this->assertSame('workflow_inputs', data_get($fields->get('output_group'), 'default'));
        $this->assertStringContainsString('_inputs', data_get($fields->get('output_group'), 'help'));
    }
}
