@php
    $depth = max(0, (int) ($depth ?? 0));
    $children = $process->children ?? collect();
    $hasChildren = $children->isNotEmpty();
@endphp

<details class="group border-t border-slate-200 first:border-t-0" @if($depth === 0) open @endif>
    <summary class="grid cursor-pointer list-none grid-cols-[160px_180px_150px_minmax(360px,1fr)_150px] items-center gap-3 px-4 py-3 hover:bg-slate-50">
        <div class="flex items-center gap-2" style="padding-left: {{ $depth * 18 }}px">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded border border-slate-300 text-xs font-bold text-slate-600">
                {{ $hasChildren ? '+' : '-' }}
            </span>
            <div>
                <div class="font-semibold text-slate-900">PID {{ $process->pid }}</div>
                <div class="text-xs text-slate-500">PPID {{ $process->parent_pid ?: '-' }}</div>
            </div>
        </div>

        <div>
            <div class="text-sm font-semibold text-slate-900">{{ $process->process_type }}</div>
            <div class="text-xs text-slate-500">{{ $process->script_name ?: $process->executable ?: '-' }}</div>
        </div>

        <div>
            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ in_array($process->status, ['running', 'terminate_requested', 'kill_requested'], true) ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                {{ $process->status }}
            </span>
            @if($process->is_root)
                <div class="mt-1 text-xs font-semibold text-blue-700">Hauptprozess</div>
            @endif
            @if($process->is_idle_suspect)
                <div class="mt-1 text-xs font-semibold text-amber-700">Leerlauf-Verdacht</div>
            @endif
        </div>

        <div class="min-w-0">
            <div class="break-all text-xs text-slate-700">{{ $process->short_command ?: '-' }}</div>
            <div class="mt-1 text-xs text-slate-400">zuletzt: {{ optional($process->last_seen_at)->format('d.m.Y H:i:s') ?: '-' }}</div>
            @if($process->action_message)
                <div class="mt-1 text-xs text-blue-700">{{ $process->action_message }}</div>
            @endif
        </div>

        <div class="flex flex-wrap justify-end gap-2">
            <div class="w-full text-right text-xs text-slate-500">
                CPU {{ $process->cpu_percent !== null ? $process->cpu_percent.'%' : '-' }} · {{ floor($process->elapsed_seconds / 60) }} Min.
            </div>
            @if($process->isRunning())
                <button type="button" wire:click="terminate({{ $process->id }}, false)" wire:confirm="Prozess {{ $process->pid }} beenden?" class="rounded border border-amber-300 px-2 py-1 text-xs font-semibold text-amber-700 hover:bg-amber-50">
                    Beenden
                </button>
                <button type="button" wire:click="terminate({{ $process->id }}, true)" wire:confirm="Prozess {{ $process->pid }} wirklich erzwingen beenden?" class="rounded border border-red-300 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50">
                    Kill
                </button>
            @endif
        </div>
    </summary>

    @if($hasChildren)
        <div class="border-t border-slate-100 bg-slate-50/50">
            @foreach($children as $child)
                @include('livewire.admin.processes.partials.process-tree-node', [
                    'process' => $child,
                    'depth' => $depth + 1,
                ])
            @endforeach
        </div>
    @endif
</details>
