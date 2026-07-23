<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowDefinitionValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowDefinitionValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_validator_returns_structured_diagnostics_for_invalid_graphs(): void
    {
        $workflow = $this->workflow();
        $workflow->steps()->create([
            'name' => 'Invalid',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'invalid',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'unknown',
                'task_key' => 'missing.catalog.task',
                'next' => ['type' => 'step', 'action_key' => 'missing-step'],
            ], [
                'key' => 'orphan-end',
                'task_key' => 'loop.end',
                'loop_start_key' => 'missing-loop',
            ]]],
        ]);

        $result = app(WorkflowDefinitionValidator::class)->validate($workflow, ['Array als Ausgabe zurueckgeben']);
        $codes = collect($result['diagnostics'])->pluck('code');

        $this->assertFalse($result['valid']);
        $this->assertContains('unknown_catalog_task', $codes);
        $this->assertContains('orphan_loop_end', $codes);
        $this->assertContains('workflow_return_missing', $codes);
        $diagnostic = collect($result['diagnostics'])->firstWhere('code', 'unknown_catalog_task');
        $this->assertSame('error', $diagnostic['severity']);
        $this->assertSame('invalid', $diagnostic['step_action_key']);
        $this->assertSame('unknown', $diagnostic['task_key']);
        $this->assertNotEmpty($diagnostic['repair_hint']);
    }

    public function test_bounded_self_retry_is_valid_but_unbounded_self_route_is_rejected(): void
    {
        $workflow = $this->workflow();
        $step = $workflow->steps()->create([
            'name' => 'Retry',
            'type' => WorkflowStep::TYPE_WAIT,
            'action_key' => 'retry',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'wait',
                'task_key' => 'wait.seconds',
                'value' => 1,
                'on_error' => [
                    'type' => 'card',
                    'action_key' => 'retry',
                    'card_key' => 'wait',
                    'max_attempts' => 2,
                ],
            ]]],
        ]);

        $this->assertTrue(app(WorkflowDefinitionValidator::class)->validate($workflow)['valid']);

        $config = $step->config_json;
        unset($config['tasks'][0]['on_error']['max_attempts']);
        $step->forceFill(['config_json' => $config])->save();
        $result = app(WorkflowDefinitionValidator::class)->validate($workflow->fresh('steps'));

        $this->assertFalse($result['valid']);
        $this->assertContains('unsafe_self_route', collect($result['diagnostics'])->pluck('code'));
    }

    public function test_collection_requires_a_producer_array_target_and_returnable_array(): void
    {
        $workflow = $this->workflow();
        $workflow->steps()->create([
            'name' => 'Collection',
            'type' => WorkflowStep::TYPE_DATA_TASK,
            'action_key' => 'collection',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'append',
                'task_key' => 'data.append_to_array',
                'array_name' => 'results',
                'value_from_variable' => 'missing_result',
            ], [
                'key' => 'check-array',
                'task_key' => 'decision.array_length',
                'array_name' => 'never_created',
                'compare_value' => 1,
            ], [
                'key' => 'return-results',
                'task_key' => 'data.workflow_return',
                'selector' => 'missing_array',
            ]]],
        ]);

        $result = app(WorkflowDefinitionValidator::class)->validate(
            $workflow,
            ['Rueckgabewert = array'],
        );
        $codes = collect($result['diagnostics'])->pluck('code');

        $this->assertFalse($result['valid']);
        $this->assertContains('collection_producer_missing', $codes);
        $this->assertContains('array_producer_missing', $codes);
        $this->assertContains('workflow_return_source_missing', $codes);
        $this->assertContains('workflow_return_array_source_missing', $codes);
    }

    public function test_loop_collection_requires_a_matching_body_producer(): void
    {
        $workflow = $this->workflow();
        $workflow->steps()->create([
            'name' => 'Loop',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'loop',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'loop-start',
                'task_key' => 'loop.for_each_element',
                'selector' => '.result',
                'collect_to_array' => 'results',
                'collect_from_variable' => 'result_data',
            ], [
                'key' => 'wait-only',
                'task_key' => 'wait.seconds',
                'value' => 1,
            ], [
                'key' => 'loop-end',
                'task_key' => 'loop.end',
                'loop_start_key' => 'loop-start',
            ], [
                'key' => 'return-results',
                'task_key' => 'data.workflow_return',
                'selector' => 'results',
            ]]],
        ]);

        $result = app(WorkflowDefinitionValidator::class)->validate($workflow, ['Rueckgabewert = array']);

        $this->assertFalse($result['valid']);
        $this->assertContains('loop_collection_producer_missing', collect($result['diagnostics'])->pluck('code'));
    }

    public function test_batch_search_array_can_feed_a_pure_control_loop_and_workflow_return(): void
    {
        $workflow = $this->workflow();
        $step = $workflow->steps()->create([
            'name' => 'Batch search and loop',
            'type' => WorkflowStep::TYPE_DATA_PROCESSING,
            'action_key' => 'batch-search-loop',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'read-results',
                'task_key' => 'browser.read_searchengine_result',
                'list_container_selector' => '#search',
                'list_item_selector' => '.result',
                'output_array_name' => 'top_results',
            ], [
                'key' => 'loop-start',
                'task_key' => 'loop.for_each_element',
                'source_array' => 'top_results',
                'iteration_count' => 3,
                'loop_end_key' => 'loop-end',
            ], [
                'key' => 'body',
                'task_key' => 'wait.seconds',
                'value' => 0,
            ], [
                'key' => 'loop-end',
                'task_key' => 'loop.end',
                'loop_start_key' => 'loop-start',
            ], [
                'key' => 'return-results',
                'task_key' => 'data.workflow_return',
                'selector' => 'top_results',
            ]]],
        ]);

        $valid = app(WorkflowDefinitionValidator::class)->validate($workflow, ['Rueckgabewert = array']);
        $this->assertTrue($valid['valid'], json_encode($valid['diagnostics'], JSON_PRETTY_PRINT));

        $config = $step->config_json;
        $config['tasks'][1]['source_array'] = 'missing_results';
        $step->forceFill(['config_json' => $config])->save();
        $invalid = app(WorkflowDefinitionValidator::class)->validate($workflow->fresh('steps'), ['Rueckgabewert = array']);

        $this->assertFalse($invalid['valid']);
        $this->assertContains('loop_source_array_missing', collect($invalid['diagnostics'])->pluck('code'));
    }

    public function test_active_embedded_workflows_and_legacy_terminal_routes_are_executable(): void
    {
        $embedded = $this->workflow();
        $embedded->steps()->create([
            'name' => 'Embedded task',
            'type' => WorkflowStep::TYPE_WAIT,
            'action_key' => 'embedded-task',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'embedded-wait',
                'task_key' => 'wait.seconds',
                'value' => 0,
            ]]],
        ]);
        $parent = $this->workflow();
        $parent->steps()->create([
            'name' => 'Include child',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'include-child',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'include-'.$embedded->id,
                'task_key' => 'workflow.include.'.$embedded->id,
                'runner' => 'workflow',
                'workflow_id' => $embedded->id,
                'next' => ['step' => 'end'],
                'on_error' => ['step' => 'fail'],
            ]]],
        ]);

        $result = app(WorkflowDefinitionValidator::class)->validate($parent);

        $this->assertTrue($result['valid'], json_encode($result['diagnostics'], JSON_PRETTY_PRINT));
        $this->assertNotContains('unknown_catalog_task', collect($result['diagnostics'])->pluck('code'));
        $this->assertNotContains('route_step_missing', collect($result['diagnostics'])->pluck('code'));
    }

    public function test_press_key_accepts_only_catalogued_keyboard_values(): void
    {
        $workflow = $this->workflow();
        $step = $workflow->steps()->create([
            'name' => 'Keyboard',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'keyboard',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'confirm',
                'task_key' => 'browser.press_key',
                'value' => 'Escape',
            ]]],
        ]);

        $invalid = app(WorkflowDefinitionValidator::class)->validate($workflow->fresh('steps'));
        $diagnostic = collect($invalid['diagnostics'])->firstWhere('code', 'invalid_configuration_option');

        $this->assertFalse($invalid['valid']);
        $this->assertSame('confirm', $diagnostic['task_key']);
        $this->assertSame('value', $diagnostic['field']);
        $this->assertStringContainsString('Enter, Tab', $diagnostic['repair_hint']);

        $config = $step->config_json;
        $config['tasks'][0]['value'] = 'Tab';
        $step->forceFill(['config_json' => $config])->save();

        $valid = app(WorkflowDefinitionValidator::class)->validate($workflow->fresh('steps'));

        $this->assertTrue($valid['valid'], json_encode($valid['diagnostics'], JSON_PRETTY_PRINT));
    }

    private function workflow(): Workflow
    {
        return Workflow::query()->create([
            'name' => 'Validator '.str()->random(6),
            'slug' => 'validator-'.str()->random(10),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
    }
}
