@php
    $depth = max(0, (int) ($depth ?? 0));
    $children = $process->children ?? collect();
    $hasChildren = $children->isNotEmpty();
    $defaultOpen = (bool) ($defaultOpen ?? false);
    $workflowRunPreview = $process->relationLoaded('workflowRunPreview') ? $process->getRelation('workflowRunPreview') : null;
    $workflowStepRunPreview = $process->relationLoaded('workflowStepRunPreview') ? $process->getRelation('workflowStepRunPreview') : null;
    $isWorkflowProcess = $process->process_type === 'workflow-run';
@endphp

<div x-data="{ workflowPreviewOpen: false }" class="border-t border-slate-200 first:border-t-0">
    <details class="group" @if($defaultOpen && $depth === 0) open @endif>
        <summary class="grid cursor-pointer list-none grid-cols-[160px_180px_150px_minmax(360px,1fr)_150px] items-center gap-3 px-4 py-3 hover:bg-slate-50">
            <div class="flex items-center gap-2" style="padding-left: {{ $depth * 18 }}px">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded border border-slate-300 text-xs font-bold text-slate-600">
                    {{ $hasChildren ? '+' : '-' }}
                </span>
                <div>
                    <div class="font-semibold text-slate-900">{{ $isWorkflowProcess ? 'Workflow #'.abs((int) $process->pid) : 'PID '.$process->pid }}</div>
                    <div class="text-xs text-slate-500">{{ $isWorkflowProcess ? 'Root-Prozess' : 'PPID '.($process->parent_pid ?: '-') }}</div>
                    @if($process->run_id)
                        <div class="mt-1 max-w-32 truncate text-xs text-blue-700">{{ $process->run_id }}</div>
                    @endif
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
                @if($process->heartbeat_at)
                    <div class="mt-1 text-xs text-slate-500">Heartbeat {{ $process->heartbeat_at->diffInSeconds(now()) }}s</div>
                @endif
            </div>

            <div class="min-w-0">
                <div class="break-all text-xs text-slate-700">{{ $process->short_command ?: '-' }}</div>
                @if($process->last_stage)
                    <div class="mt-1 text-xs font-semibold text-slate-600">{{ $process->last_stage }}</div>
                @endif
                <div class="mt-1 text-xs text-slate-400">zuletzt: {{ optional($process->last_seen_at)->format('d.m.Y H:i:s') ?: '-' }}</div>
                @if($process->action_message)
                    <div class="mt-1 text-xs text-blue-700">{{ $process->action_message }}</div>
                @endif
                @if($workflowRunPreview)
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-blue-50 px-2 py-1 text-[11px] font-semibold text-blue-700 ring-1 ring-blue-200">
                            Workflow #{{ $workflowRunPreview->id }}
                        </span>
                        @if($workflowStepRunPreview?->workflowStep)
                            <span class="rounded-full bg-slate-100 px-2 py-1 text-[11px] font-semibold text-slate-600">
                                {{ $workflowStepRunPreview->workflowStep->name }}
                            </span>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex flex-wrap justify-end gap-2">
                <div class="w-full text-right text-xs text-slate-500">
                    CPU {{ $process->cpu_percent !== null ? $process->cpu_percent.'%' : '-' }} - {{ floor($process->elapsed_seconds / 60) }} Min.
                </div>
                @if($workflowRunPreview)
                    <button type="button" x-on:click.stop="workflowPreviewOpen = true" class="rounded border border-blue-300 px-2 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-50">
                        Workflow
                    </button>
                @endif
                @if(! $isWorkflowProcess && $process->isRunning())
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
                        'defaultOpen' => false,
                    ])
                @endforeach
            </div>
        @endif
    </details>

    @if($workflowRunPreview)
        <div
            x-show="workflowPreviewOpen"
            x-cloak
            class="fixed inset-0 z-50 overflow-y-auto px-4 py-4 sm:px-6 sm:py-8"
            style="display: none;"
            x-on:keydown.escape.window="workflowPreviewOpen = false"
        >
            <div class="fixed inset-0 bg-gray-500 opacity-75" x-on:click="workflowPreviewOpen = false"></div>
            <div class="relative z-10 mx-auto flex min-h-full max-w-6xl items-center justify-center">
                <div class="flex max-h-[calc(100vh-4rem)] w-full flex-col overflow-hidden rounded-lg bg-white shadow-xl" x-trap.inert.noscroll="workflowPreviewOpen">
                    <div class="flex items-start justify-between gap-4 border-b border-gray-200 px-6 py-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Workflow-Vorschau</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                {{ $isWorkflowProcess ? 'Workflow-Prozess' : 'Prozess PID '.$process->pid }} - {{ $process->process_type }}
                            </p>
                        </div>
                        <button type="button" x-on:click="workflowPreviewOpen = false" class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                            <span class="sr-only">Schliessen</span>
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto p-6">
                        <x-workflows.run-preview
                            :workflow-run="$workflowRunPreview"
                            :process="$isWorkflowProcess ? null : $process"
                            :active-step-id="$workflowStepRunPreview?->workflow_step_id"
                            :active-task-key="$process->workflow_active_task_key"
                        />
                    </div>
                    <div class="flex justify-end border-t border-gray-200 bg-gray-50 px-6 py-4">
                        <button type="button" x-on:click="workflowPreviewOpen = false" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                            Schliessen
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
