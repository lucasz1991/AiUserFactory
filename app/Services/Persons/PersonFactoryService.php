<?php

namespace App\Services\Persons;

use App\Jobs\GeneratePersonImages;
use App\Models\Person;
use App\Models\PersonBlueprint;
use App\Models\PersonWorkflowSchedule;
use App\Models\Workflow;
use App\Services\Automation\AutomationLimitSettings;
use App\Services\Automation\PersonWorkflowScheduleService;
use App\Services\Workflows\WorkflowExecutionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Personen-Fabrik.
 *
 * Ein Bauplan erzeugt Personen ausschliesslich als **Entwuerfe**
 * (`is_active = false`, `approval_status = 'draft'`, `bot_status = 'training'`).
 * Die Aktivierung ist ein bewusster manueller Schritt: erst die Freigabe setzt
 * die Person aktiv, legt ihre Zeitplaene an und startet den Onboarding-Workflow.
 *
 * Die erzeugten Zeilen sind normale Personen mit `platform` und `profile_key`.
 * Sie werden dadurch von `ScraperProfileDatabaseStore::loadProfileCollection()`
 * mitgelesen und ueberleben ein spaeteres Speichern der Personenliste.
 */
class PersonFactoryService
{
    public function __construct(
        protected PersonProfileComposer $composer,
        protected PersonAccountRegistry $accounts,
        protected PersonWorkflowScheduleService $schedules,
        protected AutomationLimitSettings $limits,
        protected WorkflowExecutionService $execution,
    ) {}

