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
    private const FACTORY_NODE_PROCESS_TYPES = [
        'mail-registration',
        'webmail-session',
        'verification-webmail-check',
        'instagram-scraper',
        'browser-child',
        'node',
    ];

    public string $filter = 'running';

    public int $limit = 25;

    public bool $compact = false;

    public bool $showHeader = true;

    public bool $autoRefresh = true;

    public ?int $rootPid = null;

    public ?string $runId = null;

    public ?string $notice = null;

    public function mount(
        bool $compact = false,
        int $limit = 25,
        bool $showHeader = true,
        bool $autoRefresh = true,
        ?int $rootPid = null,
        ?string $runId = null,
    ): void {
        $this->compact = $compact;
        $this->limit = max(1, min(200, $limit));
        $this->showHeader = $showHeader;
        $this->autoRefresh = $autoRefresh;
        $this->rootPid = $rootPid && $rootPid > 0 ? $rootPid : null;
        $this->runId = $runId !== null && trim($runId) !== '' ? trim($runId) : null;

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
            'processTree' => $this->buildProcessTree($processes),
            'stats' => $this->stats(),
            'tableReady' => Schema::hasTable('managed_processes'),
        ]);

        return $this->compact ? $view : $view->layout('layouts.master');
    }

    protected function loadProcesses(): Collection
    {
        $canFilterByRunId = $this->runId && Schema::hasColumn('managed_processes', 'run_id');

        return ManagedProcess::query()
            ->whereIn('process_type', self::FACTORY_NODE_PROCESS_TYPES)
            ->when($canFilterByRunId, fn ($query) => $query->where('run_id', $this->runId))
            ->when(! $canFilterByRunId && $this->rootPid, fn ($query) => $query->where(function ($inner): void {
                $inner->where('pid', $this->rootPid)
                    ->orWhere('family_root_pid', $this->rootPid);
            }))
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

    protected function buildProcessTree(Collection $processes): Collection
    {
        if ($processes->isEmpty()) {
            return collect();
        }

        $nodesByPid = $processes->mapWithKeys(function (ManagedProcess $process): array {
            $node = clone $process;
            $node->children = collect();

            return [(int) $node->pid => $node];
        });
        $roots = collect();

        foreach ($nodesByPid as $pid => $node) {
            $parentPid = (int) ($node->parent_pid ?? 0);

            if ($parentPid > 0 && $parentPid !== $pid && $nodesByPid->has($parentPid)) {
                $nodesByPid->get($parentPid)->children->push($node);

                continue;
            }

            $roots->push($node);
        }

        return $roots
            ->sortBy(fn ($node) => [(int) ($node->family_root_pid ?? $node->pid), (int) $node->pid])
            ->values();
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
            'total' => $this->baseStatsQuery()->count(),
            'running' => $this->baseStatsQuery()->whereIn('status', ['running', 'terminate_requested', 'kill_requested'])->count(),
            'idle' => $this->baseStatsQuery()->where('is_idle_suspect', true)->where('status', 'running')->count(),
            'exited' => $this->baseStatsQuery()->whereIn('status', ['exited', 'terminated', 'killed'])->count(),
        ];
    }

    protected function baseStatsQuery()
    {
        $canFilterByRunId = $this->runId && Schema::hasColumn('managed_processes', 'run_id');

        return ManagedProcess::query()
            ->whereIn('process_type', self::FACTORY_NODE_PROCESS_TYPES)
            ->where('is_root', true)
            ->when($canFilterByRunId, fn ($query) => $query->where('run_id', $this->runId))
            ->when(! $canFilterByRunId && $this->rootPid, fn ($query) => $query->where(function ($inner): void {
                $inner->where('pid', $this->rootPid)
                    ->orWhere('family_root_pid', $this->rootPid);
            }));
    }
}
