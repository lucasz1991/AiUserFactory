<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Feature R6: Der TaskRunner traegt die Observability-Stufe in die Runtime und
 * schaltet im echten Ablauf die Beobachtung strukturell ab.
 *
 * Siehe README-Abschnitt „Feature R6".
 */
class WorkflowTaskRunnerObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_run_carries_off_and_disables_capture(): void
    {
        // dev_mode am Workflow, aber echter Lauf ohne Sitzung -> trotzdem off.
        $runtime = $this->remoteRuntime(context: [], devMode: true);

        $this->assertSame('off', data_get($runtime, 'observability.level'));
        $this->assertTrue(data_get($runtime, 'observability.resultOnly'));
        $this->assertFalse(data_get($runtime, 'observability.capturesScreenshots'));
        $this->assertFalse((bool) data_get($runtime, 'livePreviewEnabled'));

        // Lokaler Artefaktpfad: auch dort kein DOM/Artefakt trotz dev_mode.
        $dev = $this->localDevDebug(context: [], devMode: true);
        $this->assertFalse((bool) data_get($dev, 'captureDomBeforeStep'));
        $this->assertFalse((bool) data_get($dev, 'keepArtifacts'));
        $this->assertSame('off', data_get($dev, 'level'));
    }

    public function test_a_studio_test_run_enables_preview_but_not_dom(): void
    {
        $runtime = $this->remoteRuntime(context: ['interactive_debug' => true], devMode: false);

        $this->assertSame('preview', data_get($runtime, 'observability.level'));
        $this->assertTrue(data_get($runtime, 'observability.capturesScreenshots'));
        $this->assertTrue((bool) data_get($runtime, 'livePreviewEnabled'));

        $dev = $this->localDevDebug(context: ['interactive_debug' => true], devMode: false);
        $this->assertFalse((bool) data_get($dev, 'captureDomBeforeStep'));
    }

    public function test_a_studio_dev_run_enables_dom_and_artifacts(): void
    {
        $runtime = $this->remoteRuntime(context: ['interactive_debug' => true], devMode: true);
        $this->assertSame('debug', data_get($runtime, 'observability.level'));

        // Die DOM-/Artefakt-Flags gehoeren zum lokalen start()-Pfad; der
        // ClientController-Pfad (remoteRuntime) traegt bewusst keine lokalen
        // Artefakte. Die dev_capture_*-Schalter setzt die UI beim Speichern
        // explizit — unkonfiguriert bleiben sie aus.
        $dev = $this->localDevDebug(
            context: ['interactive_debug' => true],
            devMode: true,
            captureFlags: true,
        );
        $this->assertSame('debug', data_get($dev, 'level'));
        $this->assertTrue((bool) data_get($dev, 'captureDomBeforeStep'));
        $this->assertTrue((bool) data_get($dev, 'keepArtifacts'));
    }

    public function test_real_playback_from_the_studio_is_off_despite_the_session(): void
    {
        $runtime = $this->remoteRuntime(
            context: ['interactive_debug' => true, 'workflow_studio_session_id' => 5, 'real_playback' => true],
            devMode: true,
        );

        $this->assertSame('off', data_get($runtime, 'observability.level'));
        $this->assertFalse((bool) data_get($runtime, 'livePreviewEnabled'));

        $dev = $this->localDevDebug(
            context: ['interactive_debug' => true, 'workflow_studio_session_id' => 5, 'real_playback' => true],
            devMode: true,
        );
        $this->assertFalse((bool) data_get($dev, 'captureDomBeforeStep'));
    }

    /**
     * Ruft den geschuetzten `devDebugRuntimeConfig()` mit lokalem Artefaktpfad
     * (`start()`-Verhalten), ohne einen Node-Prozess zu spawnen.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function localDevDebug(array $context, bool $devMode, bool $captureFlags = false): array
    {
        [$run, $step, $stepRun] = $this->scenario($context, $devMode, $captureFlags);
        $runner = app(WorkflowTaskRunner::class);
        $method = new ReflectionMethod($runner, 'devDebugRuntimeConfig');
        $method->setAccessible(true);

        return $method->invoke($runner, $run, $step, $stepRun, true);
    }

    /** @param array<string,mixed> $context */
    private function remoteRuntime(array $context, bool $devMode): array
    {
        [$run, $step, $stepRun] = $this->scenario($context, $devMode);

        return app(WorkflowTaskRunner::class)->remoteRuntime($run, $step, $stepRun, $context);
    }

    /**
     * @param array<string,mixed> $context
     * @return array{0: WorkflowRun, 1: WorkflowStep, 2: WorkflowStepRun}
     */
    private function scenario(array $context, bool $devMode, bool $captureFlags = false): array
    {
        $settings = $devMode ? ['dev_mode' => true] : [];

        if ($captureFlags) {
            $settings += [
                'dev_capture_dom_before_step' => true,
                'dev_capture_dom_after_step' => true,
                'dev_capture_screenshot_before_step' => true,
                'dev_capture_screenshot_after_step' => true,
                'dev_keep_artifacts' => true,
            ];
        }

        $workflow = Workflow::query()->create([
            'name' => 'Runtime '.Str::random(6),
            'slug' => 'runtime-'.Str::random(10),
            'is_active' => true,
            'settings_json' => $settings,
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
            'status' => 'running',
            'context_json' => $context,
        ]);
        $stepRun = WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'running',
        ]);

        return [$run->load('workflow'), $step, $stepRun];
    }
}
