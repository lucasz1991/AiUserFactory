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
        $visibleTaskKeys = collect($catalog->options())->pluck('key');
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
            $this->assertFalse((bool) data_get($definition, 'form.value'), $taskKey.' must not be modeled as a key press');
            $this->assertContains($taskKey, $visibleTaskKeys, $taskKey.' must stay visible in the task form catalog');
        }
    }

    public function test_press_key_uses_a_bounded_enter_and_tab_select(): void
    {
        $definition = (new WorkflowTaskCatalog)->task('browser.press_key');

        $this->assertNotNull($definition);
        $this->assertTrue((bool) data_get($definition, 'form.value'));
        $this->assertTrue((bool) data_get($definition, 'form.value_required'));
        $this->assertSame('select', data_get($definition, 'form.value_type'));
        $this->assertSame([
            'Enter' => 'Enter - bestaetigen oder absenden',
            'Tab' => 'Tab - zum naechsten Feld wechseln',
        ], data_get($definition, 'form.value_options'));
        $this->assertStringContainsString('Navigations-Tasks', data_get($definition, 'form.value_help'));
    }

    public function test_catalog_cards_include_task_specific_defaults(): void
    {
        $catalog = new WorkflowTaskCatalog;

        $loop = $catalog->cardFromDefinition('loop.for_each_element');
        $reader = $catalog->cardFromDefinition('browser.read_element_fields');
        $searchReader = $catalog->cardFromDefinition('browser.read_searchengine_result');
        $append = $catalog->cardFromDefinition('data.append_to_array');

        $this->assertSame('1', $loop['iteration_count']);
        $this->assertSame('current_item', $loop['store_current_item_as']);
        $this->assertSame('loop_index', $loop['store_index_as']);
        $this->assertArrayNotHasKey('selector', $loop);
        $this->assertArrayNotHasKey('collect_to_array', $loop);
        $this->assertSame('current_result', $reader['scope_variable']);
        $this->assertSame('#search', $searchReader['list_container_selector']);
        $this->assertSame('.MjjYud, .g, article, [data-result], .result', $searchReader['list_item_selector']);
        $this->assertSame('top_results', $searchReader['output_array_name']);
        $this->assertSame('true', $searchReader['exclude_ads']);
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
        $this->assertNotNull($loopFields->get('iteration_count'));
        $this->assertNotNull($loopFields->get('source_array'));
        $this->assertNotNull($loopFields->get('condition_variable'));
        $this->assertNotNull($loopFields->get('store_current_item_as'));
        $this->assertNull($loopFields->get('collect_to_array'));
        $this->assertNull($loopFields->get('success_target'));
        $this->assertFalse((bool) data_get($catalog['loop.for_each_element'], 'form.selector'));
        $this->assertFalse((bool) data_get($catalog['loop.end'], 'form.browser_window'));

        $searchFields = collect(data_get($catalog['browser.read_searchengine_result'], 'form.extra_fields'))->keyBy('name');
        $this->assertNotNull($searchFields->get('list_container_selector'));
        $this->assertNotNull($searchFields->get('list_item_selector'));
        $this->assertNotNull($searchFields->get('exclude_item_selector'));
        $this->assertNotNull($searchFields->get('exclude_item_text'));
        $this->assertNull(data_get($searchFields->get('title_selector'), 'default'));
        $this->assertNull(data_get($searchFields->get('link_selector'), 'default'));
    }

    public function test_runtime_uses_pure_control_scripts_but_keeps_legacy_dom_loops_compatible(): void
    {
        $catalog = app(WorkflowTaskCatalog::class);

        $control = $catalog->resolveRuntimeTask([
            'task_key' => 'loop.for_each_element',
            'kind' => 'browser',
            'browser_window' => 'main',
            'iteration_count' => 3,
        ]);
        $legacy = $catalog->resolveRuntimeTask([
            'task_key' => 'loop.for_each_element',
            'kind' => 'browser',
            'selector' => '.legacy-result',
        ]);
        $end = $catalog->resolveRuntimeTask([
            'task_key' => 'loop.end',
            'kind' => 'browser',
            'browser_window' => 'main',
        ]);

        $this->assertSame('node/workflows/tasks/loop/for_each_element.cjs', $control['node_script']);
        $this->assertSame('data', $control['kind']);
        $this->assertArrayNotHasKey('browser_window', $control);
        $this->assertSame('node/workflows/tasks/loop/for_each_element_legacy.cjs', $legacy['node_script']);
        $this->assertSame('browser', $legacy['kind']);
        $this->assertSame('node/workflows/tasks/loop/end.cjs', $end['node_script']);
        $this->assertSame('data', $end['kind']);
        $this->assertArrayNotHasKey('browser_window', $end);
    }

    public function test_continuous_studio_runtime_is_not_segmented_like_single_task_or_copilot(): void
    {
        $reflection = new \ReflectionClass(\App\Services\Workflows\WorkflowTaskRunner::class);
        $runner = $reflection->newInstanceWithoutConstructor();
        $segment = new \ReflectionMethod($runner, 'shouldSegmentTasks');
        $segment->setAccessible(true);

        $this->assertFalse($segment->invoke($runner, ['interactive_debug' => true]));
        $this->assertTrue($segment->invoke($runner, ['studio_single_task' => true, 'interactive_debug' => true]));
        $this->assertTrue($segment->invoke($runner, ['copilot_supervised' => true]));
        $this->assertFalse($segment->invoke($runner, ['segment_tasks' => false, 'copilot_supervised' => true]));
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
