<?php

namespace App\Services\Workflows;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRecording;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorkflowRecordingService
{
    public const MAX_EVENTS = 500;

    public function __construct(
        protected WorkflowRecordingBrowser $browser,
        protected WorkflowRecordingWorkflowBuilder $builder,
        protected WorkflowTaskCatalog $catalog,
        protected WorkflowSelectorSyntaxService $selectorSyntax,
    ) {}

    public function create(User $user, string $name, string $startUrl): WorkflowRecording
    {
        $this->authorizeAdmin($user);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 180) {
            throw ValidationException::withMessages(['name' => 'Bitte einen Namen mit hoechstens 180 Zeichen eingeben.']);
        }

        return WorkflowRecording::query()->create([
            'recording_uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'name' => $name,
            'start_url' => self::replayUrl($startUrl),
            'status' => 'draft',
            'events_json' => [],
            'bindings_json' => [],
            'runtime_json' => [],
            'state_json' => [],
            'last_activity_at' => now(),
        ]);
    }

    public function owned(User $user, int $id): WorkflowRecording
    {
        $this->authorizeAdmin($user);

        return WorkflowRecording::query()->where('user_id', $user->id)->findOrFail($id);
    }

    public function start(User $user, int $id): WorkflowRecording
    {
        $snapshot = $this->mutate($user, $id, function (WorkflowRecording $recording): void {
            $this->requireStatus($recording, ['draft']);
            $recording->forceFill([
                'status' => 'recording',
                'runtime_generation' => (string) Str::uuid(),
                'started_at' => now(),
                'finished_at' => null,
            ]);
        });

        try {
            $descriptor = $this->browser->start($snapshot);
            $snapshot->runtime_json = $descriptor;
            $stored = $this->mutate($user, $id, function (WorkflowRecording $recording) use ($snapshot, $descriptor): void {
                if (! $this->sameRevision($recording, $snapshot)) {
                    return;
                }
                $recording->runtime_json = $descriptor;
            }, false);
            if ($stored->runtime_generation !== $snapshot->runtime_generation || $stored->status !== 'recording') {
                $snapshot->runtime_json = $descriptor;
                $this->browser->close($snapshot);

                return $stored;
            }

            return $this->action($user, $id, ['type' => 'navigate', 'url' => $stored->start_url]);
        } catch (Throwable $exception) {
            $this->markFailed($user, $snapshot);
            try {
                $this->browser->close($snapshot);
            } catch (Throwable) {
                // No private runtime details are logged.
            }
            throw $exception;
        }
    }

    public function action(User $user, int $id, array $command): WorkflowRecording
    {
        $snapshot = $this->owned($user, $id);
        $type = (string) ($command['type'] ?? '');
        if (! in_array($type, ['navigate', 'click', 'move', 'hover', 'fill', 'key', 'scroll', 'inspect', 'back', 'forward', 'reload'], true)) {
            throw ValidationException::withMessages(['recording' => 'Diese Browseraktion ist nicht erlaubt.']);
        }
        $this->requireStatus($snapshot, $type === 'inspect' ? ['recording', 'paused'] : ['recording']);
        if ($type === 'navigate') {
            $command['url'] = self::replayUrl((string) ($command['url'] ?? ''));
        }
        if ($type === 'fill') {
            $source = (string) ($command['source'] ?? '');
            if (! in_array($source, ['fixed', 'workflow_variable', 'literal'], true)) {
                throw ValidationException::withMessages(['source' => 'Bitte eine gueltige Eingabequelle waehlen.']);
            }
            $value = (string) ($command['value'] ?? '');
            if (($source === 'fixed' && ! $this->catalog->isAllowedInputFillDataValue($value))
                || ($source === 'literal' && (mb_strlen($value) > 8192 || $this->catalog->resemblesInputFillDataReference($value)))) {
                throw ValidationException::withMessages(['value' => 'Bitte einen gueltigen Task-Datenpfad oder bewussten freien Text auswaehlen.']);
            }
            if ($source === 'workflow_variable' && ! $this->isVariablePath((string) ($command['workflow_variable'] ?? ''))) {
                throw ValidationException::withMessages(['workflow_variable' => 'Bitte einen gueltigen Variablennamen eingeben.']);
            }
        }

        return $this->acceptState($user, $snapshot, $this->browser->command($snapshot, $command));
    }

    public function poll(User $user, int $id): WorkflowRecording
    {
        $snapshot = $this->owned($user, $id);
        if (! in_array($snapshot->status, ['recording', 'paused'], true) || ($snapshot->runtime_json ?? []) === []) {
            return $snapshot;
        }

        try {
            return $this->acceptState($user, $snapshot, $this->browser->state($snapshot));
        } catch (Throwable $exception) {
            $this->markFailed($user, $snapshot);
            try {
                $this->browser->close($snapshot);
            } catch (Throwable) {
                // No raw browser failure, page URL or credentials are logged.
            }
            throw $exception;
        }
    }

    public function pause(User $user, int $id): WorkflowRecording
    {
        return $this->control($user, $id, ['recording'], 'paused', 'pause');
    }

    public function resume(User $user, int $id): WorkflowRecording
    {
        return $this->control($user, $id, ['paused'], 'recording', 'resume');
    }

    public function stop(User $user, int $id): WorkflowRecording
    {
        $snapshot = $this->mutate($user, $id, function (WorkflowRecording $recording): void {
            $this->requireStatus($recording, ['draft', 'recording', 'paused', 'failed', 'stopped']);
            $recording->forceFill(['status' => 'stopped', 'finished_at' => now()]);
        });
        try {
            if (($snapshot->runtime_json ?? []) !== []) {
                $this->acceptState($user, $snapshot, $this->browser->command($snapshot, ['type' => 'stop']));
            }
        } finally {
            // Closing the process is also outside the database transaction.
            try {
                $this->browser->close($snapshot);
            } finally {
                $this->mutate($user, $id, function (WorkflowRecording $recording) use ($snapshot): void {
                    if ($recording->runtime_generation === $snapshot->runtime_generation && $recording->status === 'stopped') {
                        $recording->runtime_json = [];
                    }
                }, false);
            }
        }

        return $this->owned($user, $id);
    }

    public function updateEvent(User $user, int $id, string $eventId, array $values): WorkflowRecording
    {
        if (array_diff(array_keys($values), ['label', 'selector', 'value_source', 'value', 'workflow_variable']) !== []) {
            throw ValidationException::withMessages(['event' => 'Diese Aktionsfelder duerfen nicht geaendert werden.']);
        }

        return $this->mutate($user, $id, function (WorkflowRecording $recording) use ($eventId, $values): void {
            $this->requireStatus($recording, ['paused', 'stopped']);
            $events = $recording->events_json ?? [];
            $index = $this->eventIndex($events, $eventId);
            // The original input type / sensitive flag cannot be disabled by a
            // public editor field to smuggle a password back into a literal.
            $updated = array_replace($events[$index], $values);
            if (($events[$index]['secret'] ?? false) && ($updated['value_source'] ?? '') === 'literal') {
                throw ValidationException::withMessages(['eventValueSource' => 'Geheime Eingaben duerfen nicht als freier Text gespeichert werden.']);
            }
            if (array_key_exists('selector', $values)) {
                if (trim((string) $values['selector']) !== (string) ($events[$index]['selector'] ?? '')) {
                    unset($updated['selectors']);
                    // This is an explicit selector edit, not new DOM evidence.
                    // Keep the captured semantic identity, never relabel a new
                    // arbitrary selector as a verified stable alternative.
                    $updated['stable_selectors'] = [];
                }
            }
            $events[$index] = $this->normalizeEvent($updated);
            $recording->events_json = array_values($events);
        });
    }

    public function removeEvent(User $user, int $id, string $eventId): WorkflowRecording
    {
        return $this->mutate($user, $id, function (WorkflowRecording $recording) use ($eventId): void {
            $this->requireStatus($recording, ['paused', 'stopped']);
            $events = $recording->events_json ?? [];
            $index = $this->eventIndex($events, $eventId);
            array_splice($events, $index, 1);
            $recording->events_json = array_values($events);
        });
    }

    public function moveEvent(User $user, int $id, string $eventId, int $direction): WorkflowRecording
    {
        if (! in_array($direction, [-1, 1], true)) {
            throw ValidationException::withMessages(['event' => 'Bitte eine gueltige Sortierrichtung waehlen.']);
        }

        return $this->mutate($user, $id, function (WorkflowRecording $recording) use ($eventId, $direction): void {
            $this->requireStatus($recording, ['paused', 'stopped']);
            $events = $recording->events_json ?? [];
            $index = $this->eventIndex($events, $eventId);
            $target = $index + $direction;
            if (isset($events[$target])) {
                [$events[$index], $events[$target]] = [$events[$target], $events[$index]];
            }
            $recording->events_json = array_values($events);
        });
    }

    public function save(User $user, int $id, ?string $name = null): Workflow
    {
        $this->authorizeAdmin($user);

        return DB::transaction(function () use ($user, $id, $name): Workflow {
            $recording = WorkflowRecording::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
            if ($recording->status === 'saved' && $recording->workflow_id !== null) {
                return $recording->workflow()->firstOrFail();
            }
            $this->requireStatus($recording, ['stopped']);
            $workflow = $this->builder->build($recording, $name);
            $recording->forceFill([
                'workflow_id' => $workflow->id,
                'name' => $workflow->name,
                'status' => 'saved',
                'revision' => $recording->revision + 1,
                'last_activity_at' => now(),
            ])->save();

            return $workflow;
        });
    }

    /** An explicit allowlist, never serialize an entire recording model. */
    public function publicState(WorkflowRecording $recording): array
    {
        $state = is_array($recording->state_json) ? $recording->state_json : [];

        return [
            'recordingId' => (int) $recording->id,
            'state' => $recording->status,
            'name' => $recording->name,
            'events' => is_array($recording->events_json) ? $recording->events_json : [],
            'url' => (string) ($state['url'] ?? $recording->start_url),
            'title' => (string) ($state['title'] ?? ''),
            'viewport' => $state['viewport'] ?? ['width' => 1365, 'height' => 768],
            'selected' => $state['selected'] ?? null,
            'cursor' => $state['cursor'] ?? null,
            'error' => (string) ($state['error'] ?? ''),
            'revision' => (int) $recording->revision,
            'workflowId' => $recording->workflow_id ? (int) $recording->workflow_id : null,
        ];
    }

    protected function control(User $user, int $id, array $from, string $to, string $command): WorkflowRecording
    {
        $snapshot = $this->mutate($user, $id, function (WorkflowRecording $recording) use ($from, $to): void {
            $this->requireStatus($recording, $from);
            $recording->status = $to;
        });
        try {
            return $this->acceptState($user, $snapshot, $this->browser->command($snapshot, ['type' => $command]));
        } catch (Throwable $exception) {
            $this->markFailed($user, $snapshot);
            try {
                $this->browser->close($snapshot);
            } catch (Throwable) {
                // The original error is kept; no token, URL or input is logged.
            }
            throw $exception;
        }
    }

    protected function acceptState(User $user, WorkflowRecording $snapshot, array $state): WorkflowRecording
    {
        return $this->mutate($user, (int) $snapshot->id, function (WorkflowRecording $recording) use ($snapshot, $state): void {
            if (! $this->sameRevision($recording, $snapshot)) {
                return;
            }
            $events = $recording->events_json ?? [];
            $incoming = $state['events'] ?? [];
            if (! is_array($incoming) || count($incoming) > self::MAX_EVENTS) {
                throw ValidationException::withMessages(['recording' => 'Die Aufnahme hat ihre sichere Groessenbegrenzung erreicht.']);
            }
            $sequence = $recording->last_event_sequence;
            $newEvents = [];
            foreach ($incoming as $event) {
                if (! is_array($event) || ! is_int($event['sequence'] ?? null) || $event['sequence'] <= 0) {
                    throw ValidationException::withMessages(['recording' => 'Die Browseraufnahme hat eine ungueltige Ereignisfolge geliefert.']);
                }
                if ($event['sequence'] > $sequence) {
                    if (isset($newEvents[$event['sequence']])) {
                        throw ValidationException::withMessages(['recording' => 'Die Browseraufnahme hat doppelte Ereignisnummern geliefert.']);
                    }
                    $newEvents[$event['sequence']] = $this->normalizeEvent($event);
                }
            }
            ksort($newEvents);
            foreach ($newEvents as $event) {
                // A gap would lose an action. Do not build a partial workflow.
                if ($event['sequence'] !== $sequence + 1) {
                    throw ValidationException::withMessages(['recording' => 'In der Aufnahme fehlt eine Aktion. Bitte erneut aufnehmen.']);
                }
                $events[] = $event;
                $sequence = $event['sequence'];
            }
            if (count($events) > self::MAX_EVENTS) {
                throw ValidationException::withMessages(['recording' => 'Die Aufnahme hat ihre sichere Groessenbegrenzung erreicht.']);
            }
            $recording->events_json = $events;
            $recording->last_event_sequence = $sequence;
            $recording->state_json = $this->safeState($state);
            // A trusted fresh daemon response may pause itself at its bounded
            // event limit. It may never reactivate a paused/stopped DB session.
            if ($recording->status === 'recording' && ($state['state'] ?? '') === 'paused') {
                $recording->status = 'paused';
            }
        });
    }

    protected function normalizeEvent(array $event): array
    {
        $sequence = $event['sequence'] ?? null;
        $type = (string) ($event['type'] ?? '');
        if (! is_int($sequence) || $sequence <= 0 || ! in_array($type, ['navigate', 'click', 'hover', 'fill', 'key', 'scroll'], true)) {
            throw ValidationException::withMessages(['event' => 'Die Aktionsart oder Ereignisnummer ist ungueltig.']);
        }
        $normalized = [
            'id' => 'event-'.$sequence,
            'sequence' => $sequence,
            'type' => $type,
            'label' => $this->shortText($event['label'] ?? match ($type) {
                'navigate' => 'Seite öffnen', 'click' => 'Element klicken', 'hover' => 'Mausbewegung',
                'fill' => 'Eingabe ausfüllen', 'key' => 'Taste drücken', 'scroll' => 'Seite scrollen',
            }, 180),
        ];
        if (in_array($type, ['click', 'hover', 'fill'], true) || ($type === 'scroll' && ! empty($event['selector']))) {
            $selector = $this->selectors($event);
            $taskKey = match ($type) {
                'click' => 'browser.click', 'hover' => 'browser.hover', 'fill' => 'input.fill_field', default => 'browser.scroll',
            };
            if ($this->selectorSyntax->errorFor($taskKey, 'selector', $selector) !== null) {
                throw ValidationException::withMessages(['eventSelector' => 'Bitte gueltige, mit Komma getrennte Selektoren eingeben.']);
            }
            $normalized['selector'] = $selector;
            if (isset($event['selectors'])) {
                $normalized['selectors'] = array_values(array_unique(array_map('trim', $event['selectors'])));
            }
            if (in_array($type, ['click', 'hover', 'fill'], true)) {
                $normalized += $this->targetMetadata($event);
            }
        }
        if ($type === 'navigate') {
            $normalized['url'] = self::replayUrl((string) ($event['url'] ?? ''));
        } elseif ($type === 'key') {
            $key = (string) ($event['key'] ?? '');
            if (! in_array($key, ['Enter', 'Tab'], true)) {
                throw ValidationException::withMessages(['event' => 'Nur Enter und Tab sind als Tastatur-Task unterstuetzt.']);
            }
            $normalized['key'] = $key;
        } elseif ($type === 'scroll') {
            $direction = (string) ($event['direction'] ?? 'down');
            $pixels = $event['pixels'] ?? 600;
            if (! in_array($direction, ['up', 'down'], true) || ! is_numeric($pixels) || (float) $pixels < 1 || (float) $pixels > 100000) {
                throw ValidationException::withMessages(['event' => 'Der Scrollwert ist ungueltig.']);
            }
            $normalized += ['direction' => $direction, 'pixels' => (int) $pixels];
        } elseif ($type === 'fill') {
            $inputType = strtolower((string) ($event['input_type'] ?? 'text'));
            $secret = (bool) ($event['secret'] ?? false) || (bool) ($event['sensitive'] ?? false) || $inputType === 'password';
            $source = (string) ($event['value_source'] ?? 'literal');
            if (! in_array($source, ['fixed', 'workflow_variable', 'literal'], true)) {
                throw ValidationException::withMessages(['eventValueSource' => 'Bitte eine gueltige Wertquelle waehlen.']);
            }
            if ($secret && $source === 'literal') {
                $source = 'workflow_variable';
                $event['workflow_variable'] = 'workflow_inputs.recorded_secret_'.$sequence;
            }
            $normalized += ['secret' => $secret, 'sensitive' => $secret, 'input_type' => $this->shortText($inputType, 40), 'value_source' => $source];
            if ($source === 'fixed') {
                $value = (string) ($event['value'] ?? '');
                if (! $this->catalog->isAllowedInputFillDataValue($value)) {
                    throw ValidationException::withMessages(['eventValue' => 'Bitte eine Datenquelle aus dem Task-Katalog waehlen.']);
                }
                $normalized['value'] = $value;
            } elseif ($source === 'workflow_variable') {
                $variable = trim((string) ($event['workflow_variable'] ?? ''));
                if (! $this->isVariablePath($variable)) {
                    throw ValidationException::withMessages(['eventWorkflowVariable' => 'Bitte einen gueltigen Variablennamen eingeben.']);
                }
                $normalized['workflow_variable'] = $variable;
            } else {
                $value = (string) ($event['value'] ?? '');
                if (mb_strlen($value) > 10000 || $this->catalog->resemblesInputFillDataReference($value)) {
                    throw ValidationException::withMessages(['eventValue' => 'Bitte einen freien Text mit hoechstens 10000 Zeichen eingeben, keinen Datenpfad.']);
                }
                $normalized['value'] = $value;
            }
        }
        if ($type === 'hover' && isset($event['mouse_path'])) {
            $normalized['mouse_path'] = $this->mousePath($event['mouse_path']);
        }

        return $normalized;
    }

    protected function selectors(array $event): string
    {
        if (isset($event['selectors'])) {
            if (! is_array($event['selectors']) || count($event['selectors']) > 8) {
                throw ValidationException::withMessages(['eventSelector' => 'Höchstens acht Selector-Alternativen sind erlaubt.']);
            }
            $selectors = [];
            foreach ($event['selectors'] as $selector) {
                if (! is_string($selector) || trim($selector) === '') {
                    throw ValidationException::withMessages(['eventSelector' => 'Eine Selector-Alternative ist ungueltig.']);
                }
                $selectors[] = trim($selector);
            }
            $value = implode(', ', array_unique($selectors));
        } else {
            // Never split blindly on commas: attribute values and :is/:not
            // clauses may contain commas. The existing parser validates those.
            $value = trim((string) ($event['selector'] ?? ''));
        }
        if ($value === '' || mb_strlen($value) > 4000) {
            throw ValidationException::withMessages(['eventSelector' => 'Bitte einen Selector mit hoechstens 4000 Zeichen eingeben.']);
        }

        return $value;
    }

    protected function mousePath(mixed $path): array
    {
        if (! is_array($path) || count($path) > 32) {
            throw ValidationException::withMessages(['event' => 'Der Mauspfad ist zu lang oder ungueltig.']);
        }
        $normalized = [];
        $previousMs = 0;
        foreach ($path as $point) {
            if (! is_array($point) || ! is_numeric($point['x'] ?? null) || ! is_numeric($point['y'] ?? null)
                || (float) $point['x'] < 0 || (float) $point['x'] > 1 || (float) $point['y'] < 0 || (float) $point['y'] > 1) {
                throw ValidationException::withMessages(['event' => 'Ein Punkt im Mauspfad ist ungueltig.']);
            }
            $ms = (int) ($point['ms'] ?? 0);
            if ($ms < $previousMs || $ms > 10000) {
                throw ValidationException::withMessages(['event' => 'Der zeitliche Mauspfad ist ungueltig.']);
            }
            $normalized[] = ['x' => (float) $point['x'], 'y' => (float) $point['y'], 'ms' => $ms];
            $previousMs = $ms;
        }

        return $normalized;
    }

    protected function targetMetadata(array $event): array
    {
        $signature = $event['target_signature'] ?? null;
        $fields = ['tag', 'type', 'name', 'role', 'test_id', 'aria_label', 'placeholder', 'text'];
        if (! is_array($signature) || array_diff(array_keys($signature), $fields) !== []
            || ! is_string($signature['tag'] ?? null) || preg_match('/^[a-z][a-z0-9-]{0,29}$/D', $signature['tag']) !== 1) {
            throw ValidationException::withMessages(['event' => 'Die Aufnahme hat keine gueltige Element-Identitaet geliefert. Bitte die Aktion erneut aufnehmen.']);
        }
        foreach ($signature as $field => $value) {
            if (! is_string($value) || mb_strlen($value) > ($field === 'text' ? 120 : 160)
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                throw ValidationException::withMessages(['event' => 'Ein Element-Merkmal der Aufnahme ist ungueltig.']);
            }
        }
        $sensitive = (bool) ($event['secret'] ?? false) || (bool) ($event['sensitive'] ?? false)
            || ($event['input_type'] ?? '') === 'password';
        if ($sensitive && array_intersect(array_keys($signature), ['aria_label', 'placeholder', 'text']) !== []) {
            throw ValidationException::withMessages(['event' => 'Sensible Eingabefelder duerfen keine Textmerkmale in der Aufnahme speichern.']);
        }
        $stable = $event['stable_selectors'] ?? [];
        $candidates = is_array($event['selectors'] ?? null) ? $event['selectors'] : [];
        if (! is_array($stable) || count($stable) > 5) {
            throw ValidationException::withMessages(['event' => 'Die stabilen Selector-Alternativen sind ungueltig.']);
        }
        foreach ($stable as $selector) {
            if (! is_string($selector) || ! in_array($selector, $candidates, true)) {
                throw ValidationException::withMessages(['event' => 'Ein stabiler Selector ist nicht durch die aufgenommenen Alternativen belegt.']);
            }
        }

        return ['target_signature' => $signature, 'stable_selectors' => array_values(array_unique($stable))];
    }

    protected function safeState(array $state): array
    {
        $safe = [
            'url' => $this->shortText($state['url'] ?? '', 2048),
            'title' => $this->shortText($state['title'] ?? '', 180),
            'viewport' => [
                'width' => max(1, min(4096, (int) data_get($state, 'viewport.width', 1365))),
                'height' => max(1, min(2160, (int) data_get($state, 'viewport.height', 768))),
            ],
        ];
        $selected = $state['selected'] ?? null;
        if (is_array($selected)) {
            $selector = $this->selectors($selected);
            $safe['selected'] = [
                'selector' => $selector,
                'label' => $this->shortText($selected['label'] ?? '', 180),
                'input_type' => $this->shortText($selected['input_type'] ?? '', 40),
                'tag' => $this->shortText($selected['tag'] ?? '', 30),
                'sensitive' => (bool) ($selected['sensitive'] ?? false) || ($selected['input_type'] ?? '') === 'password',
                'editable' => (bool) ($selected['editable'] ?? false),
                'scope' => 'main',
            ];
            if (isset($selected['selectors']) && is_array($selected['selectors'])) {
                $safe['selected']['selectors'] = array_values(array_unique(array_map('trim', $selected['selectors'])));
            }
            foreach (['x' => 'width', 'y' => 'height'] as $axis => $dimension) {
                if (is_numeric($selected[$axis] ?? null)) {
                    $safe['selected'][$axis] = max(0, min($safe['viewport'][$dimension], (float) $selected[$axis]));
                }
            }
        }
        $cursor = $state['cursor'] ?? null;
        if (is_array($cursor) && is_numeric($cursor['x'] ?? null) && is_numeric($cursor['y'] ?? null)) {
            $safe['cursor'] = [
                'x' => max(0, min($safe['viewport']['width'], (float) $cursor['x'])),
                'y' => max(0, min($safe['viewport']['height'], (float) $cursor['y'])),
            ];
        }

        return $safe;
    }

    protected function mutate(User $user, int $id, callable $callback, bool $incrementRevision = true): WorkflowRecording
    {
        $this->authorizeAdmin($user);

        return DB::transaction(function () use ($user, $id, $callback, $incrementRevision): WorkflowRecording {
            $recording = WorkflowRecording::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
            $callback($recording);
            if ($recording->isDirty()) {
                if ($incrementRevision) {
                    $recording->revision++;
                }
                $recording->last_activity_at = now();
                $recording->save();
            }

            return $recording;
        });
    }

    protected function sameRevision(WorkflowRecording $recording, WorkflowRecording $snapshot): bool
    {
        return $recording->revision === $snapshot->revision
            && $recording->runtime_generation === $snapshot->runtime_generation
            && $recording->status === $snapshot->status;
    }

    protected function markFailed(User $user, WorkflowRecording $snapshot): void
    {
        $this->mutate($user, (int) $snapshot->id, function (WorkflowRecording $recording) use ($snapshot): void {
            if ($this->sameRevision($recording, $snapshot)) {
                $recording->forceFill(['status' => 'failed', 'finished_at' => now(), 'state_json' => ['error' => 'browser_unavailable']]);
            }
        });
    }

    protected function requireStatus(WorkflowRecording $recording, array $allowed): void
    {
        if (! in_array($recording->status, $allowed, true)) {
            throw ValidationException::withMessages(['recording' => 'Diese Aktion ist im aktuellen Aufnahmezustand nicht moeglich.']);
        }
    }

    protected function authorizeAdmin(User $user): void
    {
        if (! $user->exists || ! $user->isAdmin() || ! $user->isActive() || $user->trashed()) {
            throw new AuthorizationException('Live Aufnahme ist nur fuer aktive Administratoren verfuegbar.');
        }
    }

    protected function eventIndex(array $events, string $eventId): int
    {
        foreach ($events as $index => $event) {
            if (($event['id'] ?? '') === $eventId) {
                return $index;
            }
        }
        throw ValidationException::withMessages(['event' => 'Die ausgewaehlte Aktion wurde nicht gefunden.']);
    }

    /** Sensitive / redacted URLs are not executable workflow configuration. */
    public static function replayUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (strlen($url) > 2048 || ! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7F]/', $url)) {
            throw ValidationException::withMessages(['startUrl' => 'Bitte eine vollstaendige HTTP- oder HTTPS-Adresse ohne Zugangsdaten eingeben.']);
        }
        if (stripos(rawurldecode($url), '[geschuetzt]') !== false) {
            throw ValidationException::withMessages(['startUrl' => 'Eine geschuetzte Browser-Adresse kann nicht als ausfuehrbare URL aufgenommen werden. Bitte eine Startseite ohne Zugangswerte verwenden.']);
        }
        foreach ([$parts['query'] ?? '', $parts['fragment'] ?? ''] as $parameters) {
            foreach (preg_split('/[&;?]/', $parameters) ?: [] as $parameter) {
                if (! str_contains($parameter, '=')) {
                    continue;
                }
                $key = rawurldecode(explode('=', $parameter, 2)[0]);
                $normalizedKey = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $key) ?? '');
                if (preg_match('/password|passwd|secret|token|apikey|authorization|credentials?/i', $normalizedKey)
                    || in_array($normalizedKey, ['session', 'sessionid', 'sessionkey', 'sessiontoken', 'sessionsecret', 'phpsessid', 'jsessionid', 'aspnetsessionid', 'sid', 'cookie', 'cookies', 'otp', 'pin', 'mfacode', 'securitycode', 'onetimecode'], true)) {
                    throw ValidationException::withMessages(['startUrl' => 'URLs mit Zugangswerten oder Sitzungsschluesseln werden nicht gespeichert. Bitte eine Startseite ohne diese Werte verwenden.']);
                }
            }
        }

        return $url;
    }

    protected function shortText(mixed $value, int $limit): string
    {
        if (! is_scalar($value) && $value !== null) {
            throw ValidationException::withMessages(['recording' => 'Ein Textwert der Aufnahme ist ungueltig.']);
        }

        return mb_substr(trim((string) $value), 0, $limit);
    }

    protected function isVariablePath(string $value): bool
    {
        return strlen($value) <= 160
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/D', $value) === 1;
    }
}
