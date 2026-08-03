<?php

namespace App\Services\Automation;

use App\Models\Person;
use App\Models\PersonWorkflowSchedule;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\Workflows\WorkflowExecutionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Startet faellige Personen-Workflows.
 *
 * Der Dispatcher erfindet nichts: er waehlt faellige Zeitplaene, prueft sie in
 * einer festen Reihenfolge und startet den Workflow ueber den einzigen
 * vorhandenen Startweg `WorkflowExecutionService::start()` mit der eigenen
 * Quelle `schedule` (Teamprotokoll-Regel 2).
 *
 * Eine Ablehnung verwirft nie einen Zeitplan — sie verschiebt nur `next_run_at`
 * und haelt den Grund in `last_skip_reason` fest, damit die Oberflaeche erklaeren
 * kann, warum nichts passiert ist.
 */
class PersonWorkflowDispatcher
{
    /** Laeufe in diesen Zustaenden gelten als abgeschlossen. */
    public const FINAL_RUN_STATUSES = ['completed', 'failed', 'cancelled', 'timed_out', 'lost'];

    public const REQUESTED_BY = 'schedule';

    public function __construct(
        protected PersonWorkflowScheduleService $schedules,
        protected WorkflowBrowserBinding $browserBinding,
        protected AutomationLimitSettings $limits,
        protected WorkflowExecutionService $execution,
    ) {}

    /**
     * Ein Durchlauf. Liefert eine Zusammenfassung fuer Befehl und Oberflaeche.
     *
     * @return array{enabled:bool,considered:int,started:int,skipped:int,failed:int,details:array<int,array<string,mixed>>}
     */
    public function dispatchDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $settings = $this->limits->get();

        $summary = [
            'enabled' => (bool) $settings['enabled'],
            'considered' => 0,
            'started' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => [],
        ];

        // Stufe 1a: globaler Not-Aus.
        if (! $settings['enabled']) {
            return $summary;
        }

        $this->browserBinding->forget();

        $globalRunning = $this->countRunningScheduledRuns();
        $startsLeft = (int) $settings['max_starts_per_tick'];

        $due = PersonWorkflowSchedule::query()
            ->due($now)
            ->with(['person', 'workflow'])
            ->orderBy('priority')
            ->orderBy('next_run_at')
            ->orderBy('id')
            ->limit(max(1, $startsLeft) * 10)
            ->get();

