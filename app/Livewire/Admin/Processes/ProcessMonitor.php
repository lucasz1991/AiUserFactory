<?php

namespace App\Livewire\Admin\Processes;

use App\Jobs\SyncManagedProcessesJob;
use App\Jobs\TerminateManagedProcessJob;
use App\Models\ManagedProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class ProcessMonitor extends Component
{
    public string $filter = 'running';

    public int $limit = 25;

    public bool $compact = false;

    public bool $showHeader = true;

    public bool $autoRefresh = true;

    public ?string $notice = null;

    public function mount(
        bool $compact = false,
        int $limit = 25,
        bool $showHeader = true,
        bool $autoRefresh = true,
    ): void {
        $this->compact = $compact;
        $this->limit = max(1, min(200, $limit));
        $this->showHeader = $showHeader;
        $this->autoRefresh = $autoRefresh;

        if ($compact) {
            $this->filter = 'running';
        }

        $this->syncProcesses(false);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'running', 'exited', 'idle'], true)
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
            $this->notice = 'Prozessliste wurde synchronisiert.';
        }
    }

    public function terminate(int $processId, bool $force = false): void
    {
        if (! Schema::hasTable('managed_processes')) {
            $this->notice = 'Die Tabelle managed_processes ist noch nicht migriert.';

            return;
        }

        $process = ManagedProcess::query()->find($processId);

        if (! $process) {
            $this->notice = 'Prozess wurde nicht gefunden.';

            return;
        }

        TerminateManagedProcessJob::dispatchSync($process->id, $force);
        $process->refresh();
        $this->notice = $process->action_message ?: 'Prozessaktion wurde ausgefuehrt.';
        $this->syncProcesses(false);
    }

    public function render()
    {
        $processes = Schema::hasTable('managed_processes')
            ? $this->loadProcesses()
            : collect();

        $view = view('livewire.admin.processes.process-monitor', [
            'processes' => $processes,
            'stats' => $this->stats(),
            'tableReady' => Schema::hasTable('managed_processes'),
        ]);

        return $this->compact ? $view : $view->layout('layouts.master');
    }

    protected function loadProcesses(): Collection
    {
        return ManagedProcess::query()
            ->when($this->filter === 'running', fn ($query) => $query->whereIn('status', ['running', 'terminate_requested', 'kill_requested']))
            ->when($this->filter === 'exited', fn ($query) => $query->whereIn('status', ['exited', 'terminated', 'killed']))
            ->when($this->filter === 'idle', fn ($query) => $query->where('is_idle_suspect', true)->where('status', 'running'))
            ->orderByRaw("CASE WHEN status IN ('running', 'terminate_requested', 'kill_requested') THEN 0 ELSE 1 END")
            ->orderByDesc('last_seen_at')
            ->orderBy('family_root_pid')
            ->orderBy('pid')
            ->limit($this->limit)
            ->get();
    }

    protected function stats(): array
    {
        if (! Schema::hasTable('managed_processes')) {
            return [
                'total' => 0,
                'running' => 0,
                'idle' => 0,
                'exited' => 0,
            ];
        }

        return [
            'total' => ManagedProcess::query()->count(),
            'running' => ManagedProcess::query()->whereIn('status', ['running', 'terminate_requested', 'kill_requested'])->count(),
            'idle' => ManagedProcess::query()->where('is_idle_suspect', true)->where('status', 'running')->count(),
            'exited' => ManagedProcess::query()->whereIn('status', ['exited', 'terminated', 'killed'])->count(),
        ];
    }
}
