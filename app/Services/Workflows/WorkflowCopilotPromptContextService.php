<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRevision;
use App\Models\WorkflowStep;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class WorkflowCopilotPromptContextService
{
    private const ROUTE_FIELDS = [
        'next',
        'on_partial',
        'on_error',
        'status_routes',
    ];

    private const VALUE_FIELDS = [
        'value',
        'input',
        'value_fallback',
        'fallback_value',
    ];

    public function __construct(
        protected WorkflowTaskCatalog $catalog,
        protected WorkflowRevisionEvidenceService $revisionEvidence,
    ) {}

    public function forWorkflow(
        Workflow $workflow,
        ?WorkflowCopilotSession $session = null,
        ?WorkflowStep $currentStep = null,
        array $checkpoint = [],
    ): array {
        $workflow->loadMissing(['steps' => fn ($query) => $query->ordered()]);
        $relevantTaskKeys = $this->relevantTaskKeys($workflow, $currentStep, $checkpoint);

        $context = [
            'context_version' => 2,
            'execution_contract' => $this->executionContract(),
            'workflow_structure' => $this->workflowStructureDocumentation(),
            'workflow_authoring_capabilities' => $this->authoringCapabilities(),
            'workflow' => $this->workflowSnapshot($workflow),
            'workflow_task_catalog' => $this->taskCatalogSnapshot($relevantTaskKeys),
            'workflow_task_catalog_index' => $this->taskCatalogIndex(),
            'workflow_task_catalog_scope' => [
                'mode' => 'relevant_subset',
                'included_task_keys' => $relevantTaskKeys,
                'lookup_instruction' => 'Weitere Task-Details bei Bedarf ueber list_task_catalog abrufen; keine nicht dokumentierten Felder erfinden.',
            ],
            'workflow_diagnostics' => $this->workflowDiagnostics($workflow),
            'revision_learning' => $this->revisionLearning($workflow),
        ];

        $sessionInputs = $session instanceof WorkflowCopilotSession && is_array($session->workflow_inputs_json)
            ? $session->workflow_inputs_json
            : [];
        $runtimeVariables = [];
        if ($session instanceof WorkflowCopilotSession) {
            $session->loadMissing('activeRun');
            $runtimeVariables = is_array(data_get($session->activeRun?->context_json, 'workflow_variables'))
                ? data_get($session->activeRun?->context_json, 'workflow_variables')
                : [];
        }
        $context['variable_provenance'] = $this->variableProvenance($workflow, $sessionInputs, $runtimeVariables);

        if ($session instanceof WorkflowCopilotSession) {
            $context['copilot_session'] = $this->sessionSnapshot($session);
            $context['runtime_state'] = $this->runtimeSnapshot($session, $checkpoint);
        }

        if ($currentStep instanceof WorkflowStep) {
            $context['current_execution'] = [
                'step_action_key' => (string) $currentStep->action_key,
                'step_name' => (string) $currentStep->name,
                'task_key' => trim((string) ($checkpoint['task_key'] ?? '')) ?: null,
                'resume_task_key' => trim((string) ($checkpoint['resume_task_key'] ?? $checkpoint['task_key'] ?? '')) ?: null,
                'failure_task_key' => trim((string) ($checkpoint['failure_task_key'] ?? '')) ?: null,
                'completed_task_key' => trim((string) ($checkpoint['completed_task_key'] ?? '')) ?: null,
                'failure_reason_code' => trim((string) ($checkpoint['failure_reason_code'] ?? data_get($checkpoint, 'result.reason_code', ''))) ?: null,
                'outcome' => trim((string) ($checkpoint['outcome'] ?? '')) ?: null,
                'successful' => array_key_exists('successful', $checkpoint)
                    ? (bool) $checkpoint['successful']
                    : null,
            ];
        }

        return (array) $this->sanitizeContext($context);
    }

    public function forInitialPlanning(
        Workflow $workflow,
        string $goal,
        array $successCriteria,
        array $workflowInputs,
    ): array {
        return (array) $this->sanitizeContext([
            'context_version' => 2,
            'execution_contract' => $this->executionContract(),
            'workflow_structure' => $this->workflowStructureDocumentation(),
            'workflow_authoring_capabilities' => $this->authoringCapabilities(),
            'workflow' => [
                'id' => (int) $workflow->id,
                'name' => (string) $workflow->name,
                'description' => (string) $workflow->description,
                'trigger_type' => (string) $workflow->trigger_type,
            ],
            'goal' => Str::limit(trim($goal), 4000, ''),
            'success_criteria' => $successCriteria,
            'workflow_inputs' => $this->inputSchema($workflowInputs),
            'variable_provenance' => $this->variableProvenance($workflow, $workflowInputs),
            'workflow_task_catalog' => $this->taskCatalogSnapshot(),
            'workflow_task_catalog_index' => $this->taskCatalogIndex(),
            'revision_learning' => $this->revisionLearning($workflow),
        ]);
    }

    public function executionContract(): array
    {
        return [
            'ordering' => [
                'steps' => 'Aktivierte Steps laufen nach position und id, ausser eine Route waehlt ein anderes Ziel.',
                'tasks' => 'Tasks laufen innerhalb eines Steps nach order_id/position. Eine ausdrueckliche Task-Route unterbricht die lineare Reihenfolge.',
                'task_checkpoint' => 'Der Copilot beobachtet an Task-Grenzen. Eine erfolgreiche Probe muss danach als Ergebnis der Original-Task fortgesetzt werden.',
            ],
            'outcomes' => [
                'success' => 'Task fachlich erfolgreich.',
                'failed' => 'Technischer Taskfehler oder logischer Falsch-Zweig. Bei IF-Tasks ist der Falsch-Zweig kein Workflowfehler; erst die aufgeloeste Route entscheidet ueber continue, complete oder fail.',
                'timeout' => 'Task hat ihr Zeitlimit erreicht.',
                'partial' => 'Task lieferte nur ein Teilergebnis.',
                'blocked' => 'Task war technisch erfolgreich, aber der sichtbare Seitenzustand blockiert das Workflow-Ziel.',
            ],
            'routing_precedence' => [
                'Task next bei success hat Vorrang vor Step success.',
                'Task on_error beziehungsweise status_routes.failed/timeout hat bei Fehlern Vorrang vor Step failed/timeout.',
                'Task on_partial beziehungsweise status_routes.partial hat bei Teilergebnissen Vorrang vor Step partial.',
                'Fehlt eine Erfolgsroute, wird im Step linear zur naechsten Task und danach zum naechsten Step fortgesetzt.',
                'Fehlt bei einem Fehler jede explizite Route, ist der Lauf terminal fehlgeschlagen.',
            ],
            'route_types' => [
                'card' => 'Springt zu card_key/card. action_key/step ist optional fuer denselben Step und Pflicht fuer einen anderen Step.',
                'step' => 'Springt zu action_key/step. Das reservierte Ziel next bedeutet den naechsten aktivierten Step.',
                'end' => 'Beendet den Workflow erfolgreich. Dies ist kein Sprung zu einer weiteren Task.',
                'fail' => 'Beendet den gesamten Workflow sofort als fehlgeschlagen. fail niemals fuer einen behebbaren oder optionalen Fehler verwenden.',
            ],
            'route_guards' => [
                'Rueckwaerts- und Selbstspruenge brauchen ein kleines max_attempts und eine belegbare Zustandsaenderung.',
                'Wiederholt eine Fehlerroute denselben Seitenzustand zu oft, beendet der Same-State-Guard den Lauf.',
                'Optionale UI-Hindernisse werden als echte Verzweigung modelliert: IF-Erfolg zum Handler, IF-Fehler zur normalen Fortsetzung; Handler-Erfolg und ein bereits verschwundenes Hindernis ebenfalls zur normalen Fortsetzung.',
                'Ein reparierbarer Fehler soll eine card- oder step-Route benutzen. type=fail ist ausschliesslich fuer bewusst terminale Fachfehler.',
            ],
            'task_semantics' => [
                'decision.element_exists' => 'Element gefunden ergibt condition_true, nicht gefunden condition_false. Beide Ergebnisse sind technisch erfolgreich. next und on_error bilden fachliche Verzweigungen; nur type=fail ist ein Workflowfehler.',
                'input.fill_field' => 'value_source=fixed nutzt value/input. value_source=workflow_variable nutzt workflow_variable; value_fallback ist nur der optionale Ersatzwert. Ein Variablenname darf nie als Literal in das Feld geschrieben werden.',
                'loop.for_each_element' => 'Automatische Laeufe behandeln Loop-Start bis loop.end als wiederholbares Segment. Im Studio ist jede Karte einzeln testbar; Scope, Cursor und Array-Zustand werden zwischen den Klicks persistiert. Reader und Consumer stehen zwischen Start und Ende.',
                'data.append_to_array' => 'Haengt den Wert aus value_from_variable dauerhaft an workflow_variables[array_name] an. Der Producer der Variable muss innerhalb desselben Loop-Durchlaufs vor dem Consumer liegen; nicht gleichzeitig mit collect_to_array fuer dasselbe Array verwenden.',
                'data.validate_inputs' => 'Nur ein fehlender Wert mit required=true fuehrt zum failed-Zweig. Fehlen ausschliesslich optionale Werte oder existieren keine Definitionen, ist die Task erfolgreich. Die Ausgabegruppe enthaelt Direktwerte, _inputs mit set/present/used_default und _summary.',
                'data.workflow_return' => 'Setzt den expliziten Rueckgabewert des Workflows. Erfolgskriterien fuer einen Rueckgabewert pruefen diesen Wert, nicht irgendeine zufaellige interne Variable.',
                'browser.open_browser_session' => 'Laedt Cookies/Storage und oeffnet standardmaessig die letzte gespeicherte URL; eine konfigurierte URL ueberschreibt dieses Ziel.',
            ],
            'selector_policy' => [
                'Stabile sichtbare Semantik hat Vorrang: data-testid/data-test, aria-label, title, placeholder, name, role/type und sichtbarer Text.',
                'Generierte oder wechselnde IDs, Positionsselector und lange Hash-Klassen nur als letzte Notloesung verwenden.',
                'Fuer Eingabefelder den Text aus Label, aria-label, title oder placeholder verwenden. Selector werden serverseitig aus beobachteter DOM-Evidenz uebernommen.',
                'Fuer Sammlungen einen Selector verwenden, der alle fachlich gleichen Treffer umfasst, nicht nur den ersten konkreten Link.',
            ],
            'variables' => [
                'Workflow-Eingaben werden unter workflow_inputs und als Workflow-Variablen bereitgestellt.',
                'Neue Task-Ausgaben muessen einen benannten output_variable-, array_name- oder Rueckgabepfad besitzen, wenn spaetere Tasks oder Erfolgskriterien sie benoetigen.',
                'Arrays werden unter workflow_variables[array_name] gespeichert und bleiben ueber Task- und Listengrenzen hinweg erhalten.',
                'Ein Loop sammelt Daten entweder ueber collect_to_array direkt oder ueber eine nachgelagerte data.append_to_array-Task. Beide Varianten gleichzeitig sind fuer dasselbe Array zu vermeiden.',
                'Passwoerter, Tokens, Cookies und feste Eingabewerte werden im Modellkontext redigiert.',
            ],
            'verification' => [
                'Die Endverifikation ist unveraenderlich: keine Reparatur, kein Skip und keine Mutation waehrend des Kontrolllaufs.',
                'Jeder optionale Pfad muss deshalb bereits statisch ohne Copilot-Sonderbehandlung bis zum Ziel laufen.',
                'Technischer Erfolg, deterministische Erfolgskriterien und der visuelle Zielzustand muessen gemeinsam bestehen.',
                'Ein technisch beendeter Lauf ohne verlangten Rueckgabewert oder ohne erforderliche Sammlung ist nicht erfolgreich.',
            ],
        ];
    }

    public function workflowStructureDocumentation(): array
    {
        return [
            'workflow' => 'Ein Workflow ist der gesamte Prozess. Er besitzt Ziel, Eingaben, Erfolgskriterien und eine geordnete Folge aktivierter Listen.',
            'lists' => 'Eine Liste entspricht einem Workflow-Step und gruppiert fachlich zusammengehoerige Tasks. Listen laufen nach position; am Listenende entscheidet ihre Erfolgs-, Fehler-, Timeout- oder Partial-Weiterleitung ueber das naechste Ziel.',
            'tasks' => 'Eine Task ist eine konkrete Karte innerhalb einer Liste. Tasks laufen linear, solange keine Task-eigene Route ein anderes Ziel waehlt. Task-Routen haben Vorrang vor Listen-Routen.',
            'task_routes' => [
                'next' => 'Erfolgsweg einer Task. Ohne next folgt die naechste Karte derselben Liste.',
                'on_error' => 'Fehler- oder Falschweg. Bei IF-Tasks ist dies der fachliche Nein-Zweig und muss nicht terminal sein.',
                'on_partial' => 'Weg fuer ein verwertbares, aber unvollstaendiges Ergebnis.',
                'status_routes' => 'Optionale statusgenaue Ziele, beispielsweise failed oder timeout.',
            ],
            'list_routes' => [
                'success' => 'Wird erst verwendet, wenn keine Task-Erfolgsroute mehr greift und das Listenende erreicht ist.',
                'failed' => 'Wird verwendet, wenn eine Task fehlschlaegt und keine Task-Fehlerroute gesetzt ist.',
                'partial' => 'Uebernimmt Teilergebnisse ohne Task-eigenes Ziel.',
                'timeout' => 'Behandelt Zeitueberschreitungen ohne Task-eigenes Timeout-Ziel.',
            ],
            'route_targets' => [
                'card' => 'Springt zu einer konkreten Task-Karte; bei einer anderen Liste muss auch deren action_key angegeben sein.',
                'step' => 'Springt zum Beginn einer Liste. step=next bedeutet die naechste aktivierte Liste.',
                'end' => 'Beendet den Workflow erfolgreich.',
                'fail' => 'Beendet den Workflow terminal fehlgeschlagen und ist nur fuer bewusst nicht reparierbare Fachfehler gedacht.',
            ],
            'data_flow' => 'Task-Ausgaben werden als benannte workflow_variables gespeichert. Nachfolgende Tasks und Listen lesen diese Namen; ein eingebetteter Workflow gibt sein Endergebnis ausdruecklich mit data.workflow_return zurueck.',
            'arrays' => 'Reader erzeugen pro Treffer ein Objekt. loop.for_each_element kann dieses Objekt ueber collect_to_array automatisch sammeln; alternativ haengt data.append_to_array den Wert aus value_from_variable an. dedupe_by verhindert Duplikate anhand eines Objektpfads.',
            'loop_recipe' => [
                'Loop-Start mit einem Selector fuer alle gleichartigen Treffer konfigurieren.',
                'Reader direkt zwischen Loop-Start und Loop-Ende setzen und dessen output_variable festlegen.',
                'Entweder collect_to_array am Loop setzen oder danach data.append_to_array verwenden.',
                'completion_target fuer den normalen Abschluss, empty_target nur fuer null Treffer und error_target fuer technische Fehler verwenden.',
                'Das gekoppelte loop.end nicht einzeln verschieben, umkonfigurieren oder entfernen.',
            ],
        ];
    }

    public function authoringCapabilities(): array
    {
        return [
            'empty_workflow' => 'Ein leerer Workflow ist ein gueltiger Ausgangspunkt. Der Copilot muss selbststaendig die benoetigten Listen, Tasks, Routen und Loop-Paare aus dem Katalog erstellen, bevor ein Testlauf startet.',
            'lists' => 'Listen duerfen erstellt, benannt, typisiert, sortiert, aktiviert und ueber success/failed/timeout/partial verbunden werden.',
            'tasks' => 'Tasks duerfen nur aus workflow_task_catalog erzeugt, innerhalb und zwischen Listen verschoben, vollstaendig konfiguriert und mit Task- oder Listenrouten verbunden werden.',
            'configuration' => 'Jeder Katalogeintrag liefert parameters, configuration, defaults und documentation. Nur diese Felder verwenden; erforderliche Felder muessen vor dem Test gesetzt sein.',
            'loops' => 'loop.for_each_element erzeugt immer ein gekoppeltes loop.end. Reader liegen im Loop-Body. Arrays werden entweder ueber collect_to_array oder data.append_to_array gesammelt.',
            'variables' => 'Workflow-Eingaben, Task-Ausgaben, Arrays und workflow_return sind benannte Datenpfade. Der Modellkontext enthaelt aus Sicherheitsgruenden nur Schema und gesetzte Zustandsmerkmale, nicht geheime Werte.',
            'browser_windows' => 'Browserfenster sind benannte Laufzeitkontexte. Browser-Tasks muessen das passende browser_window verwenden; ein Fenster kann im Studio separat per Selector-Probe untersucht werden.',
            'safe_iteration' => 'Nach einer Revision startet ein frischer Test. Bereits vorhandene funktionierende Listen oder Tasks werden nicht dupliziert; zuerst aktuellen Workflow, Diagnosen und Laufzeitstatus pruefen.',
        ];
    }

    public function taskCatalogSnapshot(?array $taskKeys = null): array
    {
        $catalog = collect($this->catalog->all());
        if (is_array($taskKeys)) {
            $catalog = $catalog->only($taskKeys);
        }

        return $catalog
            ->mapWithKeys(function (array $definition, string $taskKey): array {
                return [$taskKey => [
                    'label' => (string) ($definition['label'] ?? $taskKey),
                    'description' => (string) ($definition['description'] ?? ''),
                    'kind' => (string) ($definition['kind'] ?? 'data'),
                    'runner' => (string) ($definition['runner'] ?? 'node'),
                    'timeout_seconds' => max(0, (int) ($definition['timeout_seconds'] ?? 0)),
                    'documentation' => $this->taskDocumentationSnapshot($definition),
                    'parameters' => $this->catalogFields($definition),
                    'configuration' => $this->catalogConfiguration($definition),
                    'defaults' => $this->catalogDefaults($taskKey),
                    'routing_fields' => self::ROUTE_FIELDS,
                    'hidden_from_library' => (bool) ($definition['hidden_from_library'] ?? false),
                ]];
            })
            ->all();
    }

    protected function taskCatalogIndex(): array
    {
        return collect($this->catalog->all())
            ->groupBy(fn (array $definition): string => trim((string) ($definition['kind'] ?? 'data')) ?: 'data')
            ->map(fn ($definitions, string $kind): array => [
                'kind' => $kind,
                'count' => $definitions->count(),
                'task_keys' => $definitions->keys()->values()->all(),
            ])
            ->values()
            ->all();
    }

    protected function workflowSnapshot(Workflow $workflow): array
    {
        return [
            'id' => (int) $workflow->id,
            'name' => (string) $workflow->name,
            'description' => (string) $workflow->description,
            'trigger_type' => (string) $workflow->trigger_type,
            'copilot_revision' => (int) $workflow->copilot_revision,
            'steps' => $workflow->steps
                ->map(fn (WorkflowStep $step): array => [
                    'id' => (int) $step->id,
                    'name' => (string) $step->name,
                    'type' => (string) $step->type,
                    'action_key' => (string) $step->action_key,
                    'position' => (int) $step->position,
                    'enabled' => (bool) $step->is_enabled,
                    'retry_attempts' => (int) $step->retry_attempts,
                    'wait_after_seconds' => (int) $step->wait_after_seconds,
                    'routes' => is_array(data_get($step->config_json, 'routes'))
                        ? data_get($step->config_json, 'routes')
                        : [],
                    'tasks' => collect($step->task_cards)
                        ->map(fn (array $task): array => $this->taskSnapshot($task))
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    protected function revisionLearning(Workflow $workflow): array
    {
        return [
            'revision_history' => WorkflowRevision::query()
                ->where('workflow_id', $workflow->id)
                ->latest('revision_number')
                ->limit(80)
                ->get()
                ->map(fn (WorkflowRevision $revision): array => [
                    'revision' => (int) $revision->revision_number,
                    'parent_revision' => $revision->parent_revision_number,
                    'actor' => (string) $revision->actor,
                    'reason' => Str::limit((string) $revision->reason, 1000, ''),
                    'verified' => (bool) $revision->is_verified,
                    'diff' => $revision->diff_json,
                    'created_at' => $revision->created_at?->toIso8601String(),
                ])->all(),
            'execution_evidence' => $this->revisionEvidence->relevantHistory((int) $workflow->id, 100),
            'instruction' => 'Nutze erfolgreiche Evidenz erneut, vermeide bekannte Fehlersignaturen und schwaeche Ziel oder Erfolgskriterien niemals ab.',
        ];
    }

    protected function sessionSnapshot(WorkflowCopilotSession $session): array
    {
        $state = is_array($session->state_json) ? $session->state_json : [];

        return [
            'id' => (int) $session->id,
            'goal' => Str::limit(trim((string) $session->goal), 4000, ''),
            'success_criteria' => is_array($session->success_criteria_json) ? $session->success_criteria_json : [],
            'workflow_inputs' => $this->inputSchema(
                is_array($session->workflow_inputs_json) ? $session->workflow_inputs_json : [],
            ),
            'active_user_instructions' => array_slice(
                is_array($state['active_instructions'] ?? null) ? $state['active_instructions'] : [],
                -20,
            ),
            'status' => (string) $session->status,
            'phase' => (string) $session->phase,
            'current_revision' => (int) $session->current_revision,
        ];
    }

    protected function runtimeSnapshot(WorkflowCopilotSession $session, array $checkpoint): array
    {
        $session->loadMissing('activeRun');
        $run = $session->activeRun;
        $context = $run && is_array($run->context_json) ? $run->context_json : [];
        $windows = collect(data_get($context, 'browser_windows', []))
            ->filter(fn (mixed $window): bool => is_array($window))
            ->map(fn (array $window, string|int $key): array => [
                'name' => (string) ($window['key'] ?? $key),
                'title' => Str::limit(trim((string) ($window['title'] ?? '')), 500, ''),
                'url' => Str::limit(trim((string) ($window['url'] ?? $window['currentUrl'] ?? '')), 1000, ''),
                'target_available' => filled($window['targetId'] ?? $window['target_id'] ?? null),
            ])
            ->values()
            ->all();
        $variables = is_array(data_get($context, 'workflow_variables'))
            ? data_get($context, 'workflow_variables')
            : [];

        return [
            'run_id' => $run?->id,
            'run_status' => $run?->status,
            'current_step_id' => $run?->current_workflow_step_id,
            'next_step_action_key' => data_get($context, 'next_step_action_key'),
            'next_task_key' => data_get($context, 'next_task_key'),
            'browser_windows' => $windows,
            'workflow_variables' => $this->inputSchema($variables),
            'loop_state' => $this->sanitizeContext(data_get($context, 'loop_state', [])),
            'checkpoint' => $this->sanitizeContext($checkpoint),
            'repair_plan_fingerprints' => array_slice(
                is_array(data_get($session->state_json, 'repair_plan_fingerprints'))
                    ? data_get($session->state_json, 'repair_plan_fingerprints')
                    : [],
                -10,
            ),
        ];
    }

    protected function catalogConfiguration(array $definition): array
    {
        $form = is_array($definition['form'] ?? null) ? $definition['form'] : [];

        return array_filter([
            'uses_selector' => (bool) ($form['selector'] ?? false),
            'selector_required' => (bool) ($form['selector_required'] ?? false),
            'uses_value' => (bool) ($form['value'] ?? false),
            'value_required' => (bool) ($form['value_required'] ?? false),
            'uses_url' => (bool) ($form['url'] ?? false),
            'url_required' => (bool) ($form['url_required'] ?? false),
            'uses_browser_window' => (bool) ($form['browser_window'] ?? false),
            'browser_window_required' => (bool) ($form['browser_window_required'] ?? false),
            'can_create_browser_window' => (bool) ($form['browser_window_create'] ?? false),
            'supports_timeout' => (bool) ($form['timeout'] ?? false),
            'supports_mailbox_source' => (bool) ($form['mailbox_source'] ?? false),
        ], static fn (mixed $value): bool => $value !== false && $value !== null && $value !== '');
    }

    protected function catalogDefaults(string $taskKey): array
    {
        $card = $this->catalog->cardFromDefinition($taskKey, []);

        return Arr::only($card, [
            'timeout_seconds',
            'browser_window',
            'browser_window_name',
            'mailbox_source',
            'script_person_source',
        ]);
    }

    protected function inputSchema(array $inputs): array
    {
        return collect($inputs)
            ->map(fn (mixed $value, string|int $key): array => [
                'name' => (string) $key,
                'type' => get_debug_type($value),
                'provided' => $value !== null,
            ])
            ->values()
            ->all();
    }

    protected function variableProvenance(Workflow $workflow, array $workflowInputs = [], array $runtimeVariables = []): array
    {
        $entries = collect();

        foreach ($workflowInputs as $name => $value) {
            $entries->push([
                'name' => (string) $name,
                'type' => get_debug_type($value),
                'set' => $value !== null,
                'origin' => 'workflow_input',
                'step_action_key' => null,
                'task_key' => null,
                'catalog_task_key' => null,
            ]);
        }

        foreach ($workflow->steps as $step) {
            foreach (collect($step->task_cards)->filter(fn (mixed $task): bool => is_array($task)) as $task) {
                foreach ($this->taskVariableOutputs($task) as $output) {
                    $name = (string) $output['name'];
                    $entries->push([
                        'name' => $name,
                        'type' => array_key_exists($name, $runtimeVariables)
                            ? get_debug_type($runtimeVariables[$name])
                            : (string) $output['type'],
                        'set' => array_key_exists($name, $runtimeVariables) && $runtimeVariables[$name] !== null,
                        'origin' => 'task_output',
                        'step_action_key' => (string) $step->action_key,
                        'task_key' => (string) ($task['key'] ?? ''),
                        'catalog_task_key' => (string) ($task['task_key'] ?? ''),
                    ]);
                }
            }
        }

        $knownNames = $entries->pluck('name')->all();
        foreach ($runtimeVariables as $name => $value) {
            if (in_array((string) $name, $knownNames, true)) {
                continue;
            }
            $entries->push([
                'name' => (string) $name,
                'type' => get_debug_type($value),
                'set' => $value !== null,
                'origin' => 'runtime',
                'step_action_key' => null,
                'task_key' => null,
                'catalog_task_key' => null,
            ]);
        }

        return $entries
            ->filter(fn (array $entry): bool => trim((string) $entry['name']) !== '')
            ->unique(fn (array $entry): string => implode(':', [
                $entry['origin'],
                $entry['step_action_key'] ?? '',
                $entry['task_key'] ?? '',
                $entry['name'],
            ]))
            ->take(300)
            ->values()
            ->all();
    }

    protected function taskVariableOutputs(array $task): array
    {
        $catalogKey = (string) ($task['task_key'] ?? '');
        $outputs = collect();
        $push = function (mixed $name, string $type) use ($outputs): void {
            $name = trim((string) $name);
            if ($name !== '') {
                $outputs->push(['name' => $name, 'type' => $type]);
            }
        };

        $push($task['output_variable'] ?? $task['outputVariable'] ?? null, 'object');
        $push($task['output_group'] ?? $task['outputGroup'] ?? null, 'object');
        $push($task['output_array_name'] ?? $task['outputArrayName'] ?? null, 'array');

        if ($catalogKey === 'loop.for_each_element') {
            $push($task['store_current_element_as'] ?? $task['storeCurrentElementAs'] ?? 'current_result', 'element_scope');
            $push($task['store_index_as'] ?? $task['storeIndexAs'] ?? 'result_index', 'int');
            $push($task['collect_to_array'] ?? $task['collectToArray'] ?? null, 'array');
        }
        if ($catalogKey === 'data.append_to_array') {
            $push($task['array_name'] ?? $task['arrayName'] ?? null, 'array');
        }
        if (in_array($catalogKey, ['browser.read_element_fields', 'browser.read_searchengine_result'], true)
            && blank($task['output_variable'] ?? $task['outputVariable'] ?? null)
        ) {
            $push('current_result', 'object');
        }
        if ($catalogKey === 'data.workflow_return') {
            $push('workflow_return', 'mixed');
            $push('workflow_return_ok', 'bool');
        }

        return $outputs->unique('name')->values()->all();
    }

    protected function taskSnapshot(array $task): array
    {
        $taskKey = trim((string) ($task['task_key'] ?? ''));
        $definition = $taskKey !== '' ? $this->catalog->task($taskKey) : null;
        $parameterNames = collect(is_array($definition) ? $this->catalogFields($definition) : [])
            ->pluck('name')
            ->merge([
                'selector',
                'element_selector',
                'input_selector',
                'url',
                'browser_window',
                'browser_window_name',
                'timeout_seconds',
                'value_source',
                'workflow_variable',
                'value_fallback',
                'fallback_value',
                'value',
                'input',
                'loop_pair_id',
                'loop_pair_segment',
                'loop_start_key',
                'loop_end_key',
            ])
            ->filter()
            ->unique()
            ->values();
        $parameters = [];

        foreach ($parameterNames as $name) {
            if (! array_key_exists($name, $task) || $task[$name] === null || $task[$name] === '') {
                continue;
            }

            if (in_array($name, self::VALUE_FIELDS, true)) {
                $parameters[$name.'_configuration'] = [
                    'configured' => true,
                    'type' => get_debug_type($task[$name]),
                    'redacted' => true,
                ];

                continue;
            }

            $parameters[$name] = $task[$name];
        }

        return array_filter([
            'key' => trim((string) ($task['key'] ?? '')),
            'task_key' => $taskKey,
            'title' => trim((string) ($task['title'] ?? '')),
            'description' => trim((string) ($task['description'] ?? '')),
            'kind' => trim((string) ($task['kind'] ?? '')),
            'status' => trim((string) ($task['status'] ?? '')),
            'parameters' => $parameters,
            'next' => is_array($task['next'] ?? null) ? $task['next'] : null,
            'on_partial' => is_array($task['on_partial'] ?? null) ? $task['on_partial'] : null,
            'on_error' => is_array($task['on_error'] ?? null) ? $task['on_error'] : null,
            'status_routes' => is_array($task['status_routes'] ?? null) ? $task['status_routes'] : [],
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    protected function catalogFields(array $definition): array
    {
        $form = is_array($definition['form'] ?? null) ? $definition['form'] : [];
        $fields = [];

        foreach ([
            'selector' => ['label' => 'Selector', 'required_key' => 'selector_required'],
            'value' => ['label' => 'Wert', 'required_key' => 'value_required'],
            'url' => ['label' => 'URL', 'required_key' => 'url_required'],
            'browser_window' => ['label' => 'Browserfenster', 'required_key' => 'browser_window_required'],
            'mailbox_source' => ['label' => 'Mailbox-Quelle', 'required_key' => 'mailbox_source_required'],
        ] as $name => $metadata) {
            if (! (bool) ($form[$name] ?? false)) {
                continue;
            }

            $fields[] = array_filter([
                'name' => $name,
                'label' => (string) ($form[$name.'_label'] ?? $metadata['label']),
                'required' => (bool) ($form[$metadata['required_key']] ?? false),
                'placeholder' => (string) ($form[$name.'_placeholder'] ?? ''),
                'options' => is_array($form[$name.'_options'] ?? null) ? $form[$name.'_options'] : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        foreach (is_array($form['extra_fields'] ?? null) ? $form['extra_fields'] : [] as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = trim((string) ($field['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $fields[] = array_filter(Arr::only($field, [
                'name',
                'label',
                'type',
                'required',
                'default',
                'placeholder',
                'help',
                'options',
                'min',
                'max',
                'step',
            ]), static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return collect($fields)
            ->unique('name')
            ->values()
            ->all();
    }

    protected function taskDocumentationSnapshot(array $definition): array
    {
        $documentation = is_array($definition['documentation'] ?? null) ? $definition['documentation'] : [];

        return Arr::only($documentation, [
            'purpose',
            'use_when',
            'workflow_role',
            'outputs',
            'routing',
            'important_notes',
            'scope_behavior',
            'compatibility',
            'failure_modes',
            'recipes',
        ]);
    }

    protected function relevantTaskKeys(Workflow $workflow, ?WorkflowStep $currentStep, array $checkpoint): array
    {
        $keys = $workflow->steps
            ->flatMap(fn (WorkflowStep $step): array => collect($step->task_cards)
                ->filter(fn (mixed $task): bool => is_array($task))
                ->pluck('task_key')
                ->filter()
                ->all())
            ->merge([
                'browser.find_element',
                'browser.click',
                'wait.selector',
                'decision.element_exists',
                'loop.for_each_element',
                'loop.end',
                'browser.read_element_fields',
                'browser.read_searchengine_result',
                'data.append_to_array',
                'decision.array_length',
                'data.workflow_return',
            ]);

        if ($currentStep) {
            foreach (['failure_task_key', 'resume_task_key', 'task_key'] as $field) {
                $cardKey = trim((string) ($checkpoint[$field] ?? ''));
                $catalogKey = $cardKey !== ''
                    ? data_get(collect($currentStep->task_cards)->firstWhere('key', $cardKey), 'task_key')
                    : null;
                if (filled($catalogKey)) {
                    $keys->push((string) $catalogKey);
                }
            }
        }

        return $keys->map(fn (mixed $key): string => trim((string) $key))
            ->filter(fn (string $key): bool => $key !== '' && $this->catalog->task($key) !== null)
            ->unique()
            ->take(60)
            ->values()
            ->all();
    }

    protected function workflowDiagnostics(Workflow $workflow): array
    {
        $diagnostics = [];

        foreach ($workflow->steps as $step) {
            $tasks = collect($step->task_cards)->values();

            foreach ($tasks as $index => $task) {
                $taskKey = trim((string) ($task['key'] ?? ''));
                $catalogKey = trim((string) ($task['task_key'] ?? ''));

                foreach ([
                    'next' => $task['next'] ?? null,
                    'on_partial' => $task['on_partial'] ?? null,
                    'on_error' => $task['on_error'] ?? null,
                ] as $field => $route) {
                    if (! is_array($route)) {
                        continue;
                    }

                    $routeType = $this->routeType($route);
                    $targetTask = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

                    if ($routeType === 'fail') {
                        $diagnostics[] = [
                            'severity' => 'warning',
                            'code' => 'terminal_task_route',
                            'step_action_key' => (string) $step->action_key,
                            'task_key' => $taskKey,
                            'field' => $field,
                            'message' => 'Diese Route beendet den gesamten Workflow und ist nur fuer einen bewusst terminalen Fehler geeignet.',
                        ];
                    }

                    if ($targetTask === $taskKey && ($route['action_key'] ?? $route['step'] ?? $step->action_key) === $step->action_key) {
                        $diagnostics[] = [
                            'severity' => 'warning',
                            'code' => 'self_route',
                            'step_action_key' => (string) $step->action_key,
                            'task_key' => $taskKey,
                            'field' => $field,
                            'message' => 'Die Route springt auf dieselbe Task und kann den Same-State-Guard ausloesen.',
                        ];
                    }
                }

                if ($catalogKey === 'decision.element_exists') {
                    $nextTarget = $this->routeTarget($task['next'] ?? null);
                    $errorTarget = $this->routeTarget($task['on_error'] ?? null);

                    if ($errorTarget === '') {
                        $diagnostics[] = [
                            'severity' => 'warning',
                            'code' => 'condition_without_false_route',
                            'step_action_key' => (string) $step->action_key,
                            'task_key' => $taskKey,
                            'message' => 'Wenn das Element fehlt, existiert keine ausdrueckliche fachliche Fehlerroute.',
                        ];
                    } elseif ($nextTarget !== '' && $nextTarget === $errorTarget) {
                        $diagnostics[] = [
                            'severity' => 'warning',
                            'code' => 'condition_routes_same_target',
                            'step_action_key' => (string) $step->action_key,
                            'task_key' => $taskKey,
                            'message' => 'Gefunden und nicht gefunden fuehren zum selben Ziel; die IF-Verzweigung ist wirkungslos.',
                        ];
                    }
                }

                if ($catalogKey === 'input.fill_field') {
                    $valueSource = trim((string) ($task['value_source'] ?? 'fixed'));

                    if ($valueSource === 'workflow_variable' && blank($task['workflow_variable'] ?? null)) {
                        $diagnostics[] = [
                            'severity' => 'error',
                            'code' => 'workflow_variable_name_missing',
                            'step_action_key' => (string) $step->action_key,
                            'task_key' => $taskKey,
                            'message' => 'Workflow-Variable ist als Wertquelle gewaehlt, aber der Variablenname fehlt.',
                        ];
                    }
                }

                if ($catalogKey === 'loop.for_each_element') {
                    $pairId = trim((string) ($task['loop_pair_id'] ?? ''));
                    $endIndex = $pairId === '' ? false : $tasks->search(
                        fn (array $candidate): bool => trim((string) ($candidate['loop_pair_id'] ?? '')) === $pairId
                            && (string) ($candidate['task_key'] ?? '') === 'loop.end',
                    );

                    if ($endIndex === false) {
                        $diagnostics[] = [
                            'severity' => 'error',
                            'code' => 'loop_end_missing',
                            'step_action_key' => (string) $step->action_key,
                            'task_key' => $taskKey,
                            'message' => 'Zum Loop-Start wurde kein gekoppeltes Loop-Ende gefunden.',
                        ];
                    } else {
                        $body = $tasks->slice((int) $index + 1, max(0, (int) $endIndex - (int) $index - 1));
                        $collectToArray = trim((string) ($task['collect_to_array'] ?? ''));
                        $collectFromVariable = trim((string) ($task['collect_from_variable'] ?? 'current_result')) ?: 'current_result';

                        if ($collectToArray !== '') {
                            $hasProducer = $body->contains(fn (array $candidate): bool => trim((string) ($candidate['output_variable'] ?? '')) === $collectFromVariable);
                            $hasDuplicateAppend = $body->contains(fn (array $candidate): bool => (string) ($candidate['task_key'] ?? '') === 'data.append_to_array'
                                && trim((string) ($candidate['array_name'] ?? '')) === $collectToArray);

                            if (! $hasProducer) {
                                $diagnostics[] = [
                                    'severity' => 'warning',
                                    'code' => 'loop_collection_producer_missing',
                                    'step_action_key' => (string) $step->action_key,
                                    'task_key' => $taskKey,
                                    'message' => 'Der Loop sammelt aus "'.$collectFromVariable.'", aber im Loop-Body erzeugt keine Task diese output_variable.',
                                ];
                            }

                            if ($hasDuplicateAppend) {
                                $diagnostics[] = [
                                    'severity' => 'error',
                                    'code' => 'loop_collection_duplicate_append',
                                    'step_action_key' => (string) $step->action_key,
                                    'task_key' => $taskKey,
                                    'message' => 'Der Loop und data.append_to_array sammeln beide in "'.$collectToArray.'". Genau eine Sammelvariante verwenden.',
                                ];
                            }
                        }
                    }

                    foreach (['success_target', 'completion_target', 'empty_target', 'error_target'] as $routeField) {
                        $target = trim((string) ($task[$routeField] ?? ''));

                        if ($target !== '' && ! $tasks->contains(fn (array $candidate): bool => (string) ($candidate['key'] ?? '') === $target)) {
                            $diagnostics[] = [
                                'severity' => 'error',
                                'code' => 'loop_route_target_missing',
                                'step_action_key' => (string) $step->action_key,
                                'task_key' => $taskKey,
                                'field' => $routeField,
                                'message' => 'Das Loop-Ziel "'.$target.'" aus '.$routeField.' existiert nicht in dieser Liste.',
                            ];
                        }
                    }
                }

                $onErrorTarget = $this->routeTarget($task['on_error'] ?? null);

                if ($onErrorTarget !== '') {
                    $targetIndex = $tasks->search(
                        fn (array $candidate): bool => (string) ($candidate['key'] ?? '') === $onErrorTarget,
                    );

                    if ($targetIndex !== false && (int) $targetIndex <= (int) $index) {
                        $diagnostics[] = [
                            'severity' => 'warning',
                            'code' => 'backward_error_route',
                            'step_action_key' => (string) $step->action_key,
                            'task_key' => $taskKey,
                            'target_task_key' => $onErrorTarget,
                            'message' => 'Die Fehlerroute springt rueckwaerts und braucht eine nachweisbare Zustandsaenderung sowie ein enges max_attempts.',
                        ];
                    }
                }
            }

            foreach (is_array(data_get($step->config_json, 'routes')) ? data_get($step->config_json, 'routes') : [] as $outcome => $route) {
                if (is_array($route) && $this->routeType($route) === 'fail') {
                    $diagnostics[] = [
                        'severity' => 'warning',
                        'code' => 'terminal_step_route',
                        'step_action_key' => (string) $step->action_key,
                        'outcome' => (string) $outcome,
                        'message' => 'Diese Step-Route beendet den gesamten Workflow und ist nur fuer einen bewusst terminalen Fehler geeignet.',
                    ];
                }
            }
        }

        return array_slice($diagnostics, 0, 100);
    }

    protected function routeType(array $route): string
    {
        $type = Str::lower(trim((string) ($route['type'] ?? '')));
        $step = Str::lower(trim((string) ($route['action_key'] ?? $route['step'] ?? '')));
        $card = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if ($type !== '') {
            return $type;
        }

        if ($card !== '') {
            return 'card';
        }

        return in_array($step, ['end', 'fail'], true) ? $step : 'step';
    }

    protected function routeTarget(mixed $route): string
    {
        if (! is_array($route)) {
            return '';
        }

        return trim((string) (
            $route['card_key']
            ?? $route['card']
            ?? $route['action_key']
            ?? $route['step']
            ?? ''
        ));
    }

    protected function sanitizeContext(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 10) {
            return '[TRUNCATED]';
        }

        if (is_string($value)) {
            $value = preg_replace('/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/', '[TOKEN REDACTED]', $value) ?: $value;
            $value = preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', '[EMAIL REDACTED]', $value) ?: $value;
            $value = preg_replace('/\bwss?:\/\/\S+/i', '[WEBSOCKET REDACTED]', $value) ?: $value;

            return Str::limit($value, 4000, '');
        }

        if (! is_array($value)) {
            return $value;
        }

        $safe = [];

        foreach (array_slice($value, 0, 200, true) as $key => $item) {
            $normalizedKey = Str::lower(preg_replace('/[^a-z0-9]+/i', '', (string) $key) ?: '');

            if ($this->sensitiveContextKey($normalizedKey)) {
                $safe[$key] = '[REDACTED]';

                continue;
            }

            $safe[$key] = $this->sanitizeContext($item, $depth + 1);
        }

        return $safe;
    }

    protected function sensitiveContextKey(string $key): bool
    {
        return (bool) preg_match('/(?:password|passwd|pwd|secret|token|authorization|cookievalue|sessionstorage|localstorage|storagestate|sessionid|apikey|accesskey|credential|browserws|wsendpoint|websocket|outerhtml|innerhtml|fullhtml|htmlsource|rawhtml|(?:input|form|field)value)/', $key)
            || in_array($key, ['html', 'cookies', 'session', 'signature', 'input', 'value'], true);
    }
}
