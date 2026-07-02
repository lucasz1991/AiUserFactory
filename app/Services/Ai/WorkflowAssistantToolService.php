<?php

namespace App\Services\Ai;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowTaskCatalog;
use App\Services\Workflows\WorkflowTaskOrderingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowAssistantToolService
{
    public function __construct(
        protected WorkflowTaskCatalog $taskCatalog,
        protected WorkflowTaskOrderingService $taskOrdering,
    ) {}

    public function systemPrompt(string $extraInstructions = ''): string
    {
        return trim(implode("\n", array_filter([
            'Du bist der AI User Factory Workflow Copilot.',
            'Sprich Deutsch, kurz, konkret und operativ. Nutze vorhandene Workflow-Daten und Tools, bevor du Vermutungen anstellst.',
            'Du kannst Workflows analysieren, neue Workflows anlegen, Listen/Steps erstellen, Tags setzen, Tasks konfigurieren und vorhandene Workflows aktualisieren.',
            'Nutze list_task_catalog, bevor du konkrete Task-Keys erfindest. Nutze get_workflow_context oder analyze_last_workflow_run, bevor du Fehlerursachen bewertest.',
            'Bei eingebetteten Workflows: nutze list_embedded_workflow_candidates und erklaere kurz, warum ein Workflow eingebettet werden sollte.',
            'Wenn du dem Nutzer mehrere klare Optionen anbietest, nutze present_chat_options, damit klickbare Auswahlbuttons erscheinen.',
            'Lege keine Loeschoperationen an. Fuer gefaehrliche Aenderungen erst eine kurze Zusammenfassung geben und dann eine konkrete Aktualisierungsfunktion nutzen, wenn der Nutzer es beauftragt.',
            trim($extraInstructions),
        ])));
    }

    public function tools(): array
    {
        return [
            $this->tool('list_workflows', 'Liste Workflows mit optionalem Suchbegriff, Kategorie und Kurzstruktur.', [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'category' => ['type' => 'string'],
                    'include_steps' => ['type' => 'boolean'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                ],
            ]),
            $this->tool('get_workflow_context', 'Lade Details, Listen, Tasks, Tags und letzte Runs zu einem Workflow.', [
                'type' => 'object',
                'properties' => [
                    'workflow_id' => ['type' => 'integer'],
                    'workflow_slug' => ['type' => 'string'],
                    'workflow_query' => ['type' => 'string'],
                    'include_runs' => ['type' => 'boolean'],
                ],
            ]),
            $this->tool('analyze_last_workflow_run', 'Analysiere den letzten oder angegebenen Workflow-Run inklusive Step- und Task-Ergebnissen.', [
                'type' => 'object',
                'properties' => [
                    'workflow_id' => ['type' => 'integer'],
                    'workflow_slug' => ['type' => 'string'],
                    'workflow_query' => ['type' => 'string', 'description' => 'Beispiel: DIBAG oeffnen'],
                    'run_id' => ['type' => 'integer'],
                    'include_debug_excerpt' => ['type' => 'boolean'],
                ],
            ]),
            $this->tool('create_workflow', 'Erstelle einen neuen Workflow.', [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'category' => ['type' => 'string'],
                    'subcategory' => ['type' => 'string'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'is_active' => ['type' => 'boolean'],
                ],
                'required' => ['name'],
            ]),
            $this->tool('update_workflow', 'Aktualisiere Workflow-Metadaten, Tags oder Settings.', [
                'type' => 'object',
                'properties' => [
                    'workflow_id' => ['type' => 'integer'],
                    'workflow_slug' => ['type' => 'string'],
                    'workflow_query' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'category' => ['type' => 'string'],
                    'subcategory' => ['type' => 'string'],
                    'is_active' => ['type' => 'boolean'],
                    'is_locked' => ['type' => 'boolean'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'settings' => ['type' => 'object'],
                ],
            ]),
            $this->tool('create_workflow_list', 'Erstelle eine neue Liste bzw. einen Workflow-Step in einem Workflow.', [
                'type' => 'object',
                'properties' => [
                    'workflow_id' => ['type' => 'integer'],
                    'workflow_slug' => ['type' => 'string'],
                    'workflow_query' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['preparation', 'data_processing', 'browser_control', 'interaction', 'decision', 'cleanup', 'wait', 'browser_task', 'data_task'],
                    ],
                    'description' => ['type' => 'string'],
                    'after_step_id' => ['type' => 'integer'],
                    'enabled' => ['type' => 'boolean'],
                ],
                'required' => ['name'],
            ]),
            $this->tool('list_task_catalog', 'Liste verfuegbare Task-Definitionen aus dem Workflow-Task-Katalog.', [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'kind' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 80],
                ],
            ]),
            $this->tool('list_embedded_workflow_candidates', 'Liste Workflows, die als eingebetteter Workflow verwendet werden koennen.', [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'parent_workflow_id' => ['type' => 'integer'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                ],
            ]),
            $this->tool('add_workflow_task', 'Fuege einer Liste einen Task aus dem Katalog oder einen eingebetteten Workflow hinzu.', [
                'type' => 'object',
                'properties' => [
                    'workflow_id' => ['type' => 'integer'],
                    'workflow_slug' => ['type' => 'string'],
                    'workflow_query' => ['type' => 'string'],
                    'step_id' => ['type' => 'integer'],
                    'step_action_key' => ['type' => 'string'],
                    'step_name' => ['type' => 'string'],
                    'task_key' => ['type' => 'string', 'description' => 'Task-Key aus list_task_catalog, z.B. browser.open_url.'],
                    'embedded_workflow_id' => ['type' => 'integer'],
                    'embedded_workflow_slug' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'selector' => ['type' => 'string'],
                    'input_selector' => ['type' => 'string'],
                    'value' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                    'browser_window' => ['type' => 'string'],
                    'timeout_seconds' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3600],
                    'extra' => ['type' => 'object', 'description' => 'Weitere Task-Felder wie search_input_selector, output_array_name usw.'],
                    'success_target' => ['type' => 'string', 'description' => 'Optional: end, fail oder step:<action_key>.'],
                    'error_target' => ['type' => 'string', 'description' => 'Optional: end, fail oder step:<action_key>.'],
                    'insert_position' => ['type' => 'integer', 'minimum' => 0],
                ],
            ]),
            $this->tool('update_workflow_task', 'Aktualisiere eine vorhandene Task-Karte anhand ihres Keys.', [
                'type' => 'object',
                'properties' => [
                    'workflow_id' => ['type' => 'integer'],
                    'workflow_slug' => ['type' => 'string'],
                    'workflow_query' => ['type' => 'string'],
                    'step_id' => ['type' => 'integer'],
                    'step_action_key' => ['type' => 'string'],
                    'task_card_key' => ['type' => 'string'],
                    'fields' => ['type' => 'object'],
                ],
                'required' => ['task_card_key', 'fields'],
            ]),
            $this->tool('apply_workflow_definition', 'Erstelle oder aktualisiere einen Workflow aus einer kompakten Definition. Optional koennen alle Listen ersetzt werden.', [
                'type' => 'object',
                'properties' => [
                    'workflow_id' => ['type' => 'integer'],
                    'workflow_slug' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'category' => ['type' => 'string'],
                    'subcategory' => ['type' => 'string'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'replace_steps' => ['type' => 'boolean'],
                    'steps' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'type' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'tasks' => ['type' => 'array', 'items' => ['type' => 'object']],
                            ],
                            'required' => ['name'],
                        ],
                    ],
                ],
                'required' => ['name'],
            ]),
            $this->tool('present_chat_options', 'Zeige dem Nutzer anklickbare Antwortoptionen.', [
                'type' => 'object',
                'properties' => [
                    'prompt' => ['type' => 'string'],
                    'options' => [
                        'type' => 'array',
                        'minItems' => 2,
                        'maxItems' => 6,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'label' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'prompt' => ['type' => 'string'],
                            ],
                            'required' => ['label', 'prompt'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['prompt', 'options'],
            ]),
        ];
    }

    public function execute(string $name, array $arguments, mixed $user = null): array
    {
        if (! $user) {
            return $this->error('AUTH_REQUIRED', 'Bitte anmelden, damit ich Workflows bearbeiten kann.');
        }

        return match ($name) {
            'list_workflows' => $this->listWorkflows($arguments),
            'get_workflow_context' => $this->getWorkflowContext($arguments),
            'analyze_last_workflow_run' => $this->analyzeLastWorkflowRun($arguments),
            'create_workflow' => $this->createWorkflow($arguments),
            'update_workflow' => $this->updateWorkflow($arguments),
            'create_workflow_list' => $this->createWorkflowList($arguments),
            'list_task_catalog' => $this->listTaskCatalog($arguments),
            'list_embedded_workflow_candidates' => $this->listEmbeddedWorkflowCandidates($arguments),
            'add_workflow_task' => $this->addWorkflowTask($arguments),
            'update_workflow_task' => $this->updateWorkflowTask($arguments),
            'apply_workflow_definition' => $this->applyWorkflowDefinition($arguments),
            'present_chat_options' => $this->presentChatOptions($arguments),
            default => $this->error('UNKNOWN_TOOL', 'Unbekanntes Tool: '.$name),
        };
    }

    public function conversationContext(mixed $user, array $pageContext = []): array
    {
        $recentWorkflows = Workflow::query()
            ->withCount(['steps', 'runs'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get()
            ->map(fn (Workflow $workflow): array => $this->workflowSummary($workflow))
            ->values()
            ->all();

        return [
            'authenticated' => (bool) $user,
            'user_id' => $user?->id,
            'page' => [
                'route_name' => $this->stringValue($pageContext['route_name'] ?? null),
                'path' => $this->stringValue($pageContext['path'] ?? null),
                'title' => $this->stringValue($pageContext['page_title'] ?? null),
                'workflow_id' => $this->positiveInteger($pageContext['workflow_id'] ?? null),
                'workflow_slug' => $this->stringValue($pageContext['workflow_slug'] ?? null),
            ],
            'workflow_counts' => [
                'workflows' => Workflow::query()->count(),
                'active' => Workflow::query()->where('is_active', true)->count(),
                'runs' => WorkflowRun::query()->count(),
            ],
            'recent_workflows' => $recentWorkflows,
        ];
    }

    protected function listWorkflows(array $arguments): array
    {
        $query = Str::lower(trim((string) ($arguments['query'] ?? '')));
        $category = Str::slug((string) ($arguments['category'] ?? ''), '_');
        $includeSteps = (bool) ($arguments['include_steps'] ?? false);
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 15)));

        $workflows = Workflow::query()
            ->with(['steps' => fn ($stepQuery) => $stepQuery->ordered()])
            ->withCount(['steps', 'runs'])
            ->when($query !== '', function ($builder) use ($query): void {
                $builder->where(function ($inner) use ($query): void {
                    $like = '%'.$query.'%';
                    $inner
                        ->whereRaw('LOWER(name) like ?', [$like])
                        ->orWhereRaw('LOWER(slug) like ?', [$like])
                        ->orWhereRaw('LOWER(description) like ?', [$like]);
                });
            })
            ->when($category !== '', fn ($builder) => $builder->where('category', $category))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return [
            'ok' => true,
            'workflows' => $workflows
                ->map(fn (Workflow $workflow): array => $this->workflowSummary($workflow, $includeSteps))
                ->values()
                ->all(),
        ];
    }

    protected function getWorkflowContext(array $arguments): array
    {
        $workflow = $this->resolveWorkflow($arguments);

        if (! $workflow) {
            return $this->error('WORKFLOW_NOT_FOUND', 'Workflow wurde nicht gefunden.');
        }

        $workflow->load(['steps' => fn ($query) => $query->ordered()]);
        $includeRuns = (bool) ($arguments['include_runs'] ?? true);

        return [
            'ok' => true,
            'workflow' => $this->workflowSummary($workflow, true),
            'steps' => $workflow->steps
                ->map(fn (WorkflowStep $step): array => $this->stepSummary($step, true))
                ->values()
                ->all(),
            'recent_runs' => $includeRuns
                ? $workflow->runs()->with('stepRuns.workflowStep')->limit(5)->get()->map(fn (WorkflowRun $run): array => $this->runSummary($run, false))->values()->all()
                : [],
        ];
    }

    protected function analyzeLastWorkflowRun(array $arguments): array
    {
        $runId = $this->positiveInteger($arguments['run_id'] ?? null);
        $includeDebug = (bool) ($arguments['include_debug_excerpt'] ?? true);

        $run = $runId
            ? WorkflowRun::query()->with(['workflow.steps', 'stepRuns.workflowStep'])->find($runId)
            : null;

        if (! $run) {
            $workflow = $this->resolveWorkflow($arguments, false);

            $run = $workflow
                ? $workflow->runs()->with(['workflow.steps', 'stepRuns.workflowStep'])->latest('id')->first()
                : WorkflowRun::query()->with(['workflow.steps', 'stepRuns.workflowStep'])->latest('id')->first();
        }

        if (! $run) {
            return $this->error('RUN_NOT_FOUND', 'Kein Workflow-Run gefunden.');
        }

        return [
            'ok' => true,
            'run' => $this->runSummary($run, $includeDebug),
            'diagnosis_hints' => $this->runDiagnosisHints($run),
        ];
    }

    protected function createWorkflow(array $arguments): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));

        if ($name === '') {
            return $this->error('VALIDATION', 'Der Workflow braucht einen Namen.');
        }

        $workflow = Workflow::query()->create([
            'name' => Str::limit($name, 160, ''),
            'slug' => $this->uniqueWorkflowSlug($name),
            'description' => Str::limit(trim((string) ($arguments['description'] ?? '')), 1000, ''),
            'category' => $this->slugValue($arguments['category'] ?? 'custom', 'custom'),
            'subcategory' => $this->optionalSlug($arguments['subcategory'] ?? null),
            'is_active' => array_key_exists('is_active', $arguments) ? (bool) $arguments['is_active'] : true,
            'trigger_type' => 'manual',
            'settings_json' => [
                'tags' => $this->normalizeTags($arguments['tags'] ?? []),
                'created_from' => 'ai_workflow_assistant',
            ],
        ]);

        return [
            'ok' => true,
            'message' => 'Workflow wurde erstellt.',
            'workflow' => $this->workflowSummary($workflow->fresh(), true),
        ];
    }

    protected function updateWorkflow(array $arguments): array
    {
        $workflow = $this->resolveWorkflow($arguments);

        if (! $workflow) {
            return $this->error('WORKFLOW_NOT_FOUND', 'Workflow wurde nicht gefunden.');
        }

        $attributes = [];

        foreach (['name', 'description'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $attributes[$key] = $key === 'name'
                    ? Str::limit(trim((string) $arguments[$key]), 160, '')
                    : Str::limit(trim((string) $arguments[$key]), 1000, '');
            }
        }

        if (array_key_exists('category', $arguments)) {
            $attributes['category'] = $this->slugValue($arguments['category'], 'custom');
        }

        if (array_key_exists('subcategory', $arguments)) {
            $attributes['subcategory'] = $this->optionalSlug($arguments['subcategory']);
        }

        foreach (['is_active', 'is_locked'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $attributes[$key] = (bool) $arguments[$key];
            }
        }

        $settings = is_array($workflow->settings_json) ? $workflow->settings_json : [];

        if (is_array($arguments['settings'] ?? null)) {
            $settings = array_replace_recursive($settings, $arguments['settings']);
        }

        if (array_key_exists('tags', $arguments)) {
            $settings['tags'] = $this->normalizeTags($arguments['tags']);
        }

        $attributes['settings_json'] = $settings;
        $workflow->forceFill($attributes)->save();

        return [
            'ok' => true,
            'message' => 'Workflow wurde aktualisiert.',
            'workflow' => $this->workflowSummary($workflow->fresh(['steps']), true),
        ];
    }

    protected function createWorkflowList(array $arguments): array
    {
        $workflow = $this->resolveEditableWorkflow($arguments);

        if (! $workflow) {
            return $this->error('WORKFLOW_NOT_EDITABLE', 'Workflow wurde nicht gefunden oder ist gesperrt.');
        }

        $name = trim((string) ($arguments['name'] ?? ''));

        if ($name === '') {
            return $this->error('VALIDATION', 'Die Liste braucht einen Namen.');
        }

        $type = trim((string) ($arguments['type'] ?? WorkflowStep::TYPE_PREPARATION));
        $allowedTypes = [
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

        if (! in_array($type, $allowedTypes, true)) {
            $type = WorkflowStep::TYPE_PREPARATION;
        }

        $position = ((int) $workflow->steps()->max('position')) + 10;
        $afterStepId = $this->positiveInteger($arguments['after_step_id'] ?? null);

        if ($afterStepId) {
            $afterStep = $workflow->steps()->find($afterStepId);
            $position = $afterStep ? ((int) $afterStep->position) + 5 : $position;
        }

        $step = $workflow->steps()->create([
            'name' => Str::limit($name, 160, ''),
            'type' => $type,
            'action_key' => $this->uniqueStepActionKey($workflow, $name),
            'position' => $position,
            'is_enabled' => array_key_exists('enabled', $arguments) ? (bool) $arguments['enabled'] : true,
            'config_json' => [
                'description' => Str::limit(trim((string) ($arguments['description'] ?? '')), 1000, ''),
                'tasks' => [],
                'routes' => [],
            ],
        ]);

        $this->normalizeStepPositions($workflow);

        return [
            'ok' => true,
            'message' => 'Workflow-Liste wurde erstellt.',
            'workflow' => $this->workflowSummary($workflow->fresh(['steps']), true),
            'step' => $this->stepSummary($step->fresh(), true),
        ];
    }

    protected function listTaskCatalog(array $arguments): array
    {
        $query = Str::lower(trim((string) ($arguments['query'] ?? '')));
        $kind = trim((string) ($arguments['kind'] ?? ''));
        $limit = max(1, min(80, (int) ($arguments['limit'] ?? 30)));

        $tasks = collect($this->taskCatalog->options())
            ->filter(function (array $task) use ($query, $kind): bool {
                if ($kind !== '' && (string) ($task['kind'] ?? '') !== $kind) {
                    return false;
                }

                if ($query === '') {
                    return true;
                }

                $haystack = Str::lower(implode(' ', [
                    $task['task_key'] ?? '',
                    $task['label'] ?? '',
                    $task['description'] ?? '',
                    $task['kind'] ?? '',
                    $task['runner'] ?? '',
                ]));

                return str_contains($haystack, $query);
            })
            ->take($limit)
            ->values()
            ->all();

        return [
            'ok' => true,
            'tasks' => $tasks,
        ];
    }

    protected function listEmbeddedWorkflowCandidates(array $arguments): array
    {
        $query = Str::lower(trim((string) ($arguments['query'] ?? '')));
        $parentWorkflowId = $this->positiveInteger($arguments['parent_workflow_id'] ?? null);
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 20)));

        $workflows = Workflow::query()
            ->withCount('steps')
            ->when($query !== '', function ($builder) use ($query): void {
                $like = '%'.$query.'%';
                $builder->where(function ($inner) use ($like): void {
                    $inner
                        ->whereRaw('LOWER(name) like ?', [$like])
                        ->orWhereRaw('LOWER(slug) like ?', [$like])
                        ->orWhereRaw('LOWER(description) like ?', [$like]);
                });
            })
            ->when($parentWorkflowId, fn ($builder) => $builder->whereKeyNot($parentWorkflowId))
            ->orderBy('category')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return [
            'ok' => true,
            'workflows' => $workflows->map(fn (Workflow $workflow): array => $this->workflowSummary($workflow))->values()->all(),
        ];
    }

    protected function addWorkflowTask(array $arguments): array
    {
        $workflow = $this->resolveEditableWorkflow($arguments);

        if (! $workflow) {
            return $this->error('WORKFLOW_NOT_EDITABLE', 'Workflow wurde nicht gefunden oder ist gesperrt.');
        }

        $step = $this->resolveWorkflowStep($workflow, $arguments);

        if (! $step) {
            return $this->error('STEP_NOT_FOUND', 'Liste wurde nicht gefunden.');
        }

        $task = $this->taskCardFromArguments($workflow, $step, $arguments);

        if (! ($task['ok'] ?? false)) {
            return $task;
        }

        $card = $task['card'];
        $insertPosition = array_key_exists('insert_position', $arguments)
            ? max(0, (int) $arguments['insert_position'])
            : null;

        if ($insertPosition !== null) {
            $this->taskOrdering->insertTask($step, $card, $insertPosition);
        } else {
            $this->taskOrdering->appendTask($step, $card);
        }

        return [
            'ok' => true,
            'message' => 'Task wurde hinzugefuegt.',
            'workflow' => $this->workflowSummary($workflow->fresh(['steps']), true),
            'step' => $this->stepSummary($step->fresh(), true),
            'task' => $card,
        ];
    }

    protected function updateWorkflowTask(array $arguments): array
    {
        $workflow = $this->resolveEditableWorkflow($arguments);

        if (! $workflow) {
            return $this->error('WORKFLOW_NOT_EDITABLE', 'Workflow wurde nicht gefunden oder ist gesperrt.');
        }

        $step = $this->resolveWorkflowStep($workflow, $arguments);

        if (! $step) {
            return $this->error('STEP_NOT_FOUND', 'Liste wurde nicht gefunden.');
        }

        $taskKey = trim((string) ($arguments['task_card_key'] ?? ''));
        $fields = is_array($arguments['fields'] ?? null) ? $arguments['fields'] : [];

        if ($taskKey === '' || $fields === []) {
            return $this->error('VALIDATION', 'Task-Key und Felder werden benoetigt.');
        }

        $config = is_array($step->config_json) ? $step->config_json : [];
        $tasks = collect(is_array($config['tasks'] ?? null) ? $config['tasks'] : [])->values();
        $updated = null;

        $tasks = $tasks->map(function (array $task) use ($taskKey, $fields, &$updated): array {
            if ((string) ($task['key'] ?? '') !== $taskKey) {
                return $task;
            }

            $allowed = [
                'title', 'description', 'kind', 'runner', 'task_key', 'selector', 'element_selector',
                'input_selector', 'input', 'value', 'url', 'browser_window', 'browser_window_name',
                'timeout_seconds', 'mailbox_source', 'script_person_source', 'success_payload',
                'failure_payload', 'next', 'on_error', 'status_routes',
            ];

            foreach ($fields as $key => $value) {
                if (in_array($key, $allowed, true) || preg_match('/^[A-Za-z0-9_.-]+$/', (string) $key)) {
                    $task[$key] = $value;
                }
            }

            $updated = $task;

            return $task;
        });

        if ($updated === null) {
            return $this->error('TASK_NOT_FOUND', 'Task-Karte wurde nicht gefunden.');
        }

        $config['tasks'] = $this->normalizeTaskOrder($tasks->all());
        $step->forceFill(['config_json' => $config])->save();

        return [
            'ok' => true,
            'message' => 'Task wurde aktualisiert.',
            'step' => $this->stepSummary($step->fresh(), true),
            'task' => $updated,
        ];
    }

    protected function applyWorkflowDefinition(array $arguments): array
    {
        $replaceSteps = (bool) ($arguments['replace_steps'] ?? false);
        $workflow = $this->resolveWorkflow($arguments, false);

        return DB::transaction(function () use ($arguments, $replaceSteps, $workflow): array {
            if (! $workflow) {
                $created = $this->createWorkflow($arguments);

                if (! ($created['ok'] ?? false)) {
                    return $created;
                }

                $workflow = Workflow::query()->find((int) data_get($created, 'workflow.id'));
            } else {
                $updated = $this->updateWorkflow([
                    'workflow_id' => $workflow->id,
                    ...$arguments,
                ]);

                if (! ($updated['ok'] ?? false)) {
                    return $updated;
                }

                $workflow = Workflow::query()->find((int) $workflow->id);
            }

            if (! $workflow) {
                return $this->error('WORKFLOW_NOT_FOUND', 'Workflow konnte nach dem Speichern nicht geladen werden.');
            }

            if ($replaceSteps) {
                $workflow->steps()->delete();
            }

            foreach (collect($arguments['steps'] ?? [])->filter(fn ($step): bool => is_array($step))->values() as $index => $stepDefinition) {
                $step = $workflow->steps()->create([
                    'name' => Str::limit(trim((string) ($stepDefinition['name'] ?? 'Liste '.($index + 1))), 160, ''),
                    'type' => trim((string) ($stepDefinition['type'] ?? WorkflowStep::TYPE_PREPARATION)) ?: WorkflowStep::TYPE_PREPARATION,
                    'action_key' => $this->uniqueStepActionKey($workflow, (string) ($stepDefinition['name'] ?? 'liste')),
                    'position' => (($index + 1) * 10),
                    'is_enabled' => (bool) ($stepDefinition['enabled'] ?? true),
                    'config_json' => [
                        'description' => Str::limit(trim((string) ($stepDefinition['description'] ?? '')), 1000, ''),
                        'tasks' => [],
                        'routes' => [],
                    ],
                ]);

                foreach (collect($stepDefinition['tasks'] ?? [])->filter(fn ($task): bool => is_array($task))->values() as $taskDefinition) {
                    $task = $this->taskCardFromArguments($workflow, $step, $taskDefinition);

                    if ($task['ok'] ?? false) {
                        $this->taskOrdering->appendTask($step, $task['card']);
                    }
                }
            }

            return [
                'ok' => true,
                'message' => 'Workflow-Definition wurde angewendet.',
                'workflow' => $this->workflowSummary($workflow->fresh(['steps']), true),
            ];
        });
    }

    protected function presentChatOptions(array $arguments): array
    {
        $options = collect($arguments['options'] ?? [])
            ->filter(fn (mixed $option): bool => is_array($option) && filled($option['label'] ?? null) && filled($option['prompt'] ?? null))
            ->take(6)
            ->map(fn (array $option): array => [
                'label' => Str::limit(trim((string) $option['label']), 80, ''),
                'description' => Str::limit(trim((string) ($option['description'] ?? '')), 160, ''),
                'prompt' => trim((string) $option['prompt']),
            ])
            ->values()
            ->all();

        return [
            'ok' => count($options) >= 2,
            'message' => trim((string) ($arguments['prompt'] ?? 'Bitte waehle eine Option.')),
            'chat_options' => $options,
        ];
    }

    protected function taskCardFromArguments(Workflow $workflow, WorkflowStep $step, array $arguments): array
    {
        $embeddedWorkflow = $this->resolveEmbeddedWorkflow($arguments);
        $title = trim((string) ($arguments['title'] ?? ''));

        if ($embeddedWorkflow) {
            $card = [
                'key' => $this->uniqueTaskKey($step, $title ?: $embeddedWorkflow->name),
                'task_key' => 'workflow.include.'.$embeddedWorkflow->id,
                'title' => $title ?: $embeddedWorkflow->name,
                'description' => trim((string) ($arguments['description'] ?? 'Eingebetteter Workflow: '.$embeddedWorkflow->name)),
                'kind' => 'workflow',
                'runner' => 'workflow',
                'workflow_id' => (int) $embeddedWorkflow->id,
                'workflow_slug' => (string) $embeddedWorkflow->slug,
                'status' => 'configured',
                'timeout_seconds' => max(0, (int) ($arguments['timeout_seconds'] ?? 0)),
            ];

            return ['ok' => true, 'card' => $this->applyTaskRoutes($workflow, $step, $card, $arguments)];
        }

        $taskKey = trim((string) ($arguments['task_key'] ?? $arguments['catalog_key'] ?? ''));

        if ($taskKey === '') {
            return $this->error('VALIDATION', 'Ein task_key aus dem Task-Katalog oder ein embedded_workflow_id wird benoetigt.');
        }

        $definition = $this->taskCatalog->task($taskKey);

        if (! $definition) {
            return $this->error('TASK_NOT_FOUND', 'Task-Key ist im Katalog nicht verfuegbar: '.$taskKey);
        }

        $extra = is_array($arguments['extra'] ?? null) ? $arguments['extra'] : [];
        $overrides = array_filter([
            'key' => $this->uniqueTaskKey($step, $title ?: (string) ($definition['label'] ?? $taskKey)),
            'title' => $title ?: (string) ($definition['label'] ?? $taskKey),
            'description' => trim((string) ($arguments['description'] ?? ($definition['description'] ?? ''))),
            'selector' => trim((string) ($arguments['selector'] ?? '')),
            'element_selector' => trim((string) ($arguments['selector'] ?? $arguments['element_selector'] ?? '')),
            'input_selector' => trim((string) ($arguments['input_selector'] ?? '')),
            'input' => trim((string) ($arguments['value'] ?? $arguments['input'] ?? '')),
            'value' => trim((string) ($arguments['value'] ?? $arguments['input'] ?? '')),
            'url' => trim((string) ($arguments['url'] ?? '')),
            'browser_window' => $this->normalizeBrowserWindowName((string) ($arguments['browser_window'] ?? 'main')),
            'browser_window_name' => $this->normalizeBrowserWindowName((string) ($arguments['browser_window'] ?? 'main')),
            'timeout_seconds' => array_key_exists('timeout_seconds', $arguments) ? max(0, (int) $arguments['timeout_seconds']) : null,
            'status' => 'configured',
        ], static fn ($value): bool => $value !== null && $value !== '');

        $card = $this->taskCatalog->cardFromDefinition($taskKey, [...$overrides, ...$extra]);

        if (array_key_exists('success_payload', $arguments)) {
            $card['success_payload'] = $arguments['success_payload'];
        }

        if (array_key_exists('failure_payload', $arguments)) {
            $card['failure_payload'] = $arguments['failure_payload'];
        }

        return ['ok' => true, 'card' => $this->applyTaskRoutes($workflow, $step, $card, $arguments)];
    }

    protected function applyTaskRoutes(Workflow $workflow, WorkflowStep $step, array $card, array $arguments): array
    {
        $successRoute = $this->routeTargetFromValue($workflow, (string) ($arguments['success_target'] ?? ''));
        $errorRoute = $this->routeTargetFromValue($workflow, (string) ($arguments['error_target'] ?? ''));

        if ($successRoute) {
            $card['next'] = $successRoute;
        }

        if ($errorRoute) {
            $card['on_error'] = $errorRoute;
        }

        return $card;
    }

    protected function routeTargetFromValue(Workflow $workflow, string $value): ?array
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if ($value === 'end') {
            return ['type' => 'end', 'step' => 'end', 'label' => 'Workflow abschliessen'];
        }

        if ($value === 'fail') {
            return ['type' => 'fail', 'step' => 'fail', 'label' => 'Fehlerroute'];
        }

        if (str_starts_with($value, 'step:')) {
            $actionKey = trim(substr($value, 5));
            $target = $workflow->steps()->where('action_key', $actionKey)->first();

            if ($target) {
                return [
                    'type' => 'step',
                    'action_key' => $target->action_key,
                    'step' => $target->action_key,
                    'label' => $target->name,
                ];
            }
        }

        return null;
    }

    protected function resolveWorkflow(array $arguments, bool $required = true): ?Workflow
    {
        $id = $this->positiveInteger($arguments['workflow_id'] ?? $arguments['id'] ?? null);

        if ($id) {
            return Workflow::query()->find($id);
        }

        $slug = trim((string) ($arguments['workflow_slug'] ?? $arguments['slug'] ?? ''));

        if ($slug !== '') {
            $workflow = Workflow::query()->where('slug', Str::slug($slug))->first()
                ?: Workflow::query()->where('slug', $slug)->first();

            if ($workflow) {
                return $workflow;
            }
        }

        $query = Str::lower(trim((string) ($arguments['workflow_query'] ?? $arguments['query'] ?? $arguments['name'] ?? '')));

        if ($query !== '') {
            $like = '%'.$query.'%';

            return Workflow::query()
                ->whereRaw('LOWER(name) like ?', [$like])
                ->orWhereRaw('LOWER(slug) like ?', [$like])
                ->orWhereRaw('LOWER(description) like ?', [$like])
                ->orderByDesc('updated_at')
                ->first();
        }

        return null;
    }

    protected function resolveEditableWorkflow(array $arguments): ?Workflow
    {
        $workflow = $this->resolveWorkflow($arguments);

        if (! $workflow || $workflow->is_edit_locked) {
            return null;
        }

        return $workflow;
    }

    protected function resolveWorkflowStep(Workflow $workflow, array $arguments): ?WorkflowStep
    {
        $stepId = $this->positiveInteger($arguments['step_id'] ?? null);

        if ($stepId) {
            return $workflow->steps()->whereKey($stepId)->first();
        }

        $actionKey = trim((string) ($arguments['step_action_key'] ?? $arguments['action_key'] ?? ''));

        if ($actionKey !== '') {
            return $workflow->steps()->where('action_key', $actionKey)->first();
        }

        $name = Str::lower(trim((string) ($arguments['step_name'] ?? '')));

        if ($name !== '') {
            return $workflow->steps()
                ->whereRaw('LOWER(name) like ?', ['%'.$name.'%'])
                ->ordered()
                ->first();
        }

        return $workflow->steps()->ordered()->first();
    }

    protected function resolveEmbeddedWorkflow(array $arguments): ?Workflow
    {
        $id = $this->positiveInteger($arguments['embedded_workflow_id'] ?? null);

        if ($id) {
            return Workflow::query()->find($id);
        }

        $slug = trim((string) ($arguments['embedded_workflow_slug'] ?? ''));

        if ($slug !== '') {
            return Workflow::query()->where('slug', Str::slug($slug))->first()
                ?: Workflow::query()->where('slug', $slug)->first();
        }

        return null;
    }

    protected function workflowSummary(Workflow $workflow, bool $includeSteps = false): array
    {
        $workflow->loadMissing('steps');
        $settings = is_array($workflow->settings_json) ? $workflow->settings_json : [];

        return [
            'id' => (int) $workflow->id,
            'name' => $workflow->name,
            'slug' => $workflow->slug,
            'description' => $workflow->description,
            'category' => $workflow->category,
            'subcategory' => $workflow->subcategory,
            'tags' => $this->normalizeTags($settings['tags'] ?? []),
            'is_active' => (bool) $workflow->is_active,
            'is_locked' => (bool) $workflow->is_locked,
            'is_edit_locked' => (bool) $workflow->is_edit_locked,
            'steps_count' => (int) ($workflow->steps_count ?? $workflow->steps->count()),
            'task_cards_count' => $workflow->steps->sum(fn (WorkflowStep $step): int => count($step->task_cards)),
            'runs_count' => (int) ($workflow->runs_count ?? $workflow->runs()->count()),
            'updated_at' => optional($workflow->updated_at)->toDateTimeString(),
            'url' => route('network.workflows.manage', ['workflow' => $workflow->id]),
            'steps' => $includeSteps
                ? $workflow->steps->map(fn (WorkflowStep $step): array => $this->stepSummary($step, false))->values()->all()
                : [],
        ];
    }

    protected function stepSummary(WorkflowStep $step, bool $includeTasks = false): array
    {
        $config = is_array($step->config_json) ? $step->config_json : [];
        $tasks = $step->task_cards;

        return [
            'id' => (int) $step->id,
            'name' => $step->name,
            'type' => $step->type,
            'action_key' => $step->action_key,
            'position' => (int) $step->position,
            'is_enabled' => (bool) $step->is_enabled,
            'description' => trim((string) ($config['description'] ?? $config['automation_summary'] ?? '')),
            'task_cards_count' => count($tasks),
            'tasks' => $includeTasks ? $tasks : collect($tasks)->map(fn (array $task): array => [
                'key' => $task['key'] ?? '',
                'task_key' => $task['task_key'] ?? '',
                'title' => $task['title'] ?? '',
                'kind' => $task['kind'] ?? '',
                'runner' => $task['runner'] ?? '',
            ])->values()->all(),
        ];
    }

    protected function runSummary(WorkflowRun $run, bool $includeDebug): array
    {
        $run->loadMissing(['workflow', 'stepRuns.workflowStep']);

        return [
            'id' => (int) $run->id,
            'workflow_id' => (int) $run->workflow_id,
            'workflow_name' => $run->workflow?->name,
            'workflow_slug' => $run->workflow?->slug,
            'status' => $run->status,
            'queued_at' => optional($run->queued_at)->toDateTimeString(),
            'started_at' => optional($run->started_at)->toDateTimeString(),
            'finished_at' => optional($run->finished_at)->toDateTimeString(),
            'duration_ms' => $run->duration_ms,
            'error_message' => $run->error_message,
            'context_excerpt' => $this->jsonExcerpt($run->context_json, 1600),
            'result_excerpt' => $this->jsonExcerpt($run->result_json, $includeDebug ? 5000 : 1800),
            'step_runs' => $run->stepRuns
                ->map(fn ($stepRun): array => [
                    'id' => (int) $stepRun->id,
                    'step_id' => (int) $stepRun->workflow_step_id,
                    'step_name' => $stepRun->workflowStep?->name,
                    'step_action_key' => $stepRun->workflowStep?->action_key,
                    'status' => $stepRun->status,
                    'error_message' => $stepRun->error_message,
                    'duration_ms' => $stepRun->duration_ms,
                    'result_excerpt' => $this->jsonExcerpt($stepRun->result_json, $includeDebug ? 3000 : 1000),
                ])
                ->values()
                ->all(),
        ];
    }

    protected function runDiagnosisHints(WorkflowRun $run): array
    {
        $hints = [];

        if ($run->status === 'failed') {
            $hints[] = 'Run ist fehlgeschlagen: '.$this->stringValue($run->error_message, 240);
        }

        foreach ($run->stepRuns as $stepRun) {
            if ($stepRun->status === 'failed') {
                $hints[] = 'Fehler in Liste '.$stepRun->workflowStep?->name.': '.$this->stringValue($stepRun->error_message, 240);
            }
        }

        if ($hints === []) {
            $hints[] = 'Kein harter Fehler erkannt. Pruefe Statusmeldungen und Task-Resultate fuer fachliche Filterprobleme.';
        }

        return $hints;
    }

    protected function uniqueWorkflowSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workflow';
        $slug = $base;
        $index = 2;

        while (Workflow::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$index;
            $index++;
        }

        return $slug;
    }

    protected function uniqueStepActionKey(Workflow $workflow, string $name): string
    {
        $base = Str::slug($name) ?: 'liste';
        $key = $base;
        $index = 2;

        while ($workflow->steps()->where('action_key', $key)->exists()) {
            $key = $base.'-'.$index;
            $index++;
        }

        return $key;
    }

    protected function uniqueTaskKey(WorkflowStep $step, string $title): string
    {
        $base = Str::slug($title) ?: 'task';
        $existing = collect($step->task_cards)->pluck('key')->filter()->all();
        $key = $base;
        $index = 2;

        while (in_array($key, $existing, true)) {
            $key = $base.'-'.$index;
            $index++;
        }

        return $key;
    }

    protected function normalizeStepPositions(Workflow $workflow): void
    {
        $workflow->steps()->ordered()->get()->values()->each(function (WorkflowStep $step, int $index): void {
            $step->forceFill(['position' => ($index + 1) * 10])->save();
        });
    }

    protected function normalizeTaskOrder(array $tasks): array
    {
        return collect($tasks)->values()->map(function (array $task, int $index): array {
            $task['order_id'] = ($index + 1) * 10;
            $task['position'] = ($index + 1) * 10;

            return $task;
        })->all();
    }

    protected function normalizeBrowserWindowName(string $value): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '', strtolower(trim($value))) ?? '';

        return $name !== '' ? Str::limit($name, 80, '') : 'main';
    }

    protected function normalizeTags(mixed $tags): array
    {
        return collect(is_array($tags) ? $tags : explode(',', (string) $tags))
            ->map(fn (mixed $tag): string => Str::slug((string) $tag, '_'))
            ->filter()
            ->unique()
            ->take(25)
            ->values()
            ->all();
    }

    protected function slugValue(mixed $value, string $fallback): string
    {
        return Str::slug((string) $value, '_') ?: $fallback;
    }

    protected function optionalSlug(mixed $value): ?string
    {
        $slug = Str::slug((string) $value, '_');

        return $slug !== '' ? $slug : null;
    }

    protected function positiveInteger(mixed $value): ?int
    {
        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    protected function stringValue(mixed $value, int $limit = 255): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? Str::limit($text, $limit, '') : null;
    }

    protected function jsonExcerpt(mixed $value, int $limit): string
    {
        if ($value === null || $value === []) {
            return '';
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return Str::limit((string) $json, $limit, '');
    }

    protected function tool(string $name, string $description, array $parameters): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    ...$parameters,
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    protected function error(string $code, string $message, array $extra = []): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
            ...$extra,
        ];
    }
}
