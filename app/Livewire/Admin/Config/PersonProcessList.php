<?php

namespace App\Livewire\Admin\Config;

use App\Jobs\SyncManagedProcessesJob;
use App\Models\ManagedProcess;
use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Mail\WebmailSessionRunner;
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
            'stats' => [
                'total' => $rootProcesses->count(),
                'running' => $rootProcesses->whereIn('status', ['running', 'terminate_requested', 'kill_requested'])->count(),
                'stale' => $rootProcesses->filter(fn (ManagedProcess $process): bool => $this->isStale($process))->count(),
                'exited' => $rootProcesses->whereIn('status', ['exited', 'terminated', 'killed', 'restarted'])->count(),
                'children' => $allPersonProcesses->where('is_root', false)->count(),
            ],
        ]);
    }

    protected function loadProcesses(): Collection
    {
        return $this->basePersonProcessQuery()
            ->when(! $this->showChildProcesses, fn ($query) => $query->where('is_root', true))
            ->when($this->filter === 'running', fn ($query) => $query->whereIn('status', ['running', 'terminate_requested', 'kill_requested']))
            ->when($this->filter === 'exited', fn ($query) => $query->whereIn('status', ['exited', 'terminated', 'killed', 'restarted']))
            ->orderByRaw("CASE WHEN status IN ('running', 'terminate_requested', 'kill_requested') THEN 0 ELSE 1 END")
            ->orderByDesc('is_root')
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get()
            ->when($this->filter === 'stale', fn (Collection $processes): Collection => $processes->filter(fn (ManagedProcess $process): bool => $this->isStale($process))->values());
    }

    protected function basePersonProcessQuery()
    {
        return ManagedProcess::query()
            ->where(function ($query): void {
                $query->where('metadata->subject_person_id', $this->personId)
                    ->orWhere('metadata->person_id', $this->personId);
            });
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
