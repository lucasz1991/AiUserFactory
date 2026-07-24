<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowRunPreview;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature R6: Im echten Ablauf zeigt die Vorschau nur die Ausgabe — keine
 * Screenshots, keinen Inspektor, keinen Cursor.
 *
 * Siehe README-Abschnitt „Feature R6".
 */
class WorkflowRunPreviewResultOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => true]));
    }

    public function test_a_real_run_marks_the_preview_result_only_and_shows_the_return(): void
    {
        $run = $this->makeRun(context: [], result: [
            'workflow_return' => ['treffer' => 3],
            'workflow_return_ok' => true,
        ]);

        Livewire::test(WorkflowRunPreview::class, ['workflowRunId' => $run->id])
            ->assertSet('workflowRunId', $run->id)
            ->assertViewHas('resultOnly', true)
            ->assertViewHas('workflowReturn', fn (array $return): bool => ($return['has'] ?? false) === true)
            ->assertSee('Echter Ablauf')
            ->assertSee('Rückgabewert');
    }

    public function test_a_studio_test_run_is_not_result_only(): void
    {
        $run = $this->makeRun(context: ['interactive_debug' => true], result: []);

        Livewire::test(WorkflowRunPreview::class, ['workflowRunId' => $run->id])
            ->assertViewHas('resultOnly', false);
    }

    public function test_real_playback_from_the_studio_is_result_only_despite_the_session(): void
    {
        $run = $this->makeRun(
            context: ['interactive_debug' => true, 'workflow_studio_session_id' => 4, 'real_playback' => true],
            result: [],
        );

        Livewire::test(WorkflowRunPreview::class, ['workflowRunId' => $run->id])
            ->assertViewHas('resultOnly', true);
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $result
     */
    private function makeRun(array $context, array $result): WorkflowRun
    {
        $workflow = Workflow::query()->create([
            'name' => 'Preview '.Str::random(6),
            'slug' => 'preview-'.Str::random(10),
            'is_active' => true,
            'settings_json' => ['dev_mode' => true],
        ]);
        $step = WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Liste',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'liste',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [
                ['key' => 'warten', 'title' => 'Warten', 'task_key' => 'wait.seconds', 'value' => 0],
            ]],
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'completed',
            'context_json' => $context,
            'result_json' => $result,
        ]);
        WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'completed',
            'result_json' => $result,
        ]);

        return $run;
    }
}
