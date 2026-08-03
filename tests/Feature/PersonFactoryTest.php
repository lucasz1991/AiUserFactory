<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\AutomationIndex;
use App\Livewire\Admin\Network\PersonFactoryIndex;
use App\Models\Person;
use App\Models\PersonBlueprint;
use App\Models\PersonWorkflowSchedule;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Ai\AiConnectionService;
use App\Services\Automation\AutomationLimitSettings;
use App\Services\Persons\PersonFactoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PersonFactoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->fakeAi();
    }

    public function test_the_factory_creates_a_draft_that_is_never_active_on_its_own(): void
    {
        $blueprint = $this->makeBlueprint();

        $person = app(PersonFactoryService::class)->createDraft($blueprint);

        $this->assertSame('draft', $person->approval_status);
        $this->assertFalse((bool) $person->is_active, 'Eine erzeugte Person darf nie sofort aktiv sein.');
        $this->assertSame('training', $person->bot_status);
        $this->assertSame($blueprint->id, (int) $person->person_blueprint_id);

        // Das AI-Profil wurde uebernommen.
        $this->assertSame('Nora', $person->person_first_name);
        $this->assertSame('Hamburg', $person->person_city);
        $this->assertSame(['Deutsch', 'Englisch'], $person->identity_profile['languages']);

        $blueprint->refresh();
        $this->assertSame(1, $blueprint->created_count);
        $this->assertSame(1, $blueprint->created_today);
    }

    public function test_a_draft_is_skipped_by_the_dispatcher_until_it_is_approved(): void
    {
        $blueprint = $this->makeBlueprint();
        $person = app(PersonFactoryService::class)->createDraft($blueprint);

        $this->assertTrue($person->is_draft);

        app(PersonFactoryService::class)->approve($person);
        $person->refresh();

        $this->assertSame('approved', $person->approval_status);
        $this->assertTrue((bool) $person->is_active);
        $this->assertSame('ready', $person->bot_status);
        $this->assertNotNull($person->approved_at);
    }

    public function test_approval_creates_the_schedules_from_the_blueprint_template(): void
    {
        $workflow = $this->makeWorkflow('Taeglicher Lauf');
        $blueprint = $this->makeBlueprint([
            'schedule_templates' => [[
                'workflow_id' => $workflow->id,
                'cadence_type' => PersonWorkflowSchedule::CADENCE_INTERVAL,
                'interval_minutes' => 360,
                'window_start' => '09:00',
                'window_end' => '21:00',
                'is_active' => true,
            ]],
        ]);

        $person = app(PersonFactoryService::class)->createDraft($blueprint);

        $this->assertSame(0, PersonWorkflowSchedule::query()->where('person_id', $person->id)->count());

        app(PersonFactoryService::class)->approve($person->fresh());

        $schedule = PersonWorkflowSchedule::query()->where('person_id', $person->id)->first();

        $this->assertNotNull($schedule, 'Die Freigabe muss die Zeitplaene der Vorlage anlegen.');
        $this->assertSame($workflow->id, (int) $schedule->workflow_id);
        $this->assertSame(360, (int) $schedule->interval_minutes);
        $this->assertNotNull($schedule->next_run_at);
    }

    public function test_a_rejected_draft_stays_inactive_and_switches_automation_off(): void
    {
        $blueprint = $this->makeBlueprint();
        $person = app(PersonFactoryService::class)->createDraft($blueprint);

        app(PersonFactoryService::class)->reject($person);
        $person->refresh();

        $this->assertSame('rejected', $person->approval_status);
        $this->assertFalse((bool) $person->is_active);
        $this->assertFalse((bool) $person->automation_enabled);
    }

    public function test_the_blueprint_stops_when_the_target_count_is_reached(): void
    {
        app(AutomationLimitSettings::class)->save(['factory_enabled' => true, 'factory_max_per_day' => 50]);

        $blueprint = $this->makeBlueprint(['is_active' => true, 'target_count' => 1, 'per_day' => 5]);

        app(PersonFactoryService::class)->runDueBlueprints();
        $this->assertSame(1, Person::query()->whereNotNull('person_blueprint_id')->count());

        app(PersonFactoryService::class)->runDueBlueprints();

        $this->assertSame(1, Person::query()->whereNotNull('person_blueprint_id')->count(), 'Die Zielzahl darf nicht ueberschritten werden.');
        $this->assertFalse((bool) $blueprint->fresh()->is_active);
    }

    public function test_the_factory_stays_silent_while_it_is_switched_off(): void
    {
        app(AutomationLimitSettings::class)->save(['factory_enabled' => false]);
        $this->makeBlueprint(['is_active' => true]);

        $summary = app(PersonFactoryService::class)->runDueBlueprints();

        $this->assertFalse($summary['enabled']);
        $this->assertSame(0, Person::query()->whereNotNull('person_blueprint_id')->count());
    }

    public function test_the_factory_page_lists_drafts_and_approves_them(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $blueprint = $this->makeBlueprint();
        $person = app(PersonFactoryService::class)->createDraft($blueprint);

        Livewire::test(PersonFactoryIndex::class)
            ->assertSee('Personen-Fabrik')
            ->assertSee($person->display_name)
            ->call('approve', $person->id);

        $this->assertSame('approved', $person->fresh()->approval_status);
    }

    public function test_the_automation_page_creates_a_schedule_and_computes_the_next_run(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $person = $this->makeApprovedPerson();
        $workflow = $this->makeWorkflow('Geplanter Lauf');

        Livewire::test(AutomationIndex::class)
            ->call('newSchedule')
            ->set('formPersonId', $person->id)
            ->set('formWorkflowId', $workflow->id)
            ->set('formCadence', PersonWorkflowSchedule::CADENCE_INTERVAL)
            ->set('formIntervalMinutes', 120)
            ->set('formJitterSeconds', 0)
            ->call('saveSchedule')
            ->assertHasNoErrors()
            ->assertSet('showScheduleModal', false);

        $schedule = PersonWorkflowSchedule::query()->where('person_id', $person->id)->first();

        $this->assertNotNull($schedule);
        $this->assertSame(120, (int) $schedule->interval_minutes);
        $this->assertNotNull($schedule->next_run_at);
        $this->assertTrue($schedule->next_run_at->greaterThan(now()));
    }

    public function test_the_emergency_stop_only_switches_execution_and_keeps_the_schedules(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        app(AutomationLimitSettings::class)->save(['enabled' => true]);

        $person = $this->makeApprovedPerson();
        $workflow = $this->makeWorkflow('Geplanter Lauf');
        $schedule = new PersonWorkflowSchedule([
            'cadence_type' => PersonWorkflowSchedule::CADENCE_INTERVAL,
            'interval_minutes' => 60,
            'is_active' => true,
        ]);
        $schedule->person_id = $person->id;
        $schedule->workflow_id = $workflow->id;
        $schedule->save();

        Livewire::test(AutomationIndex::class)->call('toggleEmergencyStop');

        $this->assertFalse((bool) app(AutomationLimitSettings::class)->get()['enabled']);
        $this->assertTrue((bool) $schedule->fresh()->is_active, 'Der Not-Aus darf keinen Zeitplan abschalten.');
    }

    public function test_bulk_assignment_creates_one_schedule_per_active_person(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->makeApprovedPerson();
        $this->makeApprovedPerson();
        $this->makeApprovedPerson(['is_active' => false]);

        $workflow = $this->makeWorkflow('Massenlauf');

        Livewire::test(AutomationIndex::class)
            ->call('openBulkModal')
            ->set('bulkWorkflowId', $workflow->id)
            ->set('bulkIntervalMinutes', 480)
            ->set('bulkOnlyActivePersons', true)
            ->call('applyBulk')
            ->assertHasNoErrors();

        $this->assertSame(2, PersonWorkflowSchedule::query()->where('workflow_id', $workflow->id)->count());
    }

    // ------------------------------------------------------------------ Helfer

    protected function fakeAi(): void
    {
        $this->mock(AiConnectionService::class, function ($mock): void {
            $mock->shouldReceive('json')->andReturn([
                'root' => [
                    'person_first_name' => 'Nora',
                    'person_last_name' => 'Brandt',
                    'person_city' => 'Hamburg',
                    'person_country' => 'Deutschland',
                    'person_timezone' => 'Europe/Berlin',
                ],
                'identity_profile' => [
                    'occupation' => 'Grafikerin',
                    'languages' => "Deutsch\nEnglisch",
                ],
                'bot_profile' => [
                    'communication_style' => 'freundlich, knapp',
                ],
            ]);
        });
    }

    protected function makeBlueprint(array $attributes = []): PersonBlueprint
    {
        return PersonBlueprint::query()->create(array_replace([
            'name' => 'Testbauplan',
            'is_active' => false,
            'platform' => 'instagram',
            'target_count' => 5,
            'per_day' => 2,
            'age_min' => 24,
            'age_max' => 38,
            'countries' => ['Deutschland'],
            'languages' => ['Deutsch'],
            'profile_prompt' => 'Kreative Persona aus Norddeutschland.',
            'generate_avatar' => false,
            'account_types' => ['instagram'],
        ], $attributes));
    }

    protected function makeApprovedPerson(array $attributes = []): Person
    {
        static $counter = 0;
        $counter++;

        return Person::create(array_replace([
            'platform' => 'instagram',
            'profile_key' => 'factory-person-'.$counter,
            'profile_label' => 'Factory Person '.$counter,
            'person_first_name' => 'Person',
            'person_timezone' => 'Europe/Berlin',
            'browser_profile_path' => 'browser-profiles/instagram/factory-'.$counter,
            'cookie_file_path' => 'cookies/factory-'.$counter.'.json',
            'is_active' => true,
            'automation_enabled' => true,
            'approval_status' => 'approved',
            'max_concurrent_workflow_runs' => 1,
        ], $attributes));
    }

    protected function makeWorkflow(string $name): Workflow
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
                'task_key' => 'data.workflow_return',
                'kind' => 'data',
                'runner' => 'node',
            ]]],
        ]);

        return $workflow->fresh();
    }
}
