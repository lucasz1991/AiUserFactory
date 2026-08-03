<?php

namespace App\Services\Automation;

use App\Models\Person;
use App\Models\PersonWorkflowSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Normalisierung und Taktberechnung der Personen-Zeitplaene.
 *
 * Alle Zeitfenster, Wochentage und Tagesdeckel gelten in der **Ortszeit der
 * Person** (`persons.person_timezone`, Ersatz `config('app.timezone')`). Ohne das
 * wuerden Personas aus anderen Zeitzonen mitten in ihrer Nacht aktiv.
 */
class PersonWorkflowScheduleService
{
    /**
     * Schluessel, die im frei belegbaren `context_json` nicht vorkommen duerfen.
     * Zugangsdaten kommen zur Laufzeit aus `PersonAccountRegistry`, niemals aus
     * einem gespeicherten Zeitplan (Teamprotokoll-Regel 6).
     */
    public const FORBIDDEN_CONTEXT_KEYS = [
        'password', 'passwort', 'secret', 'token', 'api_key', 'apikey',
        'credential', 'credentials', 'private_key', 'session', 'cookie',
    ];

    public function normalize(array $input): array
    {
        $cadence = (string) ($input['cadence_type'] ?? PersonWorkflowSchedule::CADENCE_INTERVAL);

        if (! array_key_exists($cadence, PersonWorkflowSchedule::CADENCES)) {
            $cadence = PersonWorkflowSchedule::CADENCE_INTERVAL;
        }

        $weekdays = $this->normalizeWeekdays($input['weekdays'] ?? []);
        $dailyTimes = $this->normalizeDailyTimes($input['daily_times'] ?? []);

        // Ohne Uhrzeit waere ein `daily_times`-Plan nie faellig. Statt still nie
        // zu laufen, faellt er auf einen sinnvollen Vorgabewert zurueck.
        if ($cadence === PersonWorkflowSchedule::CADENCE_DAILY_TIMES && $dailyTimes === []) {
            $dailyTimes = ['09:00'];
        }

        return [
            'label' => mb_substr(trim((string) ($input['label'] ?? '')), 0, 120),
            'is_active' => (bool) ($input['is_active'] ?? true),
            'cadence_type' => $cadence,
            'interval_minutes' => $cadence === PersonWorkflowSchedule::CADENCE_INTERVAL
                ? max(5, min(20160, (int) ($input['interval_minutes'] ?? 240)))
                : null,
            'daily_times' => $cadence === PersonWorkflowSchedule::CADENCE_DAILY_TIMES ? $dailyTimes : null,
            'activity_plan_session_types' => $cadence === PersonWorkflowSchedule::CADENCE_ACTIVITY_PLAN
                ? $this->normalizeStringList($input['activity_plan_session_types'] ?? [])
                : null,
            'weekdays' => $weekdays,
            'window_start' => $this->normalizeTime($input['window_start'] ?? null),
            'window_end' => $this->normalizeTime($input['window_end'] ?? null),
            'jitter_seconds' => max(0, min(7200, (int) ($input['jitter_seconds'] ?? 0))),
            'max_runs_per_day' => ($input['max_runs_per_day'] ?? null) === null || $input['max_runs_per_day'] === ''
                ? null
                : max(1, min(500, (int) $input['max_runs_per_day'])),
            'min_gap_minutes' => max(0, min(20160, (int) ($input['min_gap_minutes'] ?? 0))),
            'priority' => max(-100, min(100, (int) ($input['priority'] ?? 0))),
            'context_json' => $this->sanitizeContext($input['context_json'] ?? []),
        ];
    }

