@php
    $depth = max(0, (int) ($depth ?? 0));
    $children = $process->children ?? collect();
    $hasChildren = $children->isNotEmpty();
    $isWorkflowProcess = $process->process_type === 'workflow-run';
    $workflowRunPreview = $process->relationLoaded('workflowRunPreview') ? $process->getRelation('workflowRunPreview') : null;
    $workflowRunStatus = (string) data_get($workflowRunPreview, 'status', '');
@endphp

<tr wire:key="managed-process-{{ $process->id }}" class="{{ $process->is_idle_suspect ? 'bg-amber-50/60' : '' }}">
    <td class="whitespace-nowrap px-4 py-3">
        <div class="flex items-center gap-2" style="padding-left: {{ $depth * 18 }}px">
            @if($hasChildren)
                <details class="group contents" open>
                    <summary class="list-none"></summary>
                </details>
                <span class="inline-flex h-5 w-5 items-center justify-center rounded border border-slate-300 text-[10px] text-slate-600">+</span>
            @else
                <span class="inline-flex h-5 w-5"></span>
            @endif
            <div>
                <div class="font-semibold text-gray-900">{{ $process->pid }}</div>
                <div class="text-xs text-gray-500">PPID {{ $process->parent_pid ?: '-' }}</div>
            </div>
        </div>
    </td>
    <td class="whitespace-nowrap px-4 py-3">
        <div class="font-semibold text-gray-900">{{ $process->process_type }}</div>
        <div class="text-xs text-gray-500">{{ $process->script_name ?: $process->executable ?: '-' }}</div>
    </td>
    <td class="whitespace-nowrap px-4 py-3">
        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ in_array($process->status, ['running', 'terminate_requested', 'kill_requested'], true) ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
            {{ $process->status }}
        </span>
        @if($process->is_idle_suspect)
            <div class="mt-1 text-xs font-semibold text-amber-700">Leerlauf-Verdacht</div>
        @endif
        @if($process->is_root)
            <div class="mt-1 text-xs font-semibold text-blue-700">Hauptprozess</div>
        @endif
    </td>
    <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-600">
        <div>CPU: {{ $process->cpu_percent !== null ? $process->cpu_percent.'%' : '-' }}</div>
        <div>RAM: {{ $process->memory_mb !== null ? $process->memory_mb.' MB' : '-' }}</div>
        <div>Alter: {{ floor($process->elapsed_seconds / 60) }} Min.</div>
    </td>
    <td class="max-w-xl px-4 py-3">
        <div class="break-all text-xs text-gray-700">{{ $process->short_command ?: '-' }}</div>
        <div class="mt-1 text-xs text-gray-400">zuletzt: {{ optional($process->last_seen_at)->format('d.m.Y H:i:s') ?: '-' }}</div>
        @if($process->action_message)
            <div class="mt-1 text-xs text-blue-700">{{ $process->action_message }}</div>
        @endif
    </td>
    <td class="whitespace-nowrap px-4 py-3 text-right">
        @if($isWorkflowProcess && $workflowRunStatus === 'queued')
            <button type="button" wire:click="deleteQueuedWorkflowRun({{ abs((int) $process->pid) }})" wire:confirm="Eingeplanten Workflow-Lauf #{{ abs((int) $process->pid) }} wirklich loeschen?" class="rounded border border-red-300 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50">
                Loeschen
            </button>
        @elseif($isWorkflowProcess && $process->isRunning())
            <button type="button" wire:click="cancelWorkflowRun({{ abs((int) $process->pid) }})" wire:confirm="Workflow-Lauf #{{ abs((int) $process->pid) }} stoppen?" class="rounded border border-red-300 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50">
                Stoppen
            </button>
        @elseif($process->isRunning())
            <button type="button" wire:click="terminate({{ $process->id }}, false)" wire:confirm="Prozess {{ $process->pid }} beenden?" class="rounded border border-amber-300 px-2 py-1 text-xs font-semibold text-amber-700 hover:bg-amber-50">
                Beenden
            </button>
            <button type="button" wire:click="terminate({{ $process->id }}, true)" wire:confirm="Prozess {{ $process->pid }} wirklich erzwingen beenden?" class="ml-2 rounded border border-red-300 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50">
                Kill
            </button>
        @else
            <span class="text-xs text-gray-400">Keine Aktion</span>
        @endif
    </td>
</tr>

@foreach($children as $child)
    @include('livewire.admin.processes.partials.process-tree-row', [
        'process' => $child,
        'depth' => $depth + 1,
    ])
@endforeach
