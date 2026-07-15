<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Ai\AiConnectionService;
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
    ): array {
        if (! $this->needsInitialPlan($workflow)) {
            throw new RuntimeException('Eine Erstplanung ist nur fuer einen Workflow ohne konfigurierte Tasks erlaubt.');
        }

        $plan = $this->ai->json(
            $this->planningPrompt($workflow, $goal, $successCriteria, $workflowInputs),
            implode(' ', [
                'Du planst einen ausfuehrbaren AiUserFactory-Workflow ausschliesslich aus dem gelieferten WorkflowTaskCatalog.',
                'Antworte nur als JSON. Erfinde keine Task-Keys, Quellcode-Skripte oder nicht vorhandenen Funktionen.',
                'Verwende Workflow-Eingaben als Variablennamen und uebernimm keine geheimen Werte in die Definition.',
                'Plane einen linearen, testbaren Ablauf mit kleinen fachlichen Steps und genau den benoetigten Tasks.',
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

        DB::transaction(function () use ($workflow, $goal, $normalized): void {
            $lockedWorkflow = Workflow::query()->lockForUpdate()->findOrFail($workflow->id);

            if (! $this->needsInitialPlan($lockedWorkflow)) {
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
                    'action_key' => $this->uniqueStepActionKey($lockedWorkflow, $stepDefinition['name']),
                    'position' => ($index + 1) * 10,
                    'is_enabled' => true,
                    'config_json' => [
                        'description' => $stepDefinition['description'],
                        'tasks' => [],
                        'routes' => [],
                    ],
                ]);

                $this->taskOrdering->appendTasks($step, $stepDefinition['tasks']);
            }

            $lockedWorkflow->syncIncludedWorkflowReferences();
        });

        return $normalized;
    }

    protected function planningPrompt(
        Workflow $workflow,
        string $goal,
        array $successCriteria,
        array $workflowInputs,
    ): string {
        $catalog = collect($this->catalog->options())
            ->map(function (array $task): array {
                $form = is_array($task['form'] ?? null) ? $task['form'] : [];
                $fields = collect([
                    ($form['selector'] ?? false) ? 'selector' : null,
                    ($form['value'] ?? false) ? 'value' : null,
                    ($form['url'] ?? false) ? 'url' : null,
                    ($form['browser_window'] ?? false) ? 'browser_window' : null,
                    ($form['mailbox_source'] ?? false) ? 'mailbox_source' : null,
                    ...collect($form['extra_fields'] ?? [])
                        ->filter(fn (mixed $field): bool => is_array($field))
                        ->map(fn (array $field): string => (string) ($field['name'] ?? ''))
                        ->all(),
                ])->filter()->unique()->values()->all();

                return [
                    'task_key' => $task['key'],
                    'label' => $task['label'],
                    'kind' => $task['kind'],
                    'description' => $task['description'],
                    'parameters' => $fields,
                ];
            })
            ->values()
            ->all();
        $inputSchema = collect($workflowInputs)
            ->map(fn (mixed $value, string|int $key): array => [
                'name' => (string) $key,
                'type' => get_debug_type($value),
            ])
            ->values()
            ->all();

        return implode("\n", [
            'Erstelle die vollstaendige Erstdefinition fuer einen derzeit leeren Workflow.',
            'Workflow: '.json_encode(['id' => $workflow->id, 'name' => $workflow->name], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'Ziel: '.trim($goal),
            'Feste Erfolgskriterien: '.json_encode($successCriteria, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'Verfuegbare Workflow-Eingaben (nur Name und Typ): '.json_encode($inputSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'WorkflowTaskCatalog: '.json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'Erwartetes JSON-Schema: {"summary":"...","assumptions":["..."],"steps":[{"name":"...","type":"browser_task|data_task|preparation|data_processing|browser_control|interaction|decision|cleanup|wait","description":"...","tasks":[{"task_key":"catalog.key","title":"...","description":"...","parameters":{"url":"...","selector":"...","value":"variablenname","browser_window":"main"}}]}]}',
            'Felder in parameters duerfen nur aus der parameters-Liste des jeweiligen Katalogeintrags stammen. Nutze bei unbekannten Selektoren robuste leere/default Werte, damit die Live-Optimierung sie anhand der echten Seite pruefen kann.',
        ]);
    }

    /**
     * @return array{summary:string, assumptions:array, steps:array, task_count:int}
     */
    protected function normalizePlan(array $plan): array
    {
        $steps = [];
        $taskCount = 0;

        foreach (collect($plan['steps'] ?? [])->filter(fn (mixed $step): bool => is_array($step))->take(40) as $stepIndex => $stepDefinition) {
            $name = Str::limit(trim((string) ($stepDefinition['name'] ?? 'Step '.($stepIndex + 1))), 160, '');
            $type = trim((string) ($stepDefinition['type'] ?? WorkflowStep::TYPE_PREPARATION));
            $type = in_array($type, self::STEP_TYPES, true) ? $type : WorkflowStep::TYPE_PREPARATION;
            $tasks = [];

            foreach (collect($stepDefinition['tasks'] ?? [])->filter(fn (mixed $task): bool => is_array($task))->take(80) as $taskIndex => $taskDefinition) {
                $taskKey = trim((string) ($taskDefinition['task_key'] ?? ''));
                $catalogDefinition = $taskKey !== '' ? $this->catalog->task($taskKey) : null;

                if (! $catalogDefinition || $taskKey === 'loop.end') {
                    continue;
                }

                $parameters = is_array($taskDefinition['parameters'] ?? null) ? $taskDefinition['parameters'] : [];
                $baseKey = Str::slug((string) ($taskDefinition['title'] ?? $catalogDefinition['label'] ?? $taskKey)) ?: 'task';
                $cardKey = $this->uniqueTaskKey($tasks, $baseKey.'-'.($taskIndex + 1));
                $overrides = [
                    ...$parameters,
                    'key' => $cardKey,
                    'title' => Str::limit(trim((string) ($taskDefinition['title'] ?? $catalogDefinition['label'] ?? $taskKey)), 180, ''),
                    'description' => Str::limit(trim((string) ($taskDefinition['description'] ?? $catalogDefinition['description'] ?? '')), 1000, ''),
                ];
                $card = $this->catalog->cardFromDefinition($taskKey, $overrides);

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
                    $tasks[] = $this->catalog->cardFromDefinition('loop.end', [
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
                    $taskCount += 2;

                    continue;
                }

                $tasks[] = $card;
                $taskCount++;
            }

            if ($tasks === []) {
                continue;
            }

            $steps[] = [
                'name' => $name !== '' ? $name : 'Step '.(count($steps) + 1),
                'type' => $type,
                'description' => Str::limit(trim((string) ($stepDefinition['description'] ?? '')), 1000, ''),
                'tasks' => $tasks,
            ];
        }

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
}
