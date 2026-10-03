<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowRunConflictException;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\Workflows\WorkflowRunContextStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowRunContextPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_writers_preserve_independent_nested_updates_and_callback_fields(): void
    {
        $run = $this->createRun(['variables' => ['left' => 1, 'right' => 1]]);
        $worker = $run->fresh();
        $callback = $run->fresh();
        $callback->update(['context_json' => ['variables' => ['left' => 1, 'right' => 2], 'runtime_callback' => ['checkpoint' => 'new']]]);
        $worker->update(['context_json' => ['variables' => ['left' => 3, 'right' => 1]]]);

        $this->assertSame(3, data_get($run->fresh()->context_json, 'variables.left'));
        $this->assertSame(2, data_get($worker->context_json, 'variables.right'));
        $this->assertSame('new', data_get($run->fresh()->context_json, 'runtime_callback.checkpoint'));
    }

    public function test_overlapping_context_write_is_rejected_without_partially_changing_status(): void
    {
        $run = $this->createRun(['cursor' => ['task_key' => 'old']]);
        $worker = $run->fresh();
        $run->update(['context_json' => ['cursor' => ['task_key' => 'new']]]);

        try {
            $worker->update(['status' => 'completed', 'context_json' => ['cursor' => ['task_key' => 'stale']]]);
            $this->fail('A stale overlapping cursor write must fail.');
        } catch (WorkflowRunConflictException $exception) {
            $this->assertStringContainsString('cursor.task_key', $exception->getMessage());
        }

        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame('new', data_get($run->fresh()->context_json, 'cursor.task_key'));
    }

    public function test_stale_completion_cannot_resurrect_a_cancelled_run(): void
    {
        $run = $this->createRun(['value' => 'original']);
        $worker = $run->fresh();
        $run->update(['status' => 'cancelled', 'context_json' => ['value' => 'original', 'stop_requested' => true]]);

        try {
            $worker->update(['status' => 'completed', 'context_json' => ['value' => 'late']]);
            $this->fail('Late completion must not overwrite cancellation.');
        } catch (WorkflowRunConflictException $exception) {
            $this->assertStringContainsString('status', $exception->getMessage());
        }

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertTrue($run->fresh()->context_json['stop_requested']);
        $this->assertSame('original', $run->fresh()->context_json['value']);
    }

    public function test_dictionary_order_is_not_a_conflict_but_list_order_is_meaningful(): void
    {
        $store = app(WorkflowRunContextStore::class);
        $this->assertTrue($store->equivalent(['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]));
        $this->assertFalse($store->equivalent([1, 2], [2, 1]));
        $this->assertFalse($store->equivalent(['a' => 1], ['a' => '1']));

        $this->expectException(WorkflowRunConflictException::class);
        $store->merge(['tasks' => ['a', 'b']], ['tasks' => ['b', 'a']], ['tasks' => ['a', 'c']]);
    }

    public function test_context_only_late_result_is_fenced_after_a_stop_request(): void
    {
        $run = $this->createRun(['variables' => ['original' => true]]);
        $worker = $run->fresh();
        $run->update(['status' => 'stop_requested']);

        try {
            $worker->update(['context_json' => ['variables' => ['original' => true, 'late_result' => true]]]);
            $this->fail('A context-only late result must respect stop.');
        } catch (WorkflowRunConflictException $exception) {
            $this->assertStringContainsString('status', $exception->getMessage());
        }
        $this->assertSame('stop_requested', $run->fresh()->status);
        $this->assertArrayNotHasKey('late_result', $run->fresh()->context_json['variables']);
    }

    public function test_deletion_and_explicit_null_are_distinct_and_merge_with_unrelated_changes(): void
    {
        $store = app(WorkflowRunContextStore::class);
        $merged = $store->merge(['delete' => 1, 'null' => 1, 'keep' => 1], ['null' => null, 'keep' => 1], ['delete' => 1, 'null' => 1, 'keep' => 2]);
        $this->assertArrayNotHasKey('delete', $merged);
        $this->assertArrayHasKey('null', $merged);
        $this->assertNull($merged['null']);
        $this->assertSame(2, $merged['keep']);
    }

    public function test_delete_conflicting_with_a_parallel_update_is_rejected(): void
    {
        $this->expectException(WorkflowRunConflictException::class);
        app(WorkflowRunContextStore::class)->merge(['cursor' => 1], [], ['cursor' => 2]);
    }

    public function test_concurrent_history_appends_are_bounded_and_receive_unique_sequences(): void
    {
        $base = ['task_history' => array_map(fn (int $seq): array => ['seq' => $seq, 'task_key' => 'task-'.$seq], range(1, 599)), 'task_history_sequence' => 599];
        $run = $this->createRun($base);
        $worker = $run->fresh();
        $callback = $run->fresh();
        $callback->update(['context_json' => ['task_history' => [...$base['task_history'], ['seq' => 600, 'task_key' => 'callback']], 'task_history_sequence' => 600]]);
        $worker->update(['context_json' => ['task_history' => [...$base['task_history'], ['seq' => 600, 'task_key' => 'worker']], 'task_history_sequence' => 600]]);

        $context = $run->fresh()->context_json;
        $this->assertCount(600, $context['task_history']);
        $this->assertSame(2, $context['task_history'][0]['seq']);
        $this->assertSame('callback', $context['task_history'][598]['task_key']);
        $this->assertSame('worker', $context['task_history'][599]['task_key']);
        $this->assertSame(601, $context['task_history_sequence']);
        $this->assertCount(600, array_unique(array_column($context['task_history'], 'seq')));
    }

    public function test_history_replacement_is_not_misinterpreted_as_an_append(): void
    {
        $this->expectException(WorkflowRunConflictException::class);
        app(WorkflowRunContextStore::class)->merge(
            ['task_history' => [['seq' => 1]]],
            ['task_history' => [['seq' => 2]]],
            ['task_history' => [['seq' => 1], ['seq' => 3]]],
        );
    }

    public function test_new_independent_nested_fields_can_be_merged(): void
    {
        $merged = app(WorkflowRunContextStore::class)->merge([], ['new' => ['a' => 1]], ['new' => ['b' => 2]]);
        $this->assertSame(1, $merged['new']['a']);
        $this->assertSame(2, $merged['new']['b']);
    }

    private function createRun(array $context): WorkflowRun
    {
        $workflow = Workflow::query()->create(['name' => 'Context concurrency', 'slug' => (string) Str::uuid(), 'trigger_type' => 'manual']);

        return WorkflowRun::query()->create(['run_uuid' => (string) Str::uuid(), 'workflow_id' => $workflow->id, 'status' => 'running', 'context_json' => $context]);
    }
}
