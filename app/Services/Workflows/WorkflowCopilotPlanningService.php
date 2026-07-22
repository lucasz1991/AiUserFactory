<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Ai\AiConnectionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class WorkflowCopilotPlanningService
{
    private const STEP_TYPES = [
        WorkflowStep::TYPE_PREPARATION,
        WorkflowStep::TYPE_DATA_PROCESSING,
        WorkflowStep::TYPE_BROWSER_CONTROL,
        WorkflowStep::TYPE_INTERACTION,
        WorkflowStep::TYPE_DECISION,
        WorkflowStep::TYPE_CLEANUP,
        WorkflowStep::TYPE_WAIT,
        WorkflowStep::TYPE_BROWSER_TASK,
        WorkflowStep::TYPE_DATA_TASK,
    ];

    public function __construct(
        protected AiConnectionService $ai,
        protected WorkflowTaskCatalog $catalog,
        protected WorkflowTaskOrderingService $taskOrdering,
        protected WorkflowCopilotPromptContextService $promptContexts,
        protected WorkflowDefinitionValidator $validator,
        protected WorkflowRetryRouteAutoRepairService $retryRouteRepair,
    ) {}

    public function needsInitialPlan(Workflow $workflow): bool
    {
        $workflow->loadMissing(['steps' => fn ($query) => $query->ordered()]);

        return $workflow->steps->every(
            fn (WorkflowStep $step): bool => collect($step->task_cards)
                ->filter(fn (mixed $task): bool => is_array($task))
                ->isEmpty(),
        );
    }

    /**
     * @return array{summary:string, assumptions:array, steps:array, task_count:int}
     */
    public function planAndApply(
        Workflow $workflow,
        string $goal,
        array $successCriteria = [],
        array $workflowInputs = [],
        array $historyPreflight = [],
    ): array {
        $normalized = $this->plan($workflow, $goal, $successCriteria, $workflowInputs, $historyPreflight);

        return $this->applyPlan($workflow, $goal, $normalized, $successCriteria, $workflowInputs);
    }

    /**
     * Build the immutable blueprint without changing the workflow definition.
     *
     * @return array{summary:string, assumptions:array, steps:array, task_count:int}
     */
    public function plan(
        Workflow $workflow,
        string $goal,
        array $successCriteria = [],
        array $workflowInputs = [],
        array $historyPreflight = [],
    ): array {
        if (! $this->needsInitialPlan($workflow)) {
            throw new RuntimeException('Eine Erstplanung ist nur fuer einen Workflow ohne konfigurierte Tasks erlaubt.');
        }

        $plan = $this->ai->json(
            $this->planningPrompt($workflow, $goal, $successCriteria, $workflowInputs, $historyPreflight),
            implode(' ', [
                'Du planst einen ausfuehrbaren AiUserFactory-Workflow ausschliesslich aus dem gelieferten WorkflowTaskCatalog.',
                'Antworte nur als JSON. Erfinde keine Task-Keys, Quellcode-Skripte oder nicht vorhandenen Funktionen.',
                'Verwende Workflow-Eingaben als Variablennamen und uebernimm keine geheimen Werte in die Definition.',
                'Plane einen linearen, testbaren Ablauf mit kleinen fachlichen Steps und genau den benoetigten Tasks.',
                'Nutze fuer Verzweigungen ausschliesslich die dokumentierten Task- und Step-Routen. type=fail ist terminal.',
                'Der Workflow muss nach der Planung ohne Copilot-Skip in einem unveraenderlichen Kontrolllauf ausfuehrbar sein.',
                'Ein komplett leerer Workflow ist beabsichtigt: Erstelle selbststaendig alle erforderlichen Listen, Tasks, Routen und gekoppelten Loop-Enden.',
            ]),
            [
                'temperature' => 0.1,
                'max_completion_tokens' => 5000,
                '_timeout' => 90,
            ],
        );
        $normalized = $this->normalizePlan($plan);

        if ($normalized['steps'] === [] || $normalized['task_count'] < 1) {
            throw new RuntimeException('Die Planungs-KI hat keinen ausfuehrbaren Workflow mit katalogkonformen Tasks geliefert.');
        }

        return $normalized;
    }

    /**
     * @param  array{summary:string, assumptions:array, steps:array, task_count:int}  $normalized
     * @return array{summary:string, assumptions:array, steps:array, task_count:int}
     */
    public function applyPlan(
        Workflow $workflow,
        string $goal,
        array $normalized,
        array $successCriteria = [],
        array $workflowInputs = [],
        bool $replaceExisting = false,
    ): array {
        if ($normalized['steps'] === [] || $normalized['task_count'] < 1) {
            throw new RuntimeException('Der Workflow-Plan enthaelt keine ausfuehrbaren Tasks.');
        }

        DB::transaction(function () use ($workflow, $goal, $normalized, $successCriteria, $workflowInputs, $replaceExisting): void {
            $lockedWorkflow = Workflow::query()->lockForUpdate()->findOrFail($workflow->id);

            if (! $replaceExisting && ! $this->needsInitialPlan($lockedWorkflow)) {
                throw new RuntimeException('Der Workflow wurde waehrend der Erstplanung bereits bearbeitet.');
            }

            $lockedWorkflow->steps()->delete();
            $settings = is_array($lockedWorkflow->settings_json) ? $lockedWorkflow->settings_json : [];
            $settings['copilot_initial_plan'] = [
                'created_at' => now()->toIso8601String(),
                'goal' => Str::limit(trim($goal), 4000, ''),
                'summary' => $normalized['summary'],
                'assumptions' => $normalized['assumptions'],
                'task_count' => $normalized['task_count'],
            ];
            $attributes = ['settings_json' => $settings];

            if (blank($lockedWorkflow->description)) {
                $attributes['description'] = Str::limit(trim($goal), 1000, '');
            }

            $lockedWorkflow->forceFill($attributes)->save();

            foreach ($normalized['steps'] as $index => $stepDefinition) {
                $step = $lockedWorkflow->steps()->create([
                    'name' => $stepDefinition['name'],
                    'type' => $stepDefinition['type'],
                    'action_key' => $stepDefinition['action_key'],
                    'position' => ($index + 1) * 10,
                    'is_enabled' => true,
                    'config_json' => [
                        'description' => $stepDefinition['description'],
                        'tasks' => [],
                        'routes' => $stepDefinition['routes'],
                    ],
                ]);

                $this->taskOrdering->appendTasks($step, $stepDefinition['tasks']);
            }

            $lockedWorkflow->syncIncludedWorkflowReferences();
            $freshWorkflow = $lockedWorkflow->fresh(['steps']) ?? $lockedWorkflow;
            $this->retryRouteRepair->repair($freshWorkflow);
            $this->validator->assertValid($freshWorkflow, $successCriteria, $workflowInputs);
        });

        return $normalized;
    }

    protected function planningPrompt(
        Workflow $workflow,
        string $goal,
        array $successCriteria,
        array $workflowInputs,
        array $historyPreflight = [],
    ): string {
        $context = $this->promptContexts->forInitialPlanning(
            $workflow,
            $goal,
            $successCriteria,
            $workflowInputs,
            $historyPreflight,
        );

        return implode("\n", [
            'Erstelle die vollstaendige Erstdefinition fuer einen derzeit leeren Workflow.',
            'Es existieren absichtlich noch keine Listen oder Tasks. Erzeuge beides selbststaendig aus dem gelieferten Katalog und liefere keinen blossen Vorschlag ohne ausfuehrbare Definition.',
            'Verbindlicher Task-, Routing- und Workflow-Kontext: '.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'Erwartetes JSON-Schema: {"summary":"...","assumptions":["..."],"steps":[{"name":"...","action_key":"stabiler-step-key","type":"browser_task|data_task|preparation|data_processing|browser_control|interaction|decision|cleanup|wait","description":"...","routes":{"success":{"type":"step","step":"next"},"failed":{"type":"step","step":"fehlerbehandlung"}},"tasks":[{"key":"stabiler-task-key","task_key":"catalog.key","title":"...","description":"...","parameters":{"url":"...","selector":"...","workflow_variable":"...","browser_window":"main"},"next":{"type":"card","card":"naechste-task"},"on_error":{"type":"step","step":"fehlerbehandlung"}}]}]}',
            'Felder in parameters duerfen nur aus der parameters-Liste des jeweiligen Katalogeintrags stammen. next, on_partial, on_error und status_routes sind Routingfelder und keine normalen parameters.',
            'decision.element_exists braucht fuer den gefundenen und den nicht gefundenen Fall unterschiedliche Ziele. Optionales Fehlen darf nicht zu einem Klick auf das fehlende Element oder in eine Selbstschleife routen.',
            'Fuer Sammlungen: loop.for_each_element, danach ein Reader und das gekoppelte loop.end verwenden. Entweder collect_to_array am Loop oder data.append_to_array einsetzen, nicht beides fuer dasselbe Array. Normaler Abschluss, leere Liste und technischer Fehler brauchen getrennte Ziele.',
            'Nutze bei unbekannten Selektoren robuste leere/default Werte, damit die Live-Optimierung sie anhand der echten Seite pruefen kann. Fuer bekannte Eingabefelder semantische Attribute wie title, aria-label, placeholder oder name statt generierter IDs verwenden.',
        ]);
    }

    /**
     * @return array{summary:string, assumptions:array, steps:array, task_count:int}
     */
    protected function normalizePlan(array $plan): array
    {
        $steps = [];
        $taskCount = 0;
        $usedStepActionKeys = [];

        foreach (collect($plan['steps'] ?? [])->filter(fn (mixed $step): bool => is_array($step))->take(40) as $stepIndex => $stepDefinition) {
            $name = Str::limit(trim((string) ($stepDefinition['name'] ?? 'Step '.($stepIndex + 1))), 160, '');
            $type = trim((string) ($stepDefinition['type'] ?? WorkflowStep::TYPE_PREPARATION));
            $type = in_array($type, self::STEP_TYPES, true) ? $type : WorkflowStep::TYPE_PREPARATION;
            $actionKey = $this->uniquePlanKey(
                $usedStepActionKeys,
                (string) ($stepDefinition['action_key'] ?? $stepDefinition['actionKey'] ?? $name),
                'step',
            );
            $usedStepActionKeys[] = $actionKey;
            $tasks = [];
            $openLoopEnd = null;

            foreach (collect($stepDefinition['tasks'] ?? [])->filter(fn (mixed $task): bool => is_array($task))->take(80) as $taskIndex => $taskDefinition) {
                $taskKey = trim((string) ($taskDefinition['task_key'] ?? ''));

                if ($taskKey === 'loop.end') {
                    if (is_array($openLoopEnd)) {
                        $tasks[] = $openLoopEnd;
                        $taskCount++;
                        $openLoopEnd = null;
                    }

                    continue;
                }

                $catalogDefinition = $taskKey !== '' ? $this->catalog->task($taskKey) : null;

                if (! $catalogDefinition) {
                    continue;
                }

                if ($taskKey === 'loop.for_each_element' && is_array($openLoopEnd)) {
                    $tasks[] = $openLoopEnd;
                    $taskCount++;
                    $openLoopEnd = null;
                }

                $parameters = is_array($taskDefinition['parameters'] ?? null) ? $taskDefinition['parameters'] : [];
                $baseKey = Str::slug((string) ($taskDefinition['title'] ?? $catalogDefinition['label'] ?? $taskKey)) ?: 'task';
                $requestedKey = trim((string) ($taskDefinition['key'] ?? ''));
                $cardKey = $this->uniqueTaskKey(
                    is_array($openLoopEnd) ? [...$tasks, $openLoopEnd] : $tasks,
                    $requestedKey !== '' ? $requestedKey : $baseKey.'-'.($taskIndex + 1),
                );
                $overrides = [
                    ...$parameters,
                    'key' => $cardKey,
                    'title' => Str::limit(trim((string) ($taskDefinition['title'] ?? $catalogDefinition['label'] ?? $taskKey)), 180, ''),
                    'description' => Str::limit(trim((string) ($taskDefinition['description'] ?? $catalogDefinition['description'] ?? '')), 1000, ''),
                ];
                $card = $this->catalog->cardFromDefinition($taskKey, $overrides);
                $card['__planned_routing'] = Arr::only($taskDefinition, [
                    'next',
                    'on_partial',
                    'on_error',
                    'status_routes',
                ]);

                if ($taskKey === 'loop.for_each_element') {
                    $pairId = 'loop-'.(string) Str::uuid();
                    $endKey = $this->uniqueTaskKey([...$tasks, $card], $cardKey.'-end');
                    $card = array_replace($card, [
                        'loop_pair_id' => $pairId,
                        'loop_pair_segment' => 'start',
                        'loop_start_key' => $cardKey,
                        'loop_end_key' => $endKey,
                    ]);
                    $tasks[] = $card;
                    $openLoopEnd = $this->catalog->cardFromDefinition('loop.end', [
                        'key' => $endKey,
                        'title' => 'Loop-Ende: '.$card['title'],
                        'description' => 'Automatisches Endsegment fuer '.$card['title'].'.',
                        'browser_window' => $card['browser_window'] ?? 'main',
                        'browser_window_name' => $card['browser_window_name'] ?? $card['browser_window'] ?? 'main',
                        'loop_pair_id' => $pairId,
                        'loop_pair_segment' => 'end',
                        'loop_start_key' => $cardKey,
                        'loop_end_key' => $endKey,
                    ]);
                    $taskCount++;

                    continue;
                }

                $tasks[] = $card;
                $taskCount++;
            }

            if (is_array($openLoopEnd)) {
                $tasks[] = $openLoopEnd;
                $taskCount++;
            }

            if ($tasks === []) {
                continue;
            }

            $steps[] = [
                'name' => $name !== '' ? $name : 'Step '.(count($steps) + 1),
                'action_key' => $actionKey,
                'type' => $type,
                'description' => Str::limit(trim((string) ($stepDefinition['description'] ?? '')), 1000, ''),
                'tasks' => $tasks,
                '__planned_routes' => is_array($stepDefinition['routes'] ?? null) ? $stepDefinition['routes'] : [],
            ];
        }

        $taskKeysByStep = collect($steps)
            ->mapWithKeys(fn (array $step): array => [
                $step['action_key'] => collect($step['tasks'])
                    ->pluck('key')
                    ->filter()
                    ->map(fn (mixed $key): string => (string) $key)
                    ->values()
                    ->all(),
            ])
            ->all();
        $steps = collect($steps)
            ->map(function (array $step) use ($taskKeysByStep): array {
                $sourceStep = (string) $step['action_key'];
                $routes = [];

                foreach ($step['__planned_routes'] as $outcome => $route) {
                    $outcome = Str::lower(trim((string) $outcome));

                    if (! in_array($outcome, ['success', 'failed', 'timeout', 'partial', 'default'], true)) {
                        continue;
                    }

                    $normalizedRoute = $this->normalizePlannedRoute($route, $sourceStep, $taskKeysByStep);

                    if ($normalizedRoute !== null) {
                        $routes[$outcome] = $normalizedRoute;
                    }
                }

                $tasks = collect($step['tasks'])
                    ->map(function (array $task) use ($sourceStep, $taskKeysByStep): array {
                        $plannedRouting = is_array($task['__planned_routing'] ?? null) ? $task['__planned_routing'] : [];
                        unset($task['__planned_routing']);

                        foreach (['next', 'on_partial', 'on_error'] as $field) {
                            $route = $this->normalizePlannedRoute(
                                $plannedRouting[$field] ?? null,
                                $sourceStep,
                                $taskKeysByStep,
                            );

                            if ($route !== null) {
                                $task[$field] = $route;
                            }
                        }

                        $statusRoutes = [];

                        foreach (is_array($plannedRouting['status_routes'] ?? null) ? $plannedRouting['status_routes'] : [] as $outcome => $route) {
                            $normalizedRoute = $this->normalizePlannedRoute($route, $sourceStep, $taskKeysByStep);

                            if ($normalizedRoute !== null) {
                                $statusRoutes[Str::lower(trim((string) $outcome))] = $normalizedRoute;
                            }
                        }

                        if ($statusRoutes !== []) {
                            $task['status_routes'] = $statusRoutes;
                        }

                        return $task;
                    })
                    ->values()
                    ->all();

                unset($step['__planned_routes']);
                $step['routes'] = $routes;
                $step['tasks'] = $tasks;

                return $step;
            })
            ->values()
            ->all();

        return [
            'summary' => Str::limit(trim((string) ($plan['summary'] ?? 'Autonom geplante Workflow-Erstdefinition.')), 4000, ''),
            'assumptions' => collect($plan['assumptions'] ?? [])
                ->filter(fn (mixed $assumption): bool => is_scalar($assumption))
                ->map(fn (mixed $assumption): string => Str::limit(trim((string) $assumption), 1000, ''))
                ->filter()
                ->take(30)
                ->values()
                ->all(),
            'steps' => $steps,
            'task_count' => $taskCount,
        ];
    }

    protected function uniqueStepActionKey(Workflow $workflow, string $name): string
    {
        $base = Str::slug($name) ?: 'step';
        $candidate = $base;
        $suffix = 2;

        while ($workflow->steps()->where('action_key', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    protected function uniqueTaskKey(array $tasks, string $base): string
    {
        $base = Str::slug($base) ?: 'task';
        $candidate = $base;
        $suffix = 2;
        $keys = collect($tasks)->pluck('key')->map(fn (mixed $key): string => (string) $key)->all();

        while (in_array($candidate, $keys, true)) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    protected function uniquePlanKey(array $existing, string $base, string $fallback): string
    {
        $base = Str::slug($base) ?: $fallback;
        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, $existing, true)) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    protected function normalizePlannedRoute(
        mixed $route,
        string $sourceStep,
        array $taskKeysByStep,
    ): ?array {
        if (is_string($route)) {
            $target = Str::slug($route);

            if (in_array($target, ['next', 'end', 'fail'], true)) {
                $route = ['type' => $target === 'next' ? 'step' : $target, 'step' => $target];
            } elseif (in_array($target, $taskKeysByStep[$sourceStep] ?? [], true)) {
                $route = ['type' => 'card', 'step' => $sourceStep, 'card' => $target];
            } elseif (array_key_exists($target, $taskKeysByStep)) {
                $route = ['type' => 'step', 'step' => $target];
            } else {
                return null;
            }
        }

        if (! is_array($route)) {
            return null;
        }

        $type = Str::lower(trim((string) ($route['type'] ?? '')));
        $step = Str::slug((string) ($route['action_key'] ?? $route['step'] ?? ''));
        $card = Str::slug((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if ($type === '') {
            $type = $card !== ''
                ? 'card'
                : (in_array($step, ['end', 'fail'], true) ? $step : 'step');
        }

        if (in_array($type, ['end', 'fail'], true)) {
            return [
                'type' => $type,
                'step' => $type,
                'label' => Str::limit(trim((string) ($route['label'] ?? ($type === 'end' ? 'Workflow abschliessen' : 'Workflow fehlschlagen'))), 180, ''),
            ];
        }

        if ($type === 'step') {
            if ($step === '' || ($step !== 'next' && ! array_key_exists($step, $taskKeysByStep))) {
                return null;
            }

            return array_filter([
                'type' => 'step',
                'action_key' => $step,
                'step' => $step,
                'label' => Str::limit(trim((string) ($route['label'] ?? $step)), 180, ''),
                'max_attempts' => isset($route['max_attempts'])
                    ? min(20, max(1, (int) $route['max_attempts']))
                    : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        if ($type !== 'card' || $card === '') {
            return null;
        }

        $targetStep = $step !== '' ? $step : $sourceStep;

        if (! array_key_exists($targetStep, $taskKeysByStep)
            || ! in_array($card, $taskKeysByStep[$targetStep], true)) {
            return null;
        }

        return array_filter([
            'type' => 'card',
            'action_key' => $targetStep,
            'step' => $targetStep,
            'card_key' => $card,
            'card' => $card,
            'label' => Str::limit(trim((string) ($route['label'] ?? $card)), 180, ''),
            'max_attempts' => isset($route['max_attempts'])
                ? min(20, max(1, (int) $route['max_attempts']))
                : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
