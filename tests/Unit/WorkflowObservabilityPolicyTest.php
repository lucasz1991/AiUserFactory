<?php

namespace Tests\Unit;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\Workflows\WorkflowObservabilityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature R6: Beobachtungsdaten entstehen nur beim Erstellen/Testen, nie im
 * echten Ablauf. Diese Tests nageln die Stufen-Ableitung fest.
 *
 * Siehe README-Abschnitt „Feature R6".
 */
class WorkflowObservabilityPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plain_triggered_run_is_off_and_result_only(): void
    {
        $run = $this->makeRun([], devMode: false);
        $policy = app(WorkflowObservabilityPolicy::class);

        $this->assertSame('off', $policy->level($run));
        $this->assertTrue($policy->resultOnly($run));
        $this->assertFalse($policy->capturesScreenshots($run));
        $this->assertFalse($policy->capturesDom($run));
        $this->assertFalse($policy->showsCursor($run));
        $this->assertFalse($policy->keepsArtifacts($run));
    }

    public function test_dev_mode_does_not_elevate_a_real_run(): void
    {
        // Der entscheidende Fall: ein Workflow mit dev_mode, aber ein echter
        // Trigger-Lauf (keine Sitzung) — darf trotzdem nichts sammeln.
        $run = $this->makeRun([], devMode: true);

        $this->assertSame('off', app(WorkflowObservabilityPolicy::class)->level($run));
    }

    public function test_a_studio_test_run_without_dev_mode_is_preview(): void
    {
        $run = $this->makeRun(['interactive_debug' => true], devMode: false);
        $policy = app(WorkflowObservabilityPolicy::class);

        $this->assertSame('preview', $policy->level($run));
        $this->assertTrue($policy->capturesScreenshots($run));
        $this->assertTrue($policy->showsCursor($run));
        // preview beobachtet, sammelt aber keine schweren DOM-/Artefaktdaten.
        $this->assertFalse($policy->capturesDom($run));
        $this->assertFalse($policy->keepsArtifacts($run));
        $this->assertFalse($policy->resultOnly($run));
    }

    public function test_a_studio_test_run_with_dev_mode_is_debug(): void
    {
        $run = $this->makeRun(['interactive_debug' => true], devMode: true);
        $policy = app(WorkflowObservabilityPolicy::class);

        $this->assertSame('debug', $policy->level($run));
        $this->assertTrue($policy->capturesDom($run));
        $this->assertTrue($policy->keepsArtifacts($run));
    }

    public function test_a_studio_session_id_alone_marks_a_test_run(): void
    {
        $run = $this->makeRun(['workflow_studio_session_id' => 42], devMode: false);

        $this->assertSame('preview', app(WorkflowObservabilityPolicy::class)->level($run));
    }

    public function test_a_copilot_run_is_the_highest_level(): void
    {
        $run = $this->makeRun(['interactive_debug' => true], devMode: false, copilotSessionId: 7);
        $policy = app(WorkflowObservabilityPolicy::class);

        $this->assertSame('copilot', $policy->level($run));
        $this->assertTrue($policy->capturesDom($run));
        $this->assertTrue($policy->keepsArtifacts($run));
    }

    public function test_real_playback_forces_off_even_inside_the_studio(): void
    {
        // Studio-Aktion „Echter Ablauf": interactive_debug bleibt gesetzt (der
        // Lauf ist im Studio sichtbar), real_playback erzwingt trotzdem off.
        $run = $this->makeRun([
            'interactive_debug' => true,
            'workflow_studio_session_id' => 9,
            'real_playback' => true,
        ], devMode: true);
        $policy = app(WorkflowObservabilityPolicy::class);

        $this->assertSame('off', $policy->level($run));
        $this->assertTrue($policy->resultOnly($run));
        $this->assertFalse($policy->capturesScreenshots($run));
    }

    public function test_real_playback_beats_a_copilot_session_too(): void
    {
        $run = $this->makeRun(['real_playback' => true], devMode: false, copilotSessionId: 3);

        $this->assertSame('off', app(WorkflowObservabilityPolicy::class)->level($run));
    }

    public function test_at_least_compares_by_rank(): void
    {
        $policy = app(WorkflowObservabilityPolicy::class);
        $preview = $this->makeRun(['interactive_debug' => true], devMode: false);
        $off = $this->makeRun([], devMode: false);

        $this->assertTrue($policy->atLeast($preview, 'preview'));
        $this->assertFalse($policy->atLeast($preview, 'debug'));
        $this->assertFalse($policy->atLeast($off, 'preview'));
        $this->assertTrue($policy->atLeast($off, 'off'));
    }

    /** @param array<string,mixed> $context */
    private function makeRun(array $context, bool $devMode, int $copilotSessionId = 0): WorkflowRun
    {
        $workflow = Workflow::query()->create([
            'name' => 'Obs '.Str::random(6),
            'slug' => 'obs-'.Str::random(10),
            'is_active' => true,
            'settings_json' => $devMode ? ['dev_mode' => true] : [],
        ]);

        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'running',
            'context_json' => $context,
            'workflow_copilot_session_id' => $copilotSessionId ?: null,
        ]);

        return $run->load('workflow');
    }
}
