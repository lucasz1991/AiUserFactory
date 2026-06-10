<?php

namespace App\Services\Simulation;

use App\Models\Person;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PersonaNetworkPlanningService
{
    public function __construct(
        protected PersonaActivityPlanner $planner,
    ) {
    }

    public function planActiveNetwork(int $days = 7, string $intensity = 'balanced', string $reason = 'manual'): array
    {
        $days = max(1, min(14, $days));
        $intensity = in_array($intensity, ['quiet', 'balanced', 'active', 'creator'], true)
            ? $intensity
            : 'balanced';
        $runId = (string) Str::uuid();
        $plannedAt = now()->toIso8601String();
        $persons = $this->activePersons();
        $planned = 0;
        $skipped = 0;
        $incomingEvents = 0;
        $errors = [];

        foreach ($persons as $person) {
            if ($person->bot_status === 'disabled') {
                $skipped++;

                continue;
            }

            try {
                $metadata = is_array($person->metadata) ? $person->metadata : [];
                $personIncomingEvents = $this->pendingInternalEvents($metadata);
                $incomingEvents += count($personIncomingEvents);

                $plan = $this->planner->build(
                    person: $person,
                    days: $days,
                    intensity: $intensity,
                    seed: $this->networkSeed($person, $runId, $reason),
                    startsAt: Carbon::now($this->personTimezone($person)),
                );

                $plan['network_planning'] = [
                    'run_id' => $runId,
                    'planned_at' => $plannedAt,
                    'reason' => $reason,
                    'mode' => 'network_batch',
                    'incoming_event_count' => count($personIncomingEvents),
                    'incoming_events' => $personIncomingEvents,
                ];

                if ($personIncomingEvents !== []) {
                    $plan['governance']['notes'][] = 'Interne eingehende Events wurden als Kontext fuer diesen Plan protokolliert.';
                }

                $metadata['internal_activity_simulation'] = $plan;
                $metadata['last_network_planning_run'] = [
                    'run_id' => $runId,
                    'planned_at' => $plannedAt,
                    'reason' => $reason,
                    'days' => $days,
                    'intensity' => $intensity,
                    'incoming_event_count' => count($personIncomingEvents),
                ];

                $person->forceFill([
                    'metadata' => $metadata,
                ])->save();

                $planned++;
            } catch (\Throwable $exception) {
                $errors[] = [
                    'person_id' => $person->id,
                    'profile_key' => $person->profile_key,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'run_id' => $runId,
            'planned_at' => $plannedAt,
            'reason' => $reason,
            'days' => $days,
            'intensity' => $intensity,
            'persons_total' => $persons->count(),
            'persons_planned' => $planned,
            'persons_skipped' => $skipped,
            'incoming_events' => $incomingEvents,
            'errors' => $errors,
        ];
    }

    protected function activePersons(): Collection
    {
        return Person::query()
            ->where('platform', 'instagram')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    protected function pendingInternalEvents(array $metadata): array
    {
        $events = data_get($metadata, 'internal_inbox_events', []);

        if (! is_array($events)) {
            return [];
        }

        return collect($events)
            ->filter(fn (mixed $event): bool => is_array($event) && ($event['status'] ?? 'pending') === 'pending')
            ->map(fn (array $event): array => [
                'type' => trim((string) ($event['type'] ?? 'event')),
                'received_at' => trim((string) ($event['received_at'] ?? '')),
                'summary' => trim((string) ($event['summary'] ?? '')),
                'priority' => trim((string) ($event['priority'] ?? 'normal')),
            ])
            ->take(20)
            ->values()
            ->toArray();
    }

    protected function networkSeed(Person $person, string $runId, string $reason): string
    {
        return hash('sha256', implode('|', [
            'network',
            $runId,
            $reason,
            $person->getKey(),
            $person->profile_key,
        ]));
    }

    protected function personTimezone(Person $person): string
    {
        $timezone = trim((string) $person->person_timezone);

        if ($timezone === '') {
            return config('app.timezone', 'Europe/Berlin');
        }

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return config('app.timezone', 'Europe/Berlin');
        }
    }
}
