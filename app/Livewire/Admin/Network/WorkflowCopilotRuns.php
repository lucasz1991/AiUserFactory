<?php

namespace App\Livewire\Admin\Network;

use App\Models\WorkflowCopilotSession;
use App\Services\Workflows\WorkflowCopilotLogExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class WorkflowCopilotRuns extends Component
{
    use WithPagination;

    public ?int $workflowId = null;

    public ?int $selectedSessionId = null;

    public string $search = '';

    public string $status = 'all';

    public string $activeTab = 'overview';

    /** @var array<int, string> */
    protected array $sensitiveValues = [];

    public function mount(?int $workflowId = null): void
    {
        $this->workflowId = $workflowId;
        $this->selectedSessionId = $this->sessionQuery()->latest('id')->value('id');
    }

    public function updatedSearch(): void
    {
        $this->resetPage('copilotRunsPage');
    }

    public function updatedStatus(): void
    {
        $this->resetPage('copilotRunsPage');
    }

    public function selectSession(int $sessionId): void
    {
        $session = $this->sessionQuery()->find($sessionId);

        if (! $session) {
            return;
        }

        $this->selectedSessionId = (int) $session->getKey();
        $this->activeTab = 'overview';
    }

    public function setActiveTab(string $tab): void
    {
        if (in_array($tab, ['overview', 'logs', 'runs', 'data'], true)) {
            $this->activeTab = $tab;
        }
    }

    public function downloadSelectedSessionLog(WorkflowCopilotLogExportService $exports): mixed
    {
        $session = $this->selectedSession();

        if (! $session) {
            $this->addError('session', 'Die ausgewaehlte Copilot-Sitzung wurde nicht gefunden.');

            return null;
        }

        try {
            $export = $exports->make($session);

            return response()->download($export['path'], $export['filename'])->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            $this->addError('session', 'Das Optimierungslog konnte nicht erzeugt werden: '.$exception->getMessage());

            return null;
        }
    }

    public function render()
    {
        $sessions = $this->filteredSessionQuery()
            ->with('workflow:id,name,slug')
            ->withCount(['events', 'runs', 'revisions', 'taskAttempts', 'checkpoints'])
            ->latest('id')
            ->paginate(10, ['*'], 'copilotRunsPage');

        $selectedSession = $this->selectedSession();
        $events = collect();
        $runs = collect();
        $revisions = collect();
        $taskAttempts = collect();
        $checkpoints = collect();
        $selectedData = [];

        if ($selectedSession) {
            $selectedSession->loadMissing('workflow:id,name,slug');
            $selectedSession->loadCount(['events', 'runs', 'revisions', 'taskAttempts', 'checkpoints']);
            $this->sensitiveValues = $this->collectSensitiveValues($selectedSession->workflow_inputs_json ?? []);
            $events = $selectedSession->events()
                ->reorder()
                ->latest('sequence')
                ->limit(150)
                ->get()
                ->sortBy('sequence')
                ->map(fn ($event): array => [
                    'sequence' => (int) $event->sequence,
                    'event_type' => (string) $event->event_type,
                    'phase' => (string) $event->phase,
                    'level' => (string) $event->level,
                    'message' => $this->sanitizeText((string) $event->message),
                    'payload' => $this->sanitize($event->payload_json ?? []),
                    'occurred_at' => $event->occurred_at,
                ])
                ->values();
            $runs = $selectedSession->runs()
                ->reorder()
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn ($run): array => [
                    'id' => (int) $run->id,
                    'revision' => $run->workflow_revision,
                    'status' => (string) $run->status,
                    'duration_ms' => $run->duration_ms,
                    'requested_by' => (string) $run->requested_by,
                    'started_at' => $run->started_at,
                    'finished_at' => $run->finished_at,
                    'error_message' => $this->sanitizeText((string) $run->error_message),
                ]);
            $revisions = $selectedSession->revisions()
                ->reorder()
                ->latest('revision_number')
                ->limit(50)
                ->get()
                ->map(fn ($revision): array => [
                    'revision_number' => (int) $revision->revision_number,
                    'actor' => (string) $revision->actor,
                    'reason' => $this->sanitizeText((string) $revision->reason),
                    'is_verified' => (bool) $revision->is_verified,
                    'created_at' => $revision->created_at,
                    'diff' => $this->sanitize($revision->diff_json ?? []),
                ]);
            $taskAttempts = $selectedSession->taskAttempts()
                ->reorder()
                ->latest('attempt_number')
                ->limit(100)
                ->get()
                ->map(fn ($attempt): array => [
                    'id' => (int) $attempt->id,
                    'attempt_number' => (int) $attempt->attempt_number,
                    'workflow_run_id' => $attempt->workflow_run_id ? (int) $attempt->workflow_run_id : null,
                    'workflow_step_id' => $attempt->workflow_step_id ? (int) $attempt->workflow_step_id : null,
                    'kind' => (string) $attempt->kind,
                    'status' => (string) $attempt->status,
                    'task_key' => (string) $attempt->task_key,
                    'task_title' => (string) $attempt->task_title,
                    'duration_ms' => $attempt->duration_ms,
                    'input' => $this->sanitize($attempt->input_json ?? []),
                    'result' => $this->sanitize($attempt->result_json ?? []),
                    'error_message' => $this->sanitizeText((string) $attempt->error_message),
                    'side_effects' => $this->sanitize($attempt->side_effects_json ?? []),
                    'artifacts' => $this->sanitize($attempt->artifacts_json ?? []),
                    'started_at' => $attempt->started_at?->toIso8601String(),
                    'finished_at' => $attempt->finished_at?->toIso8601String(),
                ]);
            $checkpoints = $selectedSession->checkpoints()
                ->reorder()
                ->latest('sequence')
                ->limit(100)
                ->get()
                ->map(fn ($checkpoint): array => [
                    'id' => (int) $checkpoint->id,
                    'sequence' => (int) $checkpoint->sequence,
                    'workflow_run_id' => $checkpoint->workflow_run_id ? (int) $checkpoint->workflow_run_id : null,
                    'workflow_step_id' => $checkpoint->workflow_step_id ? (int) $checkpoint->workflow_step_id : null,
                    'workflow_task_attempt_id' => $checkpoint->workflow_task_attempt_id ? (int) $checkpoint->workflow_task_attempt_id : null,
                    'phase' => (string) $checkpoint->phase,
                    'task_key' => (string) $checkpoint->task_key,
                    'cursor' => $this->sanitize($checkpoint->cursor_json ?? []),
                    'browser_state' => $this->sanitize($checkpoint->browser_state_json ?? []),
                    'dom_snapshot' => $this->sanitize($checkpoint->dom_snapshot_json ?? []),
                    'state_signature' => (string) $checkpoint->state_signature,
                    'side_effect_ledger' => $this->sanitize($checkpoint->side_effect_ledger_json ?? []),
                    'is_reproducible' => (bool) $checkpoint->is_reproducible,
                ]);
            $selectedData = $this->sessionData($selectedSession);
            $selectedData['runs'] = $runs->values()->all();
            $selectedData['revisions'] = $revisions->values()->all();
            $selectedData['task_attempts'] = $taskAttempts->values()->all();
            $selectedData['checkpoints'] = $checkpoints->values()->all();
        }

        return view('livewire.admin.network.workflow-copilot-runs', [
            'sessions' => $sessions,
            'selectedSession' => $selectedSession,
            'selectedEvents' => $events,
            'selectedRuns' => $runs,
            'selectedRevisions' => $revisions,
            'selectedData' => $selectedData,
        ]);
    }

    protected function filteredSessionQuery(): Builder
    {
        $search = trim($this->search);

        return $this->sessionQuery()
            ->when(
                $this->status !== 'all',
                fn (Builder $query) => $query->where('status', $this->status),
            )
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('session_uuid', 'like', '%'.$search.'%')
                        ->orWhere('goal', 'like', '%'.$search.'%')
                        ->orWhereHas('workflow', fn (Builder $workflowQuery) => $workflowQuery
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('slug', 'like', '%'.$search.'%'));

                    if (ctype_digit($search)) {
                        $query->orWhereKey((int) $search);
                    }
                });
            });
    }

    protected function sessionQuery(): Builder
    {
        return WorkflowCopilotSession::query()
            ->when(
                $this->workflowId !== null,
                fn (Builder $query) => $query->where('workflow_id', $this->workflowId),
            );
    }

    protected function selectedSession(): ?WorkflowCopilotSession
    {
        if (! $this->selectedSessionId) {
            return null;
        }

        return $this->sessionQuery()->find($this->selectedSessionId);
    }

    /** @return array<string, mixed> */
    protected function sessionData(WorkflowCopilotSession $session): array
    {
        return [
            'session' => [
                'id' => (int) $session->id,
                'uuid' => (string) $session->session_uuid,
                'status' => (string) $session->status,
                'phase' => (string) $session->phase,
                'execution_target' => (string) $session->execution_target,
                'goal' => $this->sanitizeText((string) $session->goal),
                'success_criteria' => $this->sanitize($session->success_criteria_json ?? []),
                'workflow_input_keys' => array_values(array_map('strval', array_keys($session->workflow_inputs_json ?? []))),
                'budget' => $this->sanitize($session->budget_json ?? []),
                'usage' => $this->sanitize($session->usage_json ?? []),
                'state' => $this->sanitize($session->state_json ?? []),
                'current_revision' => (int) $session->current_revision,
                'repair_round' => (int) $session->repair_round,
                'started_at' => $session->started_at?->toIso8601String(),
                'finished_at' => $session->finished_at?->toIso8601String(),
                'last_activity_at' => $session->last_activity_at?->toIso8601String(),
            ],
            'workflow' => [
                'id' => (int) $session->workflow_id,
                'name' => (string) ($session->workflow?->name ?? ''),
                'slug' => (string) ($session->workflow?->slug ?? ''),
            ],
            'counts' => [
                'events' => (int) $session->events_count,
                'runs' => (int) $session->runs_count,
                'revisions' => (int) $session->revisions_count,
                'task_attempts' => (int) $session->task_attempts_count,
                'checkpoints' => (int) $session->checkpoints_count,
            ],
        ];
    }

    protected function sanitize(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return is_string($payload) ? $this->sanitizeText($payload) : $payload;
        }

        $sanitized = [];

        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[redacted]';

                continue;
            }

            $sanitized[$key] = $this->sanitize($value);
        }

        return $sanitized;
    }

    protected function sanitizeText(string $value): string
    {
        if ($this->sensitiveValues !== []) {
            $value = str_replace($this->sensitiveValues, '[redacted]', $value);
        }

        return preg_replace([
            '#\b(?:wss?|cdp)://[^\s"\']+#i',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i',
            '/\b(password|passwd|pwd|secret|token|cookie|authorization|signature|credential|session(?:_?id)?|api[_-]?key)\s*[:=]\s*[^\s,;]+/i',
            '/\beyJ[A-Za-z0-9_-]{2,}\.[A-Za-z0-9_-]{3,}\.[A-Za-z0-9_-]{3,}\b/',
        ], [
            '[websocket redacted]',
            'Bearer [redacted]',
            '$1=[redacted]',
            '[token redacted]',
        ], $value) ?? $value;
    }

    /** @return array<int, string> */
    protected function collectSensitiveValues(mixed $payload): array
    {
        if (is_array($payload)) {
            return collect($payload)
                ->flatMap(fn (mixed $value): array => $this->collectSensitiveValues($value))
                ->filter(fn (string $value): bool => mb_strlen($value) >= 4)
                ->unique()
                ->values()
                ->all();
        }

        if (! is_scalar($payload)) {
            return [];
        }

        $value = trim((string) $payload);

        return $value !== '' ? [$value] : [];
    }

    protected function isSensitiveKey(string $key): bool
    {
        $key = Str::lower(str_replace(['-', '_', ' '], '', $key));

        return str_contains($key, 'password')
            || str_contains($key, 'secret')
            || str_contains($key, 'token')
            || str_contains($key, 'cookie')
            || str_contains($key, 'sessionpayload')
            || str_contains($key, 'browserwsendpoint')
            || str_contains($key, 'websocketendpoint')
            || in_array($key, ['webmailsession', 'browsersession', 'wsendpoint'], true);
    }
}
