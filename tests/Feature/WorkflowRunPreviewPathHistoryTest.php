<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowRunPreview;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Workflows\WorkflowExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Feature R3: Der zurueckgelegte Laufweg bleibt in der Vorschau sichtbar, auch
 * wenn der Workflow in eine bereits gelaufene Liste zurueckspringt.
 *
 * Siehe README-Abschnitt „Feature R3".
 */
class WorkflowRunPreviewPathHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_completing_a_step_freezes_its_task_results_into_the_run_history(): void
    {
        [$run, $stepRun] = $this->scenario();

        $this->completeStepRun($stepRun, ['tasks' => [
            ['key' => 'start', 'title' => 'Start', 'status' => 'completed'],
            ['key' => 'mitte', 'title' => 'Mitte', 'status' => 'completed'],
        ]]);

        $history = collect(data_get($run->fresh()->context_json, 'task_history', []));

        $this->assertCount(2, $history);
        $this->assertSame(['start', 'mitte'], $history->pluck('task_key')->all());
        $this->assertSame([1, 2], $history->pluck('seq')->all());
        $this->assertSame((int) $stepRun->workflow_step_id, (int) $history->first()['workflow_step_id']);
    }

    public function test_the_sequence_keeps_counting_across_repeated_passes(): void
    {
        [$run, $stepRun] = $this->scenario();

        $this->completeStepRun($stepRun, ['tasks' => [['key' => 'start', 'status' => 'completed']]]);
        $this->completeStepRun($stepRun->fresh(), ['tasks' => [['key' => 'start', 'status' => 'failed']]]);

        $history = collect(data_get($run->fresh()->context_json, 'task_history', []));

        $this->assertSame([1, 2], $history->pluck('seq')->all());
        $this->assertSame(['completed', 'failed'], $history->pluck('status')->all());
    }

    public function test_a_backward_jump_no_longer_erases_the_markings_of_the_list(): void
    {
        [$run, $stepRun] = $this->scenario();

        // Erster Durchlauf: beide Tasks laufen.
        $this->completeStepRun($stepRun, ['tasks' => [
            ['key' => 'start', 'title' => 'Start', 'status' => 'completed'],
            ['key' => 'mitte', 'title' => 'Mitte', 'status' => 'completed'],
        ]]);

        // Ruecksprung: der neue Lauf enthaelt nur noch die Task ab dem Sprungziel.
        // Der aktuelle Snapshot kennt 'start' damit nicht mehr.
        $panels = collect([[
            'debug' => ['workflowStepId' => (int) $stepRun->workflow_step_id],
            'status' => 'running',
            'tasks' => [['key' => 'mitte', 'title' => 'Mitte', 'status' => 'running']],
        ]]);

        $tasks = collect($this->compactWorkflowMap($run->fresh(), $panels)->first()['tasks'])->keyBy('key');

        // Ohne die Historie waere 'start' hier auf den Template-Zustand
        // zurueckgefallen und die Liste saehe aus, als sei sie nie gelaufen.
        $this->assertSame('completed', $tasks['start']['status']);
        $this->assertSame(1, $tasks['start']['passes']);
        $this->assertGreaterThanOrEqual(45, $tasks['start']['freshness'], 'Die aeltere Markierung bleibt sichtbar.');
        $this->assertSame('running', $tasks['mitte']['status']);
    }

    public function test_untouched_tasks_stay_pending_and_are_distinguishable(): void
    {
        [$run, $stepRun] = $this->scenario();

        $this->completeStepRun($stepRun, ['tasks' => [['key' => 'start', 'status' => 'completed']]]);

        $tasks = collect($this->compactWorkflowMap($run->fresh())->first()['tasks'])->keyBy('key');

        // Nie gelaufene Tasks behalten ihren Template-Zustand und melden keinen
        // Durchlauf — nur daran unterscheidet die Oberflaeche „noch nicht" von
        // „uebersprungen".
        $this->assertNotContains($tasks['ende']['status'], ['completed', 'success', 'failed', 'timeout']);
        $this->assertSame(0, $tasks['ende']['passes'], 'Nie gelaufene Tasks duerfen keinen Durchlauf melden.');
        $this->assertSame(0, $tasks['ende']['freshness']);
        $this->assertSame(1, $tasks['start']['passes'], 'Die gelaufene Task meldet ihren Durchlauf.');
    }

    public function test_freshness_fades_older_markings_but_never_hides_them(): void
    {
        $preview = new WorkflowRunPreview;
        $method = new ReflectionMethod($preview, 'markerFreshness');
        $method->setAccessible(true);

        $oldest = $method->invoke($preview, 9401, 9401, 10000, false);
        $newest = $method->invoke($preview, 10000, 9401, 10000, false);
        $active = $method->invoke($preview, 9401, 9401, 10000, true);

        $this->assertGreaterThan($oldest, $newest, 'Juengere Markierungen muessen kraeftiger sein.');
        $this->assertGreaterThanOrEqual(45, $oldest, 'Auch die aelteste Markierung bleibt deutlich sichtbar.');
        $this->assertSame(100, $newest);
        $this->assertSame(100, $active, 'Der aktive Task ist immer voll deckend.');
        $this->assertSame(0, $method->invoke($preview, null, 9401, 10000, false), 'Nie gelaufen = keine Frische.');
    }

    public function test_minimap_snapshot_without_status_keeps_the_historical_marker(): void
    {
        [$run, $stepRun] = $this->scenario();
        $this->completeStepRun($stepRun, ['tasks' => [
            ['key' => 'start', 'title' => 'Start', 'status' => 'completed'],
            ['key' => 'mitte', 'title' => 'Mitte', 'status' => 'completed'],
        ]]);
        $stepRun->fresh()->forceFill([
            'status' => 'running',
            'result_json' => ['tasks' => [
                ['key' => 'start', 'title' => 'Start'],
                ['key' => 'mitte', 'title' => 'Mitte', 'status' => 'running'],
            ]],
        ])->save();

        $html = view('components.workflows.minimap', [
            'workflowRun' => $run->fresh()->load(['workflow.steps', 'stepRuns']),
        ])->render();

        $this->assertMatchesRegularExpression(
            '/data-minimap-node="liste-eins::start"[^>]+data-workflow-task-status="completed"/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/data-minimap-node="liste-eins::mitte"[^>]+data-workflow-task-status="running"/',
            $html,
        );
    }

    public function test_the_minimap_no_longer_caps_the_drawn_route_lines(): void
    {
        $markup = file_get_contents(resource_path('views/components/workflows/minimap.blade.php'));

        $this->assertStringNotContainsString(
            '$routeEvents->take(-16)',
            $markup,
            'Die harte Begrenzung auf 16 Linien war die Ursache dafuer, dass Linien beim Ruecksprung verschwanden.',
        );
        $this->assertStringContainsString('ageOpacity', $markup, 'Das Alter muss die Deckkraft steuern.');
        $this->assertStringNotContainsString('slice(max(0, $routeEvents->count() - 8))', $markup);
    }

    private function compactWorkflowMap(WorkflowRun $run, ?Collection $panels = null): Collection
    {
        $preview = new WorkflowRunPreview;
        $method = new ReflectionMethod($preview, 'compactWorkflowMap');
        $method->setAccessible(true);

        return $method->invoke($preview, $run->load(['workflow.steps', 'stepRuns']), $panels ?? collect());
    }

    private function completeStepRun(WorkflowStepRun $stepRun, array $result): void
    {
        $service = app(WorkflowExecutionService::class);
        $method = new ReflectionMethod($service, 'completeStepRun');
        $method->setAccessible(true);
        $method->invoke($service, $stepRun->load(['workflowRun', 'workflowStep']), $result);
    }

    /** @return array{0: WorkflowRun, 1: WorkflowStepRun} */
    private function scenario(): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'Vorschau '.Str::random(6),
            'slug' => 'vorschau-'.Str::random(10),
            'is_active' => true,
        ]);
        $step = WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Liste eins',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'liste-eins',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [
                ['key' => 'start', 'title' => 'Start', 'task_key' => 'wait.seconds', 'value' => 0],
                ['key' => 'mitte', 'title' => 'Mitte', 'task_key' => 'wait.seconds', 'value' => 0],
                ['key' => 'ende', 'title' => 'Ende', 'task_key' => 'wait.seconds', 'value' => 0],
            ]],
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'running',
            'context_json' => [],
        ]);
        $stepRun = WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'running',
        ]);

        return [$run, $stepRun];
    }
}
