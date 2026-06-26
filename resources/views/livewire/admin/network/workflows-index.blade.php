<div class="space-y-6" wire:loading.class="opacity-60 pointer-events-none">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Workflows</h1>
            <p class="mt-1 text-sm text-gray-500">
                Workflows gruppiert nach Prozessbereich, als kompakte Liste mit Kennzahlen.
            </p>
        </div>
        <div class="flex flex-wrap gap-3">
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

    <x-admin.panel title="Neuer Workflow">
        <div class="grid gap-4 lg:grid-cols-[minmax(0,220px)_minmax(0,240px)_minmax(0,1fr)_auto]">
            <div>
                <label for="new-workflow-name" class="block text-sm font-medium text-gray-700">Name</label>
                <input id="new-workflow-name" type="text" wire:model.defer="newWorkflowName" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('newWorkflowName') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="new-workflow-group" class="block text-sm font-medium text-gray-700">Gruppe</label>
                <input id="new-workflow-group" type="text" wire:model.defer="newWorkflowGroup" placeholder="custom, mail, browser" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('newWorkflowGroup') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="new-workflow-description" class="block text-sm font-medium text-gray-700">Beschreibung</label>
                <input id="new-workflow-description" type="text" wire:model.defer="newWorkflowDescription" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('newWorkflowDescription') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end">
                <button type="button" wire:click="createWorkflow" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                    Erstellen
                </button>
            </div>
        </div>
    </x-admin.panel>

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

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Workflow</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Gruppe</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Listen</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Tasks</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Laeufe</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Status</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Aktionen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($visibleWorkflows as $workflow)
                        @php
                            $taskCardCount = $workflow->steps->sum(fn ($step) => count($step->task_cards));
                        @endphp
                        <tr wire:key="workflow-row-{{ $workflow->id }}" class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <div class="min-w-0">
                                    <a href="{{ route('network.workflows.manage', $workflow) }}" class="text-sm font-semibold text-slate-900 hover:text-blue-700">{{ $workflow->name }}</a>
                                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ $workflow->description ?: $workflow->slug }}</p>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600">{{ $groupLabels[$workflow->category] ?? $workflow->category }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold text-slate-700">{{ $workflow->steps_count }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold text-slate-700">{{ $taskCardCount }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold text-slate-700">{{ $workflow->runs_count }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1 {{ $workflow->is_active ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-50 text-slate-500 ring-slate-200' }}">
                                    {{ $workflow->is_active ? 'Aktiv' : 'Inaktiv' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('network.workflows.manage', $workflow) }}" class="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-slate-800">
                                        Oeffnen
                                    </a>
                                    <button type="button" wire:click="deleteWorkflow({{ $workflow->id }})" wire:confirm="Workflow wirklich aus der Datenbank loeschen?" class="rounded-md border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 shadow-sm hover:bg-red-50">
                                        Loeschen
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">
                                Keine Workflows in dieser Gruppe.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.panel>
</div>
