<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\PersonWorkflowSchedule;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\Automation\AutomationLimitSettings;
use App\Services\Automation\PersonWorkflowDispatcher;
use App\Services\Automation\PersonWorkflowScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Die Pruefkette des Dispatchers, jede Ablehnung einzeln.
 */
class PersonWorkflowDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        app(AutomationLimitSettings::class)->save(['enabled' => true, 'max_concurrent_runs' => 10]);
    }

    public function test_a_due_schedule_starts_a_run_and_mirrors_the_person_id(): void
    {
        $person = $this->makePerson();
        $schedule = $this->makeSchedule($person, $this->makeWorkflow('Datenlauf'));

        $summary = app(PersonWorkflowDispatcher::class)->dispatchDue();

        $this->assertSame(1, $summary['started']);

        $run = WorkflowRun::query()->latest('id')->first();

        $this->assertNotNull($run);
        $this->assertSame($person->id, (int) $run->person_id, 'person_id muss als indizierter Spiegel gesetzt sein.');
        $this->assertSame($person->id, (int) data_get($run->context_json, 'person_id'));
        $this->assertSame(PersonWorkflowDispatcher::REQUESTED_BY, $run->requested_by);

        $schedule->refresh();
        $this->assertNotNull($schedule->last_run_at);
        $this->assertSame(1, $schedule->runs_today);
        $this->assertTrue($schedule->next_run_at->greaterThan(now()));
    }

    public function test_the_global_off_switch_stops_everything(): void
    {
        app(AutomationLimitSettings::class)->save(['enabled' => false]);

        $person = $this->makePerson();
        $this->makeSchedule($person, $this->makeWorkflow('Datenlauf'));

        $summary = app(PersonWorkflowDispatcher::class)->dispatchDue();

        $this->assertFalse($summary['enabled']);
        $this->assertSame(0, $summary['started']);
        $this->assertSame(0, WorkflowRun::query()->count());
    }

    /**
     * @dataProvider rejectionProvider
     */
    public function test_the_check_chain_rejects_with_a_reason(array $personAttributes, string $expected): void
    {
        $person = $this->makePerson($personAttributes);
        $schedule = $this->makeSchedule($person, $this->makeWorkflow('Datenlauf'));

        $summary = app(PersonWorkflowDispatcher::class)->dispatchDue();

        $this->assertSame(0, $summary['started']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertStringContainsString($expected, (string) $schedule->fresh()->last_skip_reason);
    }

    public static function rejectionProvider(): array
    {
        return [
            'inaktive Person' => [['is_active' => false], 'deaktiviert'],
            'Automatisierung aus' => [['automation_enabled' => false], 'ausgeschaltet'],
            'nicht freigegebener Entwurf' => [['approval_status' => 'draft'], 'Entwurf'],
        ];
    }

    public function test_a_blocked_person_is_skipped(): void
    {
        $person = $this->makePerson(['scrape_blocked_until' => now()->addHours(3)]);
        $schedule = $this->makeSchedule($person, $this->makeWorkflow('Datenlauf'));

        app(PersonWorkflowDispatcher::class)->dispatchDue();

        $this->assertStringContainsString('gesperrt', (string) $schedule->fresh()->last_skip_reason);
    }

    public function test_an_inactive_workflow_is_skipped(): void
    {
        $person = $this->makePerson();
        $workflow = $this->makeWorkflow('Deaktiviert');
        $workflow->forceFill(['is_active' => false])->save();
        $schedule = $this->makeSchedule($person, $workflow);

        app(PersonWorkflowDispatcher::class)->dispatchDue();

        $this->assertStringContainsString('deaktiviert', (string) $schedule->fresh()->last_skip_reason);
    }

    public function test_the_time_window_of_the_person_is_respected(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-03 03:00:00', 'Europe/Berlin'));

        $person = $this->makePerson(['person_timezone' => 'Europe/Berlin']);
        $schedule = $this->makeSchedule($person, $this->makeWorkflow('Datenlauf'), [
            'window_start' => '09:00',
            'window_end' => '17:00',
        ]);

        app(PersonWorkflowDispatcher::class)->dispatchDue();

        $this->assertStringContainsString('Zeitfenster', (string) $schedule->fresh()->last_skip_reason);

        CarbonImmutable::setTestNow();
    }

    public function test_the_daily_cap_of_the_schedule_holds(): void
    {
        $person = $this->makePerson();
        $schedule = $this->makeSchedule($person, $this->makeWorkflow('Datenlauf'), ['max_runs_per_day' => 1]);

        $schedule->forceFill([
            'runs_today' => 1,
            'runs_today_date' => now($person->automation_timezone)->toDateString(),
        ])->save();

        app(PersonWorkflowDispatcher::class)->dispatchDue();

        $this->assertStringContainsString('Tagesdeckel', (string) $schedule->fresh()->last_skip_reason);
    }

    public function test_the_person_concurrency_limit_holds(): void
    {
        $person = $this->makePerson(['max_concurrent_workflow_runs' => 1]);
        $this->makeRunningRun($person, $this->makeWorkflow('Laeuft schon'));

        $schedule = $this->makeSchedule($person, $this->makeWorkflow('Datenlauf'));

        app(PersonWorkflowDispatcher::class)->dispatchDue();

        $this->assertStringContainsString('faehrt bereits', (string) $schedule->fresh()->last_skip_reason);
    }

    /**
     * Der Kern der Nebenlaeufigkeits-Absicherung: auch mit erhoehtem Limit darf
     * kein zweiter browsergebundener Lauf derselben Person starten, weil
     * Browserprofil, Cookie-Datei und Session nur einmal existieren.
     */
    public function test_browser_bound_runs_stay_exclusive_even_with_a_higher_limit(): void
    {
        $person = $this->makePerson(['max_concurrent_workflow_runs' => 5]);
        $this->makeRunningRun($person, $this->makeWorkflow('Browser laeuft', 'browser.open_url', 'browser'));

        $schedule = $this->makeSchedule($person, $this->makeWorkflow('Noch ein Browser', 'browser.open_url', 'browser'));

        app(PersonWorkflowDispatcher::class)->dispatchDue();

        $this->assertStringContainsString('browsergebundener Lauf', (string) $schedule->fresh()->last_skip_reason);
    }

    public function test_a_data_workflow_may_run_next_to_a_browser_run_when_the_limit_allows_it(): void
    {
        $person = $this->makePerson(['max_concurrent_workflow_runs' => 3]);
        $this->makeRunningRun($person, $this->makeWorkflow('Browser laeuft', 'browser.open_url', 'browser'));

        $this->makeSchedule($person, $this->makeWorkflow('Reine Daten'));

        $summary = app(PersonWorkflowDispatcher::class)->dispatchDue();

        $this->assertSame(1, $summary['started']);
    }

    public function test_a_skipped_schedule_is_rescheduled_and_never_dropped(): void
    {
        $person = $this->makePerson(['is_active' => false]);
        $schedule = $this->makeSchedule($person, $this->makeWorkflow('Datenlauf'));
        $before = $schedule->next_run_at;

        app(PersonWorkflowDispatcher::class)->dispatchDue();
        $schedule->refresh();

        $this->assertTrue($schedule->is_active, 'Eine Ablehnung darf den Zeitplan nicht abschalten.');
        $this->assertTrue($schedule->next_run_at->greaterThan($before));
    }

    // ------------------------------------------------------------------ Helfer

    protected function makePerson(array $attributes = []): Person
    {
        static $counter = 0;
        $counter++;

        return Person::create(array_replace([
            'platform' => 'instagram',
            'profile_key' => 'dispatch-persona-'.$counter,
            'profile_label' => 'Dispatch Persona '.$counter,
            'person_first_name' => 'Nora',
            'person_timezone' => 'Europe/Berlin',
            'browser_profile_path' => 'browser-profiles/instagram/dispatch-'.$counter,
            'cookie_file_path' => 'cookies/dispatch-'.$counter.'.json',
            'is_active' => true,
            'automation_enabled' => true,
            'approval_status' => 'approved',
            'max_concurrent_workflow_runs' => 1,
        ], $attributes));
    }

    protected function makeWorkflow(string $name, string $taskKey = 'data.workflow_return', string $kind = 'data'): Workflow
    {
        $workflow = Workflow::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
            'is_active' => true,
        ]);

        WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Liste',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'list',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'task',
                'title' => $name,
                'runner' => 'node',
                'task_key' => $taskKey,
                'kind' => $kind,
                'node_script' => 'node/workflows/tasks/data/workflow_return.cjs',
            ]]],
        ]);

        return $workflow->fresh();
    }

    protected function makeSchedule(Person $person, Workflow $workflow, array $attributes = []): PersonWorkflowSchedule
    {
        $normalized = app(PersonWorkflowScheduleService::class)->normalize(array_replace([
            'cadence_type' => PersonWorkflowSchedule::CADENCE_INTERVAL,
            'interval_minutes' => 60,
        ], $attributes));

        $schedule = new PersonWorkflowSchedule($normalized);
        $schedule->person_id = $person->id;
        $schedule->workflow_id = $workflow->id;
        // Bewusst dieselbe Uhrenquelle wie der Dispatcher: sonst faellt der
        // Zeitplan bei gesetzter Testzeit aus der Faelligkeitsabfrage heraus.
        $schedule->next_run_at = CarbonImmutable::now()->subMinute();
        $schedule->save();

        return $schedule;
    }

    protected function makeRunningRun(Person $person, Workflow $workflow): WorkflowRun
    {
        return WorkflowRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'person_id' => $person->id,
            'status' => 'running',
            'requested_by' => PersonWorkflowDispatcher::REQUESTED_BY,
            'queued_at' => now(),
            'context_json' => ['person_id' => $person->id],
            'result_json' => [],
        ]);
    }
}