    /**
     * Berechnet den naechsten Ausfuehrungszeitpunkt.
     *
     * `$after` ist der Zeitpunkt, ab dem gesucht wird — beim Anlegen "jetzt",
     * nach einem Lauf der Startzeitpunkt des Laufs.
     *
     * Gerechnet wird in der Ortszeit der Person, zurueckgegeben wird in der
     * Anwendungszeitzone. Das ist kein Schoenheitsfehler: Eloquent serialisiert
     * Datumswerte als reine Wanduhrzeit und liest sie in `config('app.timezone')`
     * wieder ein. Ein hier zurueckgegebener UTC-Wert wuerde beim Speichern um den
     * Zonenversatz verschoben — der Zeitplan liefe dann dauerhaft zu frueh.
     */
    public function computeNextRunAt(
        PersonWorkflowSchedule $schedule,
        Person $person,
        ?CarbonInterface $after = null,
        bool $applyJitter = true,
    ): ?CarbonImmutable {
        $timezone = $person->automation_timezone;
        $from = CarbonImmutable::parse($after ?? now())->setTimezone($timezone);

        $candidate = match ($schedule->cadence_type) {
            PersonWorkflowSchedule::CADENCE_DAILY_TIMES => $this->nextDailyTime($schedule, $from),
            PersonWorkflowSchedule::CADENCE_ACTIVITY_PLAN => $this->nextActivityPlanSlot($schedule, $person, $from),
            default => $this->nextInterval($schedule, $from),
        };

        if (! $candidate) {
            return null;
        }

        $candidate = $this->shiftIntoAllowedWindow($schedule, $candidate);

        if (! $candidate) {
            return null;
        }

        if ($applyJitter && $schedule->jitter_seconds > 0) {
            $candidate = $candidate->addSeconds(random_int(0, (int) $schedule->jitter_seconds));
            // Der Jitter darf nicht aus dem Zeitfenster herausschieben.
            $candidate = $this->shiftIntoAllowedWindow($schedule, $candidate) ?? $candidate;
        }

        return $candidate->setTimezone(config('app.timezone', 'Europe/Berlin'));
    }

    protected function nextInterval(PersonWorkflowSchedule $schedule, CarbonImmutable $from): CarbonImmutable
    {
        return $from->addMinutes(max(5, (int) ($schedule->interval_minutes ?: 240)));
    }