    /**
     * Alle faelligen Bauplaene abarbeiten.
     *
     * @return array{enabled:bool,blueprints:int,created:int,failed:int,details:array<int,array<string,mixed>>}
     */
    public function runDueBlueprints(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $settings = $this->limits->get();

        $summary = [
            'enabled' => (bool) $settings['factory_enabled'],
            'blueprints' => 0,
            'created' => 0,
            'failed' => 0,
            'details' => [],
        ];

        if (! $settings['factory_enabled']) {
            return $summary;
        }

        $globalDayCap = (int) $settings['factory_max_per_day'];
        $createdGloballyToday = $this->countDraftsCreatedToday();

        $blueprints = PersonBlueprint::query()
            ->where('is_active', true)
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now);
            })
            ->orderBy('id')
            ->get();

        foreach ($blueprints as $blueprint) {
            $summary['blueprints']++;

            if ($blueprint->is_exhausted) {
                $blueprint->forceFill(['is_active' => false, 'last_error' => null])->save();

                continue;
            }

            $timezone = (string) config('app.timezone', 'Europe/Berlin');
            $blueprint->resetDailyCounterIfNeeded($timezone);

            $remainingToday = max(0, (int) $blueprint->per_day - (int) $blueprint->created_today);

            if ($globalDayCap > 0) {
                $remainingToday = min($remainingToday, max(0, $globalDayCap - $createdGloballyToday));
            }

            if ($blueprint->remaining_count !== null) {
                $remainingToday = min($remainingToday, $blueprint->remaining_count);
            }

            if ($remainingToday <= 0) {
                $blueprint->forceFill(['next_run_at' => $now->addHour()])->save();

                continue;
            }

            // Pro Durchlauf hoechstens eine Person: die Profilerzeugung ruft ein
            // Sprachmodell auf, und der Scheduler taktet ohnehin alle fuenf
            // Minuten. Das verteilt Last und Kosten.
            try {
                $person = $this->createDraft($blueprint);

                $summary['created']++;
                $createdGloballyToday++;
                $summary['details'][] = [
                    'blueprint' => $blueprint->name,
                    'person_id' => $person->id,
                    'person' => $person->display_name,
                    'result' => 'draft_created',
                ];
            } catch (\Throwable $exception) {
                $summary['failed']++;
                Log::error('Personen-Fabrik konnte keine Person erzeugen.', [
                    'blueprint_id' => $blueprint->id,
                    'message' => $exception->getMessage(),
                ]);

                $blueprint->forceFill([
                    'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                    'next_run_at' => $now->addMinutes(30),
                ])->save();

                $summary['details'][] = [
                    'blueprint' => $blueprint->name,
                    'result' => 'failed',
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        return $summary;
    }

    /**
     * Erzeugt eine einzelne Person als Entwurf.
     */
    public function createDraft(PersonBlueprint $blueprint): Person
    {
        $corridors = $this->pickCorridors($blueprint);
        $slug = $this->uniqueSlug($blueprint);

        $person = Person::query()->create([
            'platform' => $blueprint->platform ?: 'instagram',
            'profile_key' => $slug,
            'profile_label' => $blueprint->name.' '.($blueprint->created_count + 1),
            'browser_profile_path' => 'browser-profiles/'.($blueprint->platform ?: 'instagram').'/'.$slug,
            'cookie_file_path' => 'cookies/'.$slug.'-cookies.json',
            'persistent_profile_enabled' => true,
            'headless_enabled' => true,
            'auto_login_enabled' => false,
            'bot_status' => 'training',
            'is_active' => false,
            'is_primary' => false,
            'automation_enabled' => true,
            'max_concurrent_workflow_runs' => 1,
            'person_blueprint_id' => $blueprint->id,
            'approval_status' => 'draft',
            'identity_profile' => [],
            'bot_profile' => ['status' => 'training', 'prepared_for_automation' => false],
            'social_accounts' => [],
            'metadata' => [
                'created_by_blueprint' => [
                    'blueprint_id' => $blueprint->id,
                    'blueprint_name' => $blueprint->name,
                    'corridors' => $corridors,
                    'created_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        // Profil erzeugen. Schlaegt das Sprachmodell fehl, bleibt die Person als
        // leerer Entwurf bestehen — sichtbar in der Freigabeliste, statt still
        // verloren zu gehen.
        try {
            $this->composer->composeForNewPerson(
                $person,
                (string) ($blueprint->profile_prompt ?? ''),
                $corridors,
            );
        } catch (\Throwable $exception) {
            Log::warning('AI-Profil fuer Fabrik-Person fehlgeschlagen.', [
                'person_id' => $person->id,
                'message' => $exception->getMessage(),
            ]);

            $metadata = is_array($person->metadata) ? $person->metadata : [];
            $metadata['created_by_blueprint']['profile_error'] = mb_substr($exception->getMessage(), 0, 500);
            $person->forceFill(['metadata' => $metadata])->save();
        }

        $this->scaffoldAccounts($person, $blueprint);

        if ($blueprint->generate_avatar) {
            $this->dispatchAvatar($person);
        }

        $blueprint->resetDailyCounterIfNeeded((string) config('app.timezone', 'Europe/Berlin'));

        $createdCount = (int) $blueprint->created_count + 1;
        // Ein erschoepfter Bauplan schaltet sich sofort ab, statt still stehen zu
        // bleiben — sonst steht er in der Liste weiter auf "aktiv", obwohl er
        // nie wieder etwas erzeugt.
        $reachedTarget = $blueprint->target_count > 0 && $createdCount >= $blueprint->target_count;

        $blueprint->forceFill([
            'created_count' => $createdCount,
            'created_today' => (int) $blueprint->created_today + 1,
            'last_run_at' => now(),
            'next_run_at' => $reachedTarget ? null : now()->addMinutes(5),
            'is_active' => $reachedTarget ? false : $blueprint->is_active,
            'last_error' => null,
        ])->save();

        return $person->fresh();
    }

    /**
     * Freigabe: die Person wird aktiv, bekommt ihre Zeitplaene und startet
     * optional den Onboarding-Workflow.
     */
    public function approve(Person $person): void
    {
        if ($person->approval_status === 'approved') {
            return;
        }

        $person->forceFill([
            'approval_status' => 'approved',
            'approved_at' => now(),
            'is_active' => true,
            'bot_status' => 'ready',
        ])->save();

        $blueprint = $person->blueprint;

        if (! $blueprint) {
            return;
        }

        $this->createSchedulesFromTemplates($person, $blueprint);
        $this->startOnboarding($person, $blueprint);
    }

    public function reject(Person $person): void
    {
        $person->forceFill([
            'approval_status' => 'rejected',
            'is_active' => false,
            'automation_enabled' => false,
        ])->save();
    }

    /**
     * Legt die Zeitplaene aus den Vorlagen des Bauplans an.
     */
    public function createSchedulesFromTemplates(Person $person, PersonBlueprint $blueprint): int
    {
        $templates = is_array($blueprint->schedule_templates) ? $blueprint->schedule_templates : [];
        $created = 0;

        foreach ($templates as $template) {
            if (! is_array($template)) {
                continue;
            }

            $workflowId = (int) ($template['workflow_id'] ?? 0);
            $workflow = $workflowId > 0 ? Workflow::query()->find($workflowId) : null;

            if (! $workflow) {
                continue;
            }

            $normalized = $this->schedules->normalize($template);

            $schedule = new PersonWorkflowSchedule([
                ...$normalized,
                'person_id' => $person->id,
                'workflow_id' => $workflow->id,
            ]);
            $schedule->person_id = $person->id;
            $schedule->workflow_id = $workflow->id;
            $schedule->save();

            $schedule->forceFill([
                'next_run_at' => $this->schedules->computeNextRunAt($schedule, $person),
            ])->save();

            $created++;
        }

        return $created;
    }

    protected function startOnboarding(Person $person, PersonBlueprint $blueprint): void
    {
        if (! $blueprint->onboarding_workflow_id) {
            return;
        }

        $workflow = Workflow::query()->find($blueprint->onboarding_workflow_id);

        if (! $workflow || ! $workflow->is_active) {
            return;
        }

        try {
            $this->execution->start($workflow, [
                'person_id' => (int) $person->getKey(),
                'scheduled_reason' => 'person-factory-onboarding',
            ], 'person-factory');
        } catch (\Throwable $exception) {
            Log::warning('Onboarding-Workflow konnte nicht gestartet werden.', [
                'person_id' => $person->id,
                'workflow_id' => $workflow->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Legt fuer die im Bauplan gewaehlten Portale ein leeres Kontogeruest an,
     * damit sie im Accounts-Tab sichtbar sind und Workflows sie befuellen
     * koennen. Das Mailkonto entsteht erst durch die Mailregistrierung.
     */
    protected function scaffoldAccounts(Person $person, PersonBlueprint $blueprint): void
    {
        $types = is_array($blueprint->account_types) ? $blueprint->account_types : [];

        foreach ($types as $type) {
            $normalized = $this->accounts->normalizeType($type);

            if ($normalized === null || $normalized === 'email') {
                continue;
            }

            $this->accounts->saveSocialAccount($person, $normalized, [
                'status' => 'pending',
                'notes' => 'Von der Personen-Fabrik vorbereitet.',
            ]);
        }
    }

    protected function dispatchAvatar(Person $person): void
    {
        $appearance = (string) data_get($person->identity_profile, 'physical_appearance', '');
        $prompt = trim($appearance) !== ''
            ? $appearance
            : 'Portraitfoto einer erwachsenen Person, neutraler Hintergrund, natuerliches Licht.';

        try {
            GeneratePersonImages::dispatch(
                personId: (int) $person->getKey(),
                prompt: $prompt,
                preset: 'portrait',
                aspectRatio: '1:1',
                imageCount: 1,
                setFirstImageAsAvatar: true,
            );
        } catch (\Throwable $exception) {
            Log::warning('Avatar-Erzeugung fuer Fabrik-Person fehlgeschlagen.', [
                'person_id' => $person->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function pickCorridors(PersonBlueprint $blueprint): array
    {
        $ageMin = max(16, min(90, (int) $blueprint->age_min));
        $ageMax = max($ageMin, min(90, (int) $blueprint->age_max));

        return array_filter([
            'country' => $this->pickOne($blueprint->countries),
            'language' => $this->pickOne($blueprint->languages),
            'gender' => $this->pickOne($blueprint->genders),
            'age' => $ageMin === $ageMax ? (string) $ageMin : $ageMin.'-'.$ageMax,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function pickOne(mixed $values): ?string
    {
        if (! is_array($values)) {
            return null;
        }

        $values = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        )));

        return $values === [] ? null : $values[random_int(0, count($values) - 1)];
    }

    protected function uniqueSlug(PersonBlueprint $blueprint): string
    {
        $base = Str::slug($blueprint->name) ?: 'persona';

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (Person::withTrashed()->where('profile_key', $slug)->exists());

        return $slug;
    }

    protected function countDraftsCreatedToday(): int
    {
        return Person::query()
            ->whereNotNull('person_blueprint_id')
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }
}
