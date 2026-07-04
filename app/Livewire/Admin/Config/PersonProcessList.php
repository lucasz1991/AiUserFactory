<?php

namespace App\Livewire\Admin\Config;

use App\Jobs\SyncManagedProcessesJob;
use App\Models\ManagedProcess;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Mail\WebmailSessionRunner;
use App\Services\Processes\ManagedProcessInventory;
use App\Services\Workflows\WorkflowExecutionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class PersonProcessList extends Component
{
    public int $personId;

    public string $filter = 'running';

    public bool $autoRefresh = true;

    public bool $showChildProcesses = false;

    public bool $showPreviewModal = false;

    public ?string $previewRunId = null;

    public ?string $previewRunType = null;

    public array $previewStatus = [];

    public bool $showWorkflowPreviewModal = false;

    public ?int $previewWorkflowRunId = null;

    public ?string $notice = null;

    public function mount(int $personId): void
    {
        $this->personId = $personId;
        $this->syncProcesses(false);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['running', 'stale', 'exited', 'all'], true)
            ? $filter
            : 'running';
    }

    public function toggleChildProcesses(): void
    {
        $this->showChildProcesses = ! $this->showChildProcesses;
    }

    public function openPreview(string $runId, string $runType): void
    {
        $runId = trim($runId);
        $runType = trim($runType);

        if ($runId === '' || ! in_array($runType, ['mail-registration', 'webmail-session'], true)) {
            return;
        }

        $this->previewRunId = $runId;
        $this->previewRunType = $runType;
        $this->refreshPreview();
        $this->showPreviewModal = true;
    }

    public function refreshPreview(): void
    {
        if (! $this->previewRunId || ! $this->previewRunType) {
            return;
        }

        $status = match ($this->previewRunType) {
            'mail-registration' => app(MailAccountRegistrationRunner::class)->readRun($this->previewRunId),
            'webmail-session' => app(WebmailSessionRunner::class)->readRun($this->previewRunId),
            default => null,
        };

        if (is_array($status)) {
            $this->previewStatus = $status;
        }
    }

    public function closePreview(): void
    {
        $this->showPreviewModal = false;
    }

    public function cancelPreviewRun(): void
    {
        if (! $this->previewRunId || ! $this->previewRunType) {
            return;
        }

        $message = 'Prozesslauf wurde im Vorschau-Fenster gestoppt.';
        $result = match ($this->previewRunType) {
            'mail-registration' => app(MailAccountRegistrationRunner::class)->cancelRun($this->previewRunId, true, $message),
            'webmail-session' => app(WebmailSessionRunner::class)->cancelRun($this->previewRunId, true, $message),
            default => ['ok' => false, 'message' => 'Unbekannter Prozesstyp.'],
        };

        $this->notice = (string) ($result['message'] ?? $message);
        $this->refreshPreview();
        $this->syncProcesses(false);
    }

    public function openWorkflowPreview(int $workflowRunId): void
    {
        if ($workflowRunId <= 0) {
            return;
        }

        $this->previewWorkflowRunId = $workflowRunId;
        $this->showWorkflowPreviewModal = true;
    }

    public function closeWorkflowPreview(): void
    {
        $this->showWorkflowPreviewModal = false;
    }

    public function refreshWorkflowPreview(): void
    {
        if (! $this->previewWorkflowRunId) {
            return;
        }

        $run = WorkflowRun::query()->find($this->previewWorkflowRunId);

        if (! $run || in_array($run->status, ['completed', 'failed', 'cancelled', 'timed_out', 'lost'], true)) {
            return;
        }

        app(WorkflowExecutionService::class)->refresh($run);
    }

    public function cancelWorkflowRun(int $workflowRunId): void
    {
        $run = WorkflowRun::query()->find($workflowRunId);

        if (! $run) {
            $this->notice = 'Workflow-Lauf wurde nicht gefunden.';

            return;
        }

        $result = app(WorkflowExecutionService::class)->cancel($run, 'Workflow-Lauf wurde ueber die Prozessliste gestoppt.');
        $this->notice = (string) ($result['message'] ?? 'Workflow-Lauf wurde gestoppt.');
        $this->syncProcesses(false);
    }

    public function cancelWorkflowPreview(): void
    {
        if (! $this->previewWorkflowRunId) {
            return;
        }

        $this->cancelWorkflowRun($this->previewWorkflowRunId);
    }

    public function deleteQueuedWorkflowRun(int $workflowRunId): void
    {
        $run = WorkflowRun::query()->find($workflowRunId);

        if (! $run) {
            $this->notice = 'Workflow-Lauf wurde nicht gefunden.';

            return;
        }

        $result = app(WorkflowExecutionService::class)->deleteQueued($run);
        $this->notice = (string) ($result['message'] ?? 'Workflow-Lauf wurde geloescht.');

        if (($result['ok'] ?? false) && $this->previewWorkflowRunId === $workflowRunId) {
            $this->previewWorkflowRunId = null;
            $this->showWorkflowPreviewModal = false;
        }

        $this->syncProcesses(false);
    }

    public function deleteQueuedWorkflowPreview(): void
    {
        if (! $this->previewWorkflowRunId) {
            return;
        }

        $this->deleteQueuedWorkflowRun($this->previewWorkflowRunId);
    }

    public function terminateProcess(int $processId, bool $force = true): void
    {
        $process = ManagedProcess::query()->find($processId);

        if (! $process) {
            $this->notice = 'Prozess wurde nicht gefunden.';

            return;
        }

        $result = app(ManagedProcessInventory::class)->terminate($process, $force);
        $this->notice = (string) ($result['message'] ?? 'Prozessaktion wurde ausgefuehrt.');
        $this->syncProcesses(false);
    }

    public function syncProcesses(bool $showNotice = true): void
    {
        if (! Schema::hasTable('managed_processes')) {
            $this->notice = 'Die Tabelle managed_processes ist noch nicht migriert.';

            return;
        }

        SyncManagedProcessesJob::dispatchSync();

        if ($showNotice) {
            $this->notice = 'Personen-Prozesse wurden synchronisiert.';
        }
    }

    public function render()
    {
        $tableReady = Schema::hasTable('managed_processes');
        $processes = $tableReady ? $this->loadProcesses() : collect();
        $allPersonProcesses = $tableReady ? $this->basePersonProcessQuery()->get() : collect();
        $rootProcesses = $allPersonProcesses->where('is_root', true);

        return view('livewire.admin.config.person-process-list', [
            'tableReady' => $tableReady,
            'processes' => $processes,
            'previewWorkflowRun' => $this->previewWorkflowRun(),
            'stats' => [
                'total' => $rootProcesses->count() + $this->personWorkflowRuns()->count(),
                'running' => $rootProcesses->whereIn('status', ['running', 'terminate_requested', 'kill_requested'])->count()
                    + $this->personWorkflowRuns()->whereIn('status', ['queued', 'running', 'waiting', 'stop_requested', 'unreachable'])->count(),
                'stale' => $rootProcesses->filter(fn (ManagedProcess $process): bool => $this->isStale($process))->count(),
                'exited' => $rootProcesses->whereIn('status', ['exited', 'terminated', 'killed', 'restarted'])->count()
                    + $this->personWorkflowRuns()->whereIn('status', ['completed', 'failed', 'cancelled', 'timed_out', 'lost'])->count(),
                'children' => $allPersonProcesses->where('is_root', false)->count(),
            ],
        ]);
    }

    protected function loadProcesses(): Collection
    {
        $processes = $this->basePersonProcessQuery()
            ->when(! $this->showChildProcesses, fn ($query) => $query->where('is_root', true))
            ->when($this->filter === 'running', fn ($query) => $query->whereIn('status', ['running', 'terminate_requested', 'kill_requested']))
            ->when($this->filter === 'exited', fn ($query) => $query->whereIn('status', ['exited', 'terminated', 'killed', 'restarted']))
            ->orderByRaw("CASE WHEN status IN ('running', 'terminate_requested', 'kill_requested') THEN 0 ELSE 1 END")
            ->orderByDesc('is_root')
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get()
            ->when($this->filter === 'stale', fn (Collection $processes): Collection => $processes->filter(fn (ManagedProcess $process): bool => $this->isStale($process))->values());

        $processes = $this->attachWorkflowPreview($processes);

        if ($this->filter === 'stale') {
            return $processes;
        }

        return $this->withWorkflowRunProcesses($processes);
    }

    protected function basePersonProcessQuery()
    {
        $workflowRunIds = $this->personWorkflowRuns()->pluck('id')->values();

        return ManagedProcess::query()
            ->where(function ($query) use ($workflowRunIds): void {
                $query->where('metadata->subject_person_id', $this->personId)
                    ->orWhere('metadata->person_id', $this->personId);

                if ($workflowRunIds->isNotEmpty()) {
                    $query->orWhereIn('metadata->workflow_context->workflowRunId', $workflowRunIds)
                        ->orWhereIn('metadata->process_identity->workflowRunId', $workflowRunIds);
                }
            });
    }

    protected function personWorkflowRuns(): Collection
    {
        return WorkflowRun::query()
            ->with([
                'currentStep',
                'workflow.steps' => fn ($query) => $query->ordered(),
                'stepRuns.workflowStep',
            ])
            ->where('context_json->person_id', $this->personId)
            ->latest('id')
            ->limit(50)
            ->get();
    }

    protected function previewWorkflowRun(): ?WorkflowRun
    {
        if (! $this->previewWorkflowRunId) {
            return null;
        }

        return WorkflowRun::query()
            ->with([
                'currentStep',
                'workflow.steps' => fn ($query) => $query->ordered(),
                'stepRuns.workflowStep',
            ])
            ->find($this->previewWorkflowRunId);
    }

    protected function attachWorkflowPreview(Collection $processes): Collection
    {
        if ($processes->isEmpty()) {
            return $processes;
        }

        $stepRunIds = $processes
            ->map(fn (ManagedProcess $process): int => $this->workflowStepRunIdForProcess($process))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($stepRunIds->isEmpty()) {
            return $processes;
        }

        $stepRuns = WorkflowStepRun::query()
            ->with([
                'workflowStep',
                'workflowRun.currentStep',
                'workflowRun.workflow.steps' => fn ($query) => $query->ordered(),
                'workflowRun.stepRuns.workflowStep',
            ])
            ->whereIn('id', $stepRunIds)
            ->get()
            ->keyBy('id');

        return $processes->map(function (ManagedProcess $process) use ($stepRuns): ManagedProcess {
            $stepRun = $stepRuns->get($this->workflowStepRunIdForProcess($process));

            if ($stepRun) {
                $process->setRelation('workflowStepRunPreview', $stepRun);
                $process->setRelation('workflowRunPreview', $stepRun->workflowRun);
                $process->setAttribute('workflow_active_task_key', (string) data_get($stepRun->workflowRun?->context_json, 'next_task_key', ''));
            }

            return $process;
        });
    }

    protected function withWorkflowRunProcesses(Collection $processes): Collection
    {
        $virtualProcesses = $this->personWorkflowRuns()
            ->when($this->filter === 'running', fn (Collection $runs): Collection => $runs->whereIn('status', ['queued', 'running', 'waiting', 'stop_requested', 'unreachable'])->values())
            ->when($this->filter === 'exited', fn (Collection $runs): Collection => $runs->whereIn('status', ['completed', 'failed', 'cancelled', 'timed_out', 'lost'])->values())
            ->map(function (WorkflowRun $run): ManagedProcess {
                $status = in_array($run->status, ['queued', 'running', 'waiting', 'stop_requested', 'unreachable'], true) ? 'running' : 'exited';
                $process = new ManagedProcess;
                $process->exists = false;
                $process->forceFill([
                    'id' => -$run->id,
                    'pid' => -$run->id,
                    'parent_pid' => null,
                    'family_root_pid' => -$run->id,
                    'process_key' => 'workflow:'.$run->id,
                    'run_id' => $run->run_uuid,
                    'run_type' => 'workflow',
                    'process_role' => 'workflow-run',
                    'process_type' => 'workflow-run',
                    'script_name' => $run->workflow?->name,
                    'short_command' => $run->workflow?->description ?: $run->workflow?->slug,
                    'status' => $status,
                    'is_managed' => false,
                    'is_root' => true,
                    'is_idle_suspect' => false,
                    'elapsed_seconds' => $this->workflowElapsedSeconds($run),
                    'started_at' => $run->started_at ?? $run->queued_at,
                    'detected_at' => $run->queued_at,
                    'last_seen_at' => $run->updated_at,
                    'heartbeat_at' => $run->updated_at,
                    'last_stage' => $run->currentStep?->name,
                    'last_message' => $run->error_message,
                    'metadata' => [
                        'workflow_run_db_id' => $run->id,
                        'subject_person_id' => $this->personId,
                    ],
                ]);
                $process->setRelation('workflowRunPreview', $run);
                $process->setRelation('workflowStepRunPreview', $run->stepRuns->first(fn ($stepRun) => in_array($stepRun->status, ['running', 'waiting'], true)) ?: $run->stepRuns->last());
                $process->setAttribute('workflow_active_task_key', (string) data_get($run->context_json, 'next_task_key', ''));

                return $process;
            });

        return $virtualProcesses
            ->concat($processes)
            ->unique(fn (ManagedProcess $process): string => $process->process_type.':'.$process->pid)
            ->values();
    }

    protected function workflowStepRunIdForProcess(ManagedProcess $process): int
    {
        return (int) (
            data_get($process->metadata, 'process_identity.workflowStepRunId')
            ?: data_get($process->metadata, 'processIdentity.workflowStepRunId')
            ?: data_get($process->metadata, 'workflow_context.workflowStepRunId')
            ?: data_get($process->metadata, 'workflow.workflowStepRunId')
            ?: data_get($process->metadata, 'workflowStepRunId')
            ?: 0
        );
    }

    protected function workflowElapsedSeconds(WorkflowRun $run): int
    {
        if ($run->duration_ms !== null) {
            return intdiv(max(0, (int) $run->duration_ms), 1000);
        }

        $startedAt = $run->started_at ?? $run->queued_at;

        if (! $startedAt) {
            return 0;
        }

        return max(0, $startedAt->diffInSeconds($run->finished_at ?? now()));
    }

    protected function isStale(ManagedProcess $process): bool
    {
        if (! $process->heartbeat_at) {
            return $process->isRunning();
        }

        $interval = max(3, (int) data_get($process->metadata, 'live_preview_interval_seconds', 3));

        return $process->isRunning() && $process->heartbeat_at->diffInSeconds(now()) > max(30, $interval * 5);
    }
}
