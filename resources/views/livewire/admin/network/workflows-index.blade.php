<div class="space-y-6" wire:loading.class="opacity-60 pointer-events-none">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Workflows</h1>
            <p class="mt-1 text-sm text-gray-500">
                Workflows gruppiert nach Prozessbereich, als kompakte Liste mit Kennzahlen.
            </p>
        </div>
        <div class="flex flex-wrap gap-3">
            <button type="button" wire:click="$set('showCreateWorkflowModal', true)" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                Neuer Workflow
            </button>
            <a href="{{ route('network.actions') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                Aktionen
            </a>
            <a href="{{ route('processes.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                Prozesse
            </a>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-admin.stat label="Workflows" :value="$summary['workflows']" tone="slate" />
        <x-admin.stat label="Aktiv" :value="$summary['active_workflows']" tone="emerald" />
        <x-admin.stat label="Listen" :value="$summary['lists']" tone="blue" />
        <x-admin.stat label="Step-Karten" :value="$summary['task_cards']" tone="amber" />
    </div>

    <x-admin.panel title="Workflow-Gruppen">
        <div class="border-b border-slate-200">
            <nav class="-mb-px flex gap-4 overflow-x-auto" aria-label="Workflow Gruppen">
                <button type="button" wire:click="$set('activeGroup', 'all')" class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-semibold {{ $activeGroup === 'all' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}">
                    Alle
                    <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ $workflows->count() }}</span>
                </button>
                @foreach($groups as $group)
                    <button type="button" wire:click="$set('activeGroup', @js($group))" class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-semibold {{ $activeGroup === $group ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}">
                        {{ $groupLabels[$group] ?? $group }}
                        <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ $workflows->where('category', $group)->count() }}</span>
                    </button>
                @endforeach
            </nav>
        </div>

        <div class="overflow-visible">
            <table class="w-full table-fixed divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="w-[42%] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Workflow</th>
                        <th class="w-[18%] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Gruppe</th>
                        <th class="w-[24%] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Daten</th>
                        <th class="w-[10%] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Status</th>
                        <th class="w-[6%] px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($visibleWorkflows as $workflow)
                        @php
                            $taskCardCount = $workflow->steps->sum(fn ($step) => count($step->task_cards));
                        @endphp
                        <tr wire:key="workflow-row-{{ $workflow->id }}" class="hover:bg-slate-50">
                            <td class="min-w-0 px-3 py-3">
                                <div class="min-w-0">
                                    <a href="{{ route('network.workflows.manage', $workflow) }}" class="block truncate text-sm font-semibold text-slate-900 hover:text-blue-700">{{ $workflow->name }}</a>
                                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ $workflow->description ?: $workflow->slug }}</p>
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <span class="inline-flex max-w-full truncate rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">
                                    {{ $groupLabels[$workflow->category] ?? $workflow->category }}
                                </span>
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    <span title="Listen" class="inline-flex items-center rounded-full bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700 ring-1 ring-blue-200">L {{ $workflow->steps_count }}</span>
                                    <span title="Tasks" class="inline-flex items-center rounded-full bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700 ring-1 ring-amber-200">T {{ $taskCardCount }}</span>
                                    <span title="Laeufe" class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">R {{ $workflow->runs_count }}</span>
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1 {{ $workflow->is_active ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-50 text-slate-500 ring-slate-200' }}">
                                    {{ $workflow->is_active ? 'Aktiv' : 'Inaktiv' }}
                                </span>
                            </td>
                            <td class="px-3 py-3 text-right">
                                <x-workflows.actions-dropdown :workflow="$workflow" edit-method="openEditWorkflow" delete-method="deleteWorkflow" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">
                                Keine Workflows in dieser Gruppe.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.panel>

    <x-dialog-modal wire:model="showCreateWorkflowModal" maxWidth="2xl">
        <x-slot name="title">Neuer Workflow</x-slot>
        <x-slot name="content">
            <x-workflows.workflow-form
                name-model="newWorkflowName"
                group-model="newWorkflowGroup"
                description-model="newWorkflowDescription"
            />
        </x-slot>
        <x-slot name="footer">
            <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
            <button type="button" wire:click="createWorkflow" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Erstellen</button>
        </x-slot>
    </x-dialog-modal>

    <x-dialog-modal wire:model="showEditWorkflowModal" maxWidth="2xl">
        <x-slot name="title">Workflow bearbeiten</x-slot>
        <x-slot name="content">
            <x-workflows.workflow-form
                name-model="editingWorkflowName"
                group-model="editingWorkflowGroup"
                description-model="editingWorkflowDescription"
                active-model="editingWorkflowActive"
            />
        </x-slot>
        <x-slot name="footer">
            <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
            <button type="button" wire:click="saveEditWorkflow" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">Speichern</button>
        </x-slot>
    </x-dialog-modal>
</div>