    protected function nextDailyTime(PersonWorkflowSchedule $schedule, CarbonImmutable $from): ?CarbonImmutable
    {
        $times = is_array($schedule->daily_times) ? $schedule->daily_times : [];

        if ($times === []) {
            return null;
        }

        sort($times);

        // Bis zu acht Tage vorausschauen: sieben decken jede Wochentagsauswahl
        // ab, der achte faengt den Sprung ueber Mitternacht sauber auf.
        for ($dayOffset = 0; $dayOffset <= 8; $dayOffset++) {
            $day = $from->addDays($dayOffset)->startOfDay();

            foreach ($times as $time) {
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $candidate = $day->setTime($hour, $minute);

                if ($candidate->greaterThan($from)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Taktquelle "Aktivitaetsplan": der bereits gespeicherte Sandbox-Plan der
     * Person (`metadata.internal_activity_simulation`) liefert die Startzeiten.
     * Damit wird der Plan endlich wirksam, statt nur angezeigt zu werden.
     */
    protected function nextActivityPlanSlot(
        PersonWorkflowSchedule $schedule,
        Person $person,
        CarbonImmutable $from,
    ): ?CarbonImmutable {
        $plan = data_get($person->metadata, 'internal_activity_simulation', []);
        $days = is_array($plan['days_plan'] ?? null) ? $plan['days_plan'] : [];

        if ($days === []) {
            return null;
        }

        $allowedTypes = is_array($schedule->activity_plan_session_types)
            ? array_filter($schedule->activity_plan_session_types)
            : [];

        $timezone = $from->getTimezone();
        $candidates = [];

        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }

            $date = trim((string) ($day['date'] ?? ''));

            foreach (($day['sessions'] ?? []) as $session) {
                if (! is_array($session)) {
                    continue;
                }

                $type = trim((string) ($session['session_type'] ?? ''));

                if ($allowedTypes !== [] && ! in_array($type, $allowedTypes, true)) {
                    continue;
                }

                $startsAt = $this->activitySlotToCarbon($date, $session, $timezone);

                if ($startsAt && $startsAt->greaterThan($from)) {
                    $candidates[] = $startsAt;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $candidates[0];
    }

    protected function activitySlotToCarbon(string $date, array $session, \DateTimeZone $timezone): ?CarbonImmutable
    {
        $time = trim((string) ($session['starts_at_local'] ?? ''));

        if ($date === '' || $time === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($date.' '.$time, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Verschiebt einen Kandidaten in das erlaubte Zeitfenster und auf einen
     * erlaubten Wochentag. Liefert `null`, wenn innerhalb von acht Tagen kein
     * gueltiger Zeitpunkt existiert — dann ist der Zeitplan widerspruechlich.
     */
    protected function shiftIntoAllowedWindow(
        PersonWorkflowSchedule $schedule,
        CarbonImmutable $candidate,
    ): ?CarbonImmutable {
        $weekdays = is_array($schedule->weekdays) ? array_map('intval', $schedule->weekdays) : [];
        $start = $this->normalizeTime($schedule->window_start);
        $end = $this->normalizeTime($schedule->window_end);

        for ($attempt = 0; $attempt <= 8; $attempt++) {
            $isAllowedDay = $weekdays === [] || in_array((int) $candidate->isoWeekday(), $weekdays, true);

            if (! $isAllowedDay) {
                $candidate = $candidate->addDay()->startOfDay();

                if ($start !== null) {
                    [$hour, $minute] = array_map('intval', explode(':', $start));
                    $candidate = $candidate->setTime($hour, $minute);
                }

                continue;
            }

            if ($start === null || $end === null) {
                return $candidate;
            }

            $windowStart = $this->applyTime($candidate, $start);
            $windowEnd = $this->applyTime($candidate, $end);

            // Fenster ueber Mitternacht, z. B. 22:00 bis 02:00.
            if ($windowEnd->lessThanOrEqualTo($windowStart)) {
                if ($candidate->greaterThanOrEqualTo($windowStart) || $candidate->lessThan($windowEnd)) {
                    return $candidate;
                }

                return $windowStart;
            }

            if ($candidate->lessThan($windowStart)) {
                return $windowStart;
            }

            if ($candidate->greaterThan($windowEnd)) {
                $candidate = $candidate->addDay()->startOfDay();

                continue;
            }

            return $candidate;
        }

        return null;
    }

    protected function applyTime(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $day->setTime($hour, $minute);
    }

    /**
     * @return array<int, int>
     */
    protected function normalizeWeekdays(mixed $weekdays): array
    {
        if (! is_array($weekdays)) {
            return [];
        }

        $normalized = array_values(array_unique(array_filter(
            array_map('intval', $weekdays),
            static fn (int $day): bool => $day >= 1 && $day <= 7,
        )));

        sort($normalized);

        // Alle sieben Tage bedeutet dasselbe wie "keine Einschraenkung" und wird
        // als leere Liste gespeichert, damit die Abfrage einfach bleibt.
        return count($normalized) === 7 ? [] : $normalized;
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeDailyTimes(mixed $times): array
    {
        if (is_string($times)) {
            $times = preg_split('/[\s,;]+/', $times) ?: [];
        }

        if (! is_array($times)) {
            return [];
        }

        $normalized = [];

        foreach ($times as $time) {
            $value = $this->normalizeTime($time);

            if ($value !== null) {
                $normalized[$value] = $value;
            }
        }

        $normalized = array_values($normalized);
        sort($normalized);

        return $normalized;
    }

    public function normalizeTime(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (! preg_match('/^(\d{1,2}):(\d{2})/', $value, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeStringList(mixed $values): array
    {
        if (is_string($values)) {
            $values = preg_split('/[\r\n,;]+/', $values) ?: [];
        }

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ))));
    }

    /**
     * Entfernt alles, was nach einem Geheimnis aussieht, und begrenzt die Groesse.
     *
     * @return array<string, scalar>
     */
    public function sanitizeContext(mixed $context): array
    {
        if (is_string($context)) {
            $decoded = json_decode($context, true);
            $context = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($context)) {
            return [];
        }

        $clean = [];

        foreach ($context as $key => $value) {
            $normalizedKey = strtolower(str_replace(['-', ' '], '_', trim((string) $key)));

            if ($normalizedKey === '' || ! is_scalar($value)) {
                continue;
            }

            foreach (self::FORBIDDEN_CONTEXT_KEYS as $forbidden) {
                if (str_contains($normalizedKey, $forbidden)) {
                    continue 2;
                }
            }

            // `person_id` setzt der Dispatcher selbst; ein abweichender Wert im
            // Zeitplan wuerde den Lauf einer fremden Person unterschieben.
            if (in_array($normalizedKey, ['person_id', 'personid'], true)) {
                continue;
            }

            $clean[$normalizedKey] = is_string($value) ? mb_substr($value, 0, 500) : $value;

            if (count($clean) >= 30) {
                break;
            }
        }

        return $clean;
    }
}
