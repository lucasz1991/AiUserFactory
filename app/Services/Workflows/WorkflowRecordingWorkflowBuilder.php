<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowRecording;
use App\Models\WorkflowStep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Converts a reviewed recording to the existing, executable task contract. */
class WorkflowRecordingWorkflowBuilder
{
    public function __construct(
        protected WorkflowTaskCatalog $catalog,
        protected WorkflowRouteTargetMapper $routes,
        protected WorkflowDefinitionValidator $validator,
        protected WorkflowSelectorSyntaxService $selectorSyntax,
    ) {}

    // Called only inside the recording service's save transaction. No browser
    // work or existing-workflow mutation is performed here.
    public function build(WorkflowRecording $recording, ?string $name = null): Workflow
    {
        $events = is_array($recording->events_json) ? $recording->events_json : [];
        if ($events === []) {
            throw ValidationException::withMessages(['recording' => 'Die Aufnahme enthaelt noch keine Aktionen.']);
        }

        $name = trim($name ?? $recording->name);
        if ($name === '' || mb_strlen($name) > 180) {
            throw ValidationException::withMessages(['name' => 'Bitte einen Namen mit hoechstens 180 Zeichen eingeben.']);
        }

        $cards = [];
        $inputDefinitions = [];
        $secretInputNames = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                throw ValidationException::withMessages(['recording' => 'Die Aufnahme enthaelt eine ungueltige Aktion.']);
            }
            $card = $this->card($event);
            if (($event['type'] ?? '') === 'fill' && ($event['value_source'] ?? '') === 'workflow_variable') {
                $variable = (string) ($event['workflow_variable'] ?? '');
                if (str_starts_with($variable, 'workflow_inputs.')) {
                    $inputName = substr($variable, strlen('workflow_inputs.'));
                    $inputDefinitions[$inputName] = [
                        'name' => $inputName,
                        'source' => $variable,
                        'required' => true,
                        'type' => 'string',
                    ];
                    if ($event['secret'] ?? false) {
                        $secretInputNames[] = $inputName;
                    }
                }
            }
            $cards[] = $card;
        }

        if (($cards[0]['task_key'] ?? '') !== 'browser.open_url') {
            array_unshift($cards, $this->catalog->cardFromDefinition('browser.open_url', [
                'key' => 'recorded-start',
                'title' => 'Aufnahme-Startseite oeffnen',
                'url' => WorkflowRecordingService::replayUrl($recording->start_url),
                'browser_window' => 'main',
            ]));
        }

        if ($inputDefinitions !== []) {
            array_unshift($cards, $this->catalog->cardFromDefinition('data.validate_inputs', [
                'key' => 'recorded-inputs',
                'title' => 'Aufnahme-Eingaben pruefen',
                'input_definitions' => json_encode(array_values($inputDefinitions), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'output_group' => 'workflow_inputs',
            ]));
        }

        $slug = (Str::slug($name) ?: 'live-aufnahme').'-'.Str::lower((string) Str::uuid());
        $workflow = Workflow::query()->create([
            'name' => $name,
            'slug' => $slug,
            'description' => 'Aus einer administrativen Live-Aufnahme erstellt. Vor Aktivierung pruefen und testen.',
            'category' => 'Live Aufnahme',
            'is_active' => false,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [
                'recording_uuid' => $recording->recording_uuid,
                'recording_input_names' => array_keys($inputDefinitions),
                'recording_secret_input_names' => array_values(array_unique($secretInputNames)),
            ],
        ]);
        $step = $workflow->steps()->create([
            'name' => 'Live Aufnahme',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'live-recording',
            'position' => 10,
            'is_enabled' => true,
            'retry_attempts' => 0,
            'wait_after_seconds' => 0,
            'config_json' => ['tasks' => $cards],
        ]);

        $fail = $this->routes->fromValue('fail', fn () => null, fn () => null);
        $end = $this->routes->fromValue('end', fn () => null, fn () => null);
        foreach ($cards as $index => &$card) {
            $card['position'] = $card['order_id'] = ($index + 1) * 10;
            $card['next'] = $this->routes->nextTarget($step, $card['key'], null, fn () => collect([$step]));
            $card['on_error'] = $fail;
            $card['on_partial'] = $fail;
        }
        unset($card);
        // The last card explicitly terminates; no self / backward route exists.
        $cards[array_key_last($cards)]['next'] = $end;
        $step->forceFill(['config_json' => ['tasks' => $cards]])->save();

        $result = $this->validator->validate($workflow->fresh('steps'));
        if (! ($result['valid'] ?? false)) {
            throw ValidationException::withMessages(['recording' => 'Die Aufnahme kann noch nicht als gueltiger Workflow gespeichert werden. Bitte Eingaben und Selektoren pruefen.']);
        }

        return $workflow->fresh('steps');
    }

    protected function card(array $event): array
    {
        $type = (string) ($event['type'] ?? '');
        $taskKey = match ($type) {
            'navigate' => 'browser.open_url',
            'click' => 'browser.click',
            'hover' => 'browser.hover',
            'fill' => 'input.fill_field',
            'key' => 'browser.press_key',
            'scroll' => 'browser.scroll',
            default => throw ValidationException::withMessages(['recording' => 'Diese Aktionsart ist nicht unterstuetzt.']),
        };
        $overrides = [
            'key' => 'recorded-'.(string) ($event['sequence'] ?? ''),
            'title' => (string) ($event['label'] ?? $this->catalog->task($taskKey)['label']),
            'browser_window' => 'main',
        ];

        if (in_array($type, ['click', 'hover', 'fill'], true)) {
            $overrides['selector'] = trim((string) ($event['selector'] ?? ''));
            if ($overrides['selector'] === '' || $this->selectorSyntax->errorFor($taskKey, 'selector', $overrides['selector']) !== null) {
                throw ValidationException::withMessages(['recording' => 'Eine Aktion braucht einen gueltigen Element-Selector.']);
            }
        }
        if ($type === 'navigate') {
            $overrides['url'] = WorkflowRecordingService::replayUrl((string) ($event['url'] ?? ''));
        } elseif ($type === 'key') {
            $overrides['value'] = (string) ($event['key'] ?? '');
        } elseif ($type === 'scroll') {
            $overrides += [
                'direction' => (string) ($event['direction'] ?? 'down'),
                'pixels' => (int) ($event['pixels'] ?? 600),
                'steps' => 1,
                'delay_ms_between_steps' => 0,
                'stop_if_no_change' => 'true',
            ];
            if (($event['selector'] ?? '') !== '') {
                $overrides['selector'] = $event['selector'];
            }
        } elseif ($type === 'fill') {
            $overrides['value_source'] = (string) ($event['value_source'] ?? 'literal');
            if (($event['secret'] ?? false) && $overrides['value_source'] === 'literal') {
                throw ValidationException::withMessages(['recording' => 'Geheime Eingaben muessen aus einer Datenquelle oder Workflow-Eingabe kommen.']);
            }
            if ($overrides['value_source'] === 'workflow_variable') {
                $overrides['workflow_variable'] = (string) ($event['workflow_variable'] ?? '');
            } else {
                $overrides['value'] = (string) ($event['value'] ?? '');
            }
        }

        $card = $this->catalog->cardFromDefinition($taskKey, $overrides);
        if (in_array($type, ['click', 'hover', 'fill'], true)) {
            if (! is_array($event['target_signature'] ?? null) || empty($event['target_signature']['tag'])) {
                throw ValidationException::withMessages(['recording' => 'Einer Aktion fehlt die aufgenommene Element-Identitaet. Bitte erneut aufnehmen.']);
            }
            $card['recorded_selector_strict'] = true;
            $card['recorded_target_signature'] = $event['target_signature'];
            $card['recorded_stable_selectors'] = $event['stable_selectors'] ?? [];
        }
        // Bounds were enforced when accepting runtime events. Runtime hover can
        // replay these points before resolving and hovering the stable selector.
        if ($type === 'hover' && ($event['mouse_path'] ?? []) !== []) {
            $card['recorded_mouse_path'] = $event['mouse_path'];
        }

        return $card;
    }
}
