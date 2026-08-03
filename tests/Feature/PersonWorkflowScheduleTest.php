<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\PersonWorkflowSchedule;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Automation\PersonWorkflowScheduleService;
use App\Services\Automation\WorkflowBrowserBinding;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Taktberechnung und Browser-Erkennung.
 *
 * Alle Zeitfenster gelten in der Ortszeit der Person — ohne das wuerden Personas
 * aus anderen Zeitzonen mitten in ihrer Nacht aktiv.
 */
class PersonWorkflowScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_interval_cadence_adds_the_configured_minutes(): void
    {
        $person = $this->makePerson(['person_timezone' => 'Europe/Berlin']);
        $schedule = $this->makeSchedule($person, [
            'cadence_type' => PersonWorkflowSchedule::CADENCE_INTERVAL,
            'interval_minutes' => 90,
        ]);

        $next = $this->service()->computeNextRunAt(
            $schedule,
            $person,
            CarbonImmutable::parse('2026-08-03 10:00:00', 'Europe/Berlin'),
            applyJitter: false,
        );

        $this->assertSame('2026-08-03 11:30', $next->setTimezone('Europe/Berlin')->format('Y-m-d H:i'));
    }

    public function test_daily_times_pick_the_next_upcoming_slot(): void
    {
        $person = $this->makePerson(['person_timezone' => 'Europe/Berlin']);
        $schedule = $this->makeSchedule($person, [
            'cadence_type' => PersonWorkflowSchedule::CADENCE_DAILY_TIMES,
            'daily_times' => ['08:00', '13:30', '19:45'],
        ]);

        $next = $this->service()->computeNextRunAt(
            $schedule,
            $person,
            CarbonImmutable::parse('2026-08-03 12:00:00', 'Europe/Berlin'),
            applyJitter: false,
        );

        $this->assertSame('2026-08-03 13:30', $next->setTimezone('Europe/Berlin')->format('Y-m-d H:i'));
    }

    public function test_daily_times_roll_over_to_the_next_day_after_the_last_slot(): void
    {
        $person = $this->makePerson(['person_timezone' => 'Europe/Berlin']);
        $schedule = $this->makeSchedule($person, [
            'cadence_type' => PersonWorkflowSchedule::CADENCE_DAILY_TIMES,
            'daily_times' => ['08:00'],
        ]);

        $next = $this->service()->computeNextRunAt(
            $schedule,
            $person,
            CarbonImmutable::parse('2026-08-03 21:00:00', 'Europe/Berlin'),
            applyJitter: false,
        );

        $this->assertSame('2026-08-04 08:00', $next->setTimezone('Europe/Berlin')->format('Y-m-d H:i'));
    }

    public function test_the_time_window_belongs_to_the_timezone_of_the_person(): void
    {
        // 09:00 Ortszeit in New York ist 15:00 in Berlin. Wuerde das Fenster in
        // Serverzeit gelten, liefe die Persona zur falschen Tageszeit.
        $person = $this->makePerson(['person_timezone' => 'America/New_York']);
        $schedule = $this->makeSchedule($person, [
            'cadence_type' => PersonWorkflowSchedule::CADENCE_INTERVAL,
            'interval_minutes' => 60,
            'window_start' => '09:00',
            'window_end' => '17:00',
        ]);

        $next = $this->service()->computeNextRunAt(
            $schedule,
            $person,
            CarbonImmutable::parse('2026-08-03 03:00:00', 'America/New_York'),
            applyJitter: false,
        );

        $local = $next->setTimezone('America/New_York');

        $this->assertSame('09:00', $local->format('H:i'));
        $this->assertSame('2026-08-03', $local->format('Y-m-d'));
    }

    public function test_a_candidate_after_the_window_moves_to_the_next_day(): void
    {
        $person = $this->makePerson(['person_timezone' => 'Europe/Berlin']);
        $schedule = $this->makeSchedule($person, [
            'cadence_type' => PersonWorkflowSchedule::CADENCE_INTERVAL,
            'interval_minutes' => 120,
            'window_start' => '09:00',
            'window_end' => '17:00',
        ]);

        $next = $this->service()->computeNextRunAt(
            $schedule,
            $person,
            CarbonImmutable::parse('2026-08-03 16:30:00', 'Europe/Berlin'),
            applyJitter: false,
        );

        $this->assertSame('2026-08-04 09:00', $next->setTimezone('Europe/Berlin')->format('Y-m-d H:i'));
    }

    public function test_weekdays_skip_to_the_next_allowed_day(): void
    {
        // 2026-08-03 ist ein Montag; erlaubt sind nur Samstag (6) und Sonntag (7).
        $person = $this->makePerson(['person_timezone' => 'Europe/Berlin']);
        $schedule = $this->makeSchedule($person, [
            'cadence_type' => PersonWorkflowSchedule::CADENCE_INTERVAL,
            'interval_minutes' => 60,
            'weekdays' => [6, 7],
        ]);

        $next = $this->service()->computeNextRunAt(
            $schedule,
            $person,
            CarbonImmutable::parse('2026-08-03 10:00:00', 'Europe/Berlin'),
            applyJitter: false,
        );

        $this->assertSame(6, (int) $next->setTimezone('Europe/Berlin')->isoWeekday());
    }

    public function test_the_activity_plan_of_the_person_can_drive_the_cadence(): void
    {
        $person = $this->makePerson([
            'person_timezone' => 'Europe/Berlin',
            'metadata' => [
                'internal_activity_simulation' => [
                    'days_plan' => [[
                        'date' => '2026-08-03',
                        'sessions' => [
                            ['session_type' => 'morning_scroll', 'starts_at_local' => '07:30'],
                            ['session_type' => 'evening_post', 'starts_at_local' => '20:15'],
                        ],
                    ]],
                ],
            ],
        ]);

        $schedule = $this->makeSchedule($person, [
            'cadence_type' => PersonWorkflowSchedule::CADENCE_ACTIVITY_PLAN,
            'activity_plan_session_types' => ['evening_post'],
        ]);

        $next = $this->service()->computeNextRunAt(
            $schedule,
            $person,
            CarbonImmutable::parse('2026-08-03 06:00:00', 'Europe/Berlin'),
            applyJitter: false,
        );

        // Der Morgenslot wird durch den Filter ausgeschlossen.
        $this->assertSame('2026-08-03 20:15', $next->setTimezone('Europe/Berlin')->format('Y-m-d H:i'));
    }

    public function test_context_rejects_secrets_and_a_foreign_person_id(): void
    {
        $normalized = $this->service()->normalize([
            'cadence_type' => PersonWorkflowSchedule::CADENCE_INTERVAL,
            'interval_minutes' => 60,
            'context_json' => [
                'search_term' => 'kaffee',
                'password' => 'darf-nicht-durch',
                'api_token' => 'auch-nicht',
                'person_id' => 999,
            ],
        ]);

        $this->assertSame(['search_term' => 'kaffee'], $normalized['context_json']);
    }

    public function test_all_seven_weekdays_are_stored_as_no_restriction(): void
    {
        $normalized = $this->service()->normalize([
            'cadence_type' => PersonWorkflowSchedule::CADENCE_INTERVAL,
            'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        ]);

        $this->assertSame([], $normalized['weekdays']);
    }

    public function test_browser_binding_detects_browser_input_and_embedded_workflows(): void
    {
        $binding = app(WorkflowBrowserBinding::class);

        $dataOnly = $this->makeWorkflow('Nur Daten', [
            ['task_key' => 'data.workflow_return', 'kind' => 'data'],
        ]);
        $browser = $this->makeWorkflow('Mit Browser', [
            ['task_key' => 'browser.open_url', 'kind' => 'browser'],
        ]);
        $inputOnly = $this->makeWorkflow('Nur Eingabe', [
            ['task_key' => 'input.fill_field', 'kind' => 'input'],
        ]);

        $this->assertFalse($binding->requiresBrowser($dataOnly));
        $this->assertTrue($binding->requiresBrowser($browser));
        $this->assertTrue($binding->requiresBrowser($inputOnly));

        // Eingebetteter Browser-Workflow faerbt den Elternworkflow ein.
        $parent = $this->makeWorkflow('Huelle', [
            ['task_key' => 'data.validate_inputs', 'kind' => 'data'],
            ['task_key' => 'workflow.include.'.$browser->id, 'runner' => 'workflow', 'workflow_id' => $browser->id],
        ]);

        $binding->forget();
        $this->assertTrue($binding->requiresBrowser($parent));
    }

    public function test_browser_binding_survives_a_workflow_cycle(): void
    {
        $first = $this->makeWorkflow('Erster', [['task_key' => 'data.workflow_return', 'kind' => 'data']]);
        $second = $this->makeWorkflow('Zweiter', [['task_key' => 'data.workflow_return', 'kind' => 'data']]);

        $this->attachEmbedded($first, $second);
        $this->attachEmbedded($second, $first);

        $binding = app(WorkflowBrowserBinding::class);

        $this->assertFalse($binding->requiresBrowser($first));
    }

    protected function service(): PersonWorkflowScheduleService
    {
        return app(PersonWorkflowScheduleService::class);
    }

    protected function makePerson(array $attributes = []): Person
    {
        static $counter = 0;
        $counter++;

        return Person::create(array_replace([
            'platform' => 'instagram',
            'profile_key' => 'persona-'.$counter,
            'profile_label' => 'Persona '.$counter,
            'person_first_name' => 'Nora',
            'person_last_name' => 'Brandt',
            'browser_profile_path' => 'browser-profiles/instagram/persona-'.$counter,
            'cookie_file_path' => 'cookies/persona-'.$counter.'.json',
            'is_active' => true,
            'automation_enabled' => true,
            'approval_status' => 'approved',
            'max_concurrent_workflow_runs' => 1,
        ], $attributes));
    }

    protected function makeSchedule(Person $person, array $attributes = []): PersonWorkflowSchedule
    {
        $workflow = $this->makeWorkflow('Zeitplan-Workflow '.uniqid(), [
            ['task_key' => 'data.workflow_return', 'kind' => 'data'],
        ]);

        $schedule = new PersonWorkflowSchedule(array_replace(
            $this->service()->normalize($attributes),
            ['person_id' => $person->id, 'workflow_id' => $workflow->id],
        ));
        $schedule->person_id = $person->id;
        $schedule->workflow_id = $workflow->id;
        $schedule->save();

        return $schedule;
    }

    protected function makeWorkflow(string $name, array $tasks): Workflow
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
            'config_json' => ['tasks' => $tasks],
        ]);

        return $workflow->fresh();
    }

    protected function attachEmbedded(Workflow $parent, Workflow $child): void
    {
        $step = $parent->steps()->first();
        $config = $step->config_json;
        $config['tasks'][] = [
            'task_key' => 'workflow.include.'.$child->id,
            'runner' => 'workflow',
            'workflow_id' => $child->id,
        ];
        $step->forceFill(['config_json' => $config])->save();
    }
}
