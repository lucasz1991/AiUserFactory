<?php

namespace App\Livewire\Admin\Config;

use App\Jobs\SyncManagedProcessesJob;
use App\Models\ManagedProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class PersonProcessList extends Component
{
    public int $personId;

    public string $filter = 'running';

    public bool $autoRefresh = true;

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

        return view('livewire.admin.config.person-process-list', [
            'tableReady' => $tableReady,
            'processes' => $processes,
            'stats' => [
                'total' => $processes->count(),
                'running' => $processes->whereIn('status', ['running', 'terminate_requested', 'kill_requested'])->count(),
                'stale' => $processes->filter(fn (ManagedProcess $process): bool => $this->isStale($process))->count(),
                'exited' => $processes->whereIn('status', ['exited', 'terminated', 'killed', 'restarted'])->count(),
            ],
        ]);
    }

    protected function loadProcesses(): Collection
    {
        return ManagedProcess::query()
            ->where(function ($query): void {
                $query->where('metadata->subject_person_id', $this->personId)
                    ->orWhere('metadata->person_id', $this->personId);
            })
            ->when($this->filter === 'running', fn ($query) => $query->whereIn('status', ['running', 'terminate_requested', 'kill_requested']))
            ->when($this->filter === 'exited', fn ($query) => $query->whereIn('status', ['exited', 'terminated', 'killed', 'restarted']))
            ->orderByRaw("CASE WHEN status IN ('running', 'terminate_requested', 'kill_requested') THEN 0 ELSE 1 END")
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get()
            ->when($this->filter === 'stale', fn (Collection $processes): Collection => $processes->filter(fn (ManagedProcess $process): bool => $this->isStale($process))->values());
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