        foreach ($due as $schedule) {
            if ($startsLeft <= 0) {
                break;
            }

            $summary['considered']++;

            $person = $schedule->person;
            $workflow = $schedule->workflow;

            if (! $person || ! $workflow) {
                $this->skip($schedule, $person, 'Person oder Workflow fehlt.', $now);
                $summary['skipped']++;

                continue;
            }

            // Stufe 9: globaler Deckel.
            if ($globalRunning >= (int) $settings['max_concurrent_runs']) {
                $this->skip($schedule, $person, 'Globaler Deckel gleichzeitiger Laeufe erreicht.', $now, 5);
                $summary['skipped']++;

                continue;
            }

            $rejection = $this->rejectionReason($schedule, $person, $workflow, $settings, $now);

            if ($rejection !== null) {
                $this->skip($schedule, $person, $rejection['reason'], $now, $rejection['retryMinutes']);
                $summary['skipped']++;
                $summary['details'][] = [
                    'schedule_id' => $schedule->id,
                    'person' => $person->display_name,
                    'workflow' => $workflow->name,
                    'result' => 'skipped',
                    'reason' => $rejection['reason'],
                ];

                continue;
            }

            try {
                $run = $this->startRun($schedule, $person, $workflow);

                $summary['started']++;
                $globalRunning++;
                $startsLeft--;
                $summary['details'][] = [
                    'schedule_id' => $schedule->id,
                    'person' => $person->display_name,
                    'workflow' => $workflow->name,
                    'result' => 'started',
                    'workflow_run_id' => $run->id,
                ];
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $this->recordFailure($schedule, $person, $exception, $settings, $now);
                $summary['details'][] = [
                    'schedule_id' => $schedule->id,
                    'person' => $person->display_name,
                    'workflow' => $workflow->name,
                    'result' => 'failed',
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        return $summary;
    }

    /**
     * Die Pruefkette. Liefert `null`, wenn gestartet werden darf.
     *
     * @return array{reason:string,retryMinutes:int}|null
     */
    public function rejectionReason(
        PersonWorkflowSchedule $schedule,
        Person $person,
        Workflow $workflow,
        array $settings,
        CarbonImmutable $now,
    ): ?array {
        $timezone = $person->automation_timezone;
        $localNow = $now->setTimezone($timezone);

        // Stufe 2: Person betriebsbereit.
        if (! $person->is_active) {
            return ['reason' => 'Person ist deaktiviert.', 'retryMinutes' => 60];
        }

        if (! $person->automation_enabled) {
            return ['reason' => 'Automatisierung ist fuer diese Person ausgeschaltet.', 'retryMinutes' => 60];
        }

        if ($person->approval_status === 'draft') {
            return ['reason' => 'Person ist noch ein nicht freigegebener Entwurf.', 'retryMinutes' => 60];
        }

        if ($person->is_scrape_blocked) {
            return ['reason' => 'Person ist bis '.$person->scrape_blocked_until?->format('d.m.Y H:i').' gesperrt.', 'retryMinutes' => 30];
        }

        // Stufe 3: Workflow ausfuehrbar.
        if (! $workflow->is_active) {
            return ['reason' => 'Workflow ist deaktiviert.', 'retryMinutes' => 60];
        }

        if ($workflow->has_active_copilot_lock) {
            return ['reason' => 'Workflow wird gerade vom Copilot optimiert.', 'retryMinutes' => 15];
        }

        if (! $workflow->enabledSteps()->exists()) {
            return ['reason' => 'Workflow hat keine aktiven Schritte.', 'retryMinutes' => 120];
        }

        // Stufe 4: Wochentag und Zeitfenster in Ortszeit der Person.
        $weekdays = is_array($schedule->weekdays) ? array_map('intval', $schedule->weekdays) : [];

        if ($weekdays !== [] && ! in_array((int) $localNow->isoWeekday(), $weekdays, true)) {
            return ['reason' => 'Heute ist kein erlaubter Wochentag.', 'retryMinutes' => 30];
        }

        if (! $this->isInsideWindow($schedule, $localNow)) {
            return ['reason' => 'Ausserhalb des erlaubten Zeitfensters.', 'retryMinutes' => 15];
        }

        // Stufe 5: Mindestabstand.
        if ($schedule->min_gap_minutes > 0 && $schedule->last_run_at !== null) {
            $earliest = CarbonImmutable::parse($schedule->last_run_at)->addMinutes((int) $schedule->min_gap_minutes);

            if ($now->lessThan($earliest)) {
                return ['reason' => 'Mindestabstand zum letzten Lauf nicht erreicht.', 'retryMinutes' => max(1, (int) $now->diffInMinutes($earliest))];
            }
        }

        // Stufe 6: Tagesdeckel des Zeitplans und der Person.
        $schedule->resetDailyCounterIfNeeded($timezone);

        if ($schedule->max_runs_per_day !== null && $schedule->runs_today >= $schedule->max_runs_per_day) {
            return ['reason' => 'Tagesdeckel dieses Zeitplans erreicht.', 'retryMinutes' => 60];
        }

        $personDayCap = (int) $settings['max_runs_per_person_per_day'];

        if ($personDayCap > 0 && $this->countPersonRunsToday($person, $timezone) >= $personDayCap) {
            return ['reason' => 'Tagesdeckel dieser Person erreicht.', 'retryMinutes' => 60];
        }

        // Stufe 7: Nebenlaeufigkeit je Person.
        $activeRuns = $this->activeRunsOfPerson($person);
        $personLimit = max(1, (int) ($person->max_concurrent_workflow_runs ?: 1));

        if ($activeRuns->count() >= $personLimit) {
            return ['reason' => 'Person faehrt bereits '.$activeRuns->count().' Laeufe.', 'retryMinutes' => 5];
        }

        // Stufe 8: Browser-Exklusivitaet. Browserprofil, Cookie-Datei und Session
        // gehoeren jeder Person nur einmal — zwei gleichzeitige browsergebundene
        // Laeufe wuerden sich die Session ueberschreiben.
        if ($this->browserBinding->requiresBrowser($workflow)) {
            foreach ($activeRuns as $run) {
                if ($run->workflow_id && $this->browserBinding->requiresBrowserById((int) $run->workflow_id)) {
                    return ['reason' => 'Ein anderer browsergebundener Lauf dieser Person ist aktiv.', 'retryMinutes' => 5];
                }
            }
        }

        return null;
    }

    protected function isInsideWindow(PersonWorkflowSchedule $schedule, CarbonImmutable $localNow): bool
    {
        $start = $this->schedules->normalizeTime($schedule->window_start);
        $end = $this->schedules->normalizeTime($schedule->window_end);

        if ($start === null || $end === null) {
            return true;
        }

        $current = $localNow->format('H:i');

        // Fenster ueber Mitternacht, z. B. 22:00 bis 02:00.
        if ($end <= $start) {
            return $current >= $start || $current < $end;
        }

        return $current >= $start && $current <= $end;
    }

    protected function startRun(PersonWorkflowSchedule $schedule, Person $person, Workflow $workflow): WorkflowRun
    {
        $context = array_replace(
            is_array($schedule->context_json) ? $schedule->context_json : [],
            [
                'person_id' => (int) $person->getKey(),
                'person_workflow_schedule_id' => (int) $schedule->getKey(),
                'scheduled_reason' => 'person-workflow-schedule',
            ],
        );

        $run = $this->execution->start($workflow, $context, self::REQUESTED_BY);

        $startedAt = CarbonImmutable::now();
        $schedule->resetDailyCounterIfNeeded($person->automation_timezone);
        $schedule->forceFill([
            'last_run_at' => $startedAt,
            'last_workflow_run_id' => $run->id,
            'runs_today' => (int) $schedule->runs_today + 1,
            'consecutive_failures' => 0,
            'paused_until' => null,
            'last_skip_reason' => null,
            'next_run_at' => $this->schedules->computeNextRunAt($schedule, $person, $startedAt),
        ])->save();

        return $run;
    }

    protected function skip(
        PersonWorkflowSchedule $schedule,
        ?Person $person,
        string $reason,
        CarbonImmutable $now,
        int $retryMinutes = 15,
    ): void {
        // Bevorzugt der regulaere naechste Termin; nur wenn der in der
        // Vergangenheit laege, wird um die Wartezeit verschoben.
        $next = $person
            ? $this->schedules->computeNextRunAt($schedule, $person, $now)
            : null;

        if ($next === null || $next->lessThanOrEqualTo($now)) {
            $next = $now->addMinutes(max(1, $retryMinutes));
        }

        $schedule->forceFill([
            'next_run_at' => $next,
            'last_skip_reason' => mb_substr($reason, 0, 120),
        ])->save();
    }

    protected function recordFailure(
        PersonWorkflowSchedule $schedule,
        Person $person,
        \Throwable $exception,
        array $settings,
        CarbonImmutable $now,
    ): void {
        $failures = (int) $schedule->consecutive_failures + 1;
        $backoff = (int) $settings['failure_backoff_minutes'] * (2 ** min(6, $failures - 1));
        $pauseAfter = (int) $settings['pause_after_failures'];

        Log::warning('Personen-Workflow konnte nicht gestartet werden.', [
            'schedule_id' => $schedule->id,
            'person_id' => $person->id,
            'workflow_id' => $schedule->workflow_id,
            'message' => $exception->getMessage(),
        ]);

        $schedule->forceFill([
            'consecutive_failures' => $failures,
            'last_skip_reason' => mb_substr($exception->getMessage(), 0, 120),
            'next_run_at' => $now->addMinutes($backoff),
            'paused_until' => $pauseAfter > 0 && $failures >= $pauseAfter ? $now->addMinutes($backoff) : null,
            'is_active' => $pauseAfter > 0 && $failures >= $pauseAfter ? false : $schedule->is_active,
        ])->save();
    }

    /**
     * @return Collection<int, WorkflowRun>
     */
    public function activeRunsOfPerson(Person $person): Collection
    {
        return WorkflowRun::query()
            ->where('person_id', $person->getKey())
            ->whereNotIn('status', self::FINAL_RUN_STATUSES)
            ->get(['id', 'workflow_id', 'status']);
    }

    protected function countRunningScheduledRuns(): int
    {
        return WorkflowRun::query()
            ->where('requested_by', self::REQUESTED_BY)
            ->whereNotIn('status', self::FINAL_RUN_STATUSES)
            ->count();
    }

    protected function countPersonRunsToday(Person $person, string $timezone): int
    {
        // Tagesbeginn in der Ortszeit der Person, aber in der Anwendungszeitzone
        // verglichen — so werden die Zeitstempel gelesen, die Eloquent schreibt.
        $startOfDay = CarbonImmutable::now($timezone)
            ->startOfDay()
            ->setTimezone(config('app.timezone', 'Europe/Berlin'));

        return WorkflowRun::query()
            ->where('person_id', $person->getKey())
            ->where('requested_by', self::REQUESTED_BY)
            ->where('created_at', '>=', $startOfDay)
            ->count();
    }

    /**
     * Setzt `next_run_at` fuer alle Zeitplaene neu, denen er fehlt. Wird nach
     * dem Speichern und beim ersten Durchlauf gebraucht.
     */
    public function primeMissingSchedules(): int
    {
        $primed = 0;

        PersonWorkflowSchedule::query()
            ->whereNull('next_run_at')
            ->where('is_active', true)
            ->with('person')
            ->chunkById(200, function ($schedules) use (&$primed): void {
                foreach ($schedules as $schedule) {
                    if (! $schedule->person) {
                        continue;
                    }

                    $next = $this->schedules->computeNextRunAt($schedule, $schedule->person);

                    if ($next !== null) {
                        DB::table('person_workflow_schedules')
                            ->where('id', $schedule->id)
                            ->update(['next_run_at' => $next]);
                        $primed++;
                    }
                }
            });

        return $primed;
    }
}
