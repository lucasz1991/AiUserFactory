<div class="space-y-6" wire:loading.class="opacity-60 pointer-events-none">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-white">Workflows</h1>
            <p class="mt-1 text-sm text-white">
                Workflows gruppiert nach Prozessbereich, als kompakte Liste mit Kennzahlen.
            </p>
        </div>
        <div class="ml-auto flex max-w-full flex-col items-end gap-2">
            <div class="flex flex-wrap justify-end gap-2">
                <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="inline-flex items-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                        Verwalten
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-cloak x-show="open" x-transition x-on:click.outside="open = false" class="absolute right-0 z-50 mt-2 w-56 rounded-lg border border-slate-200 bg-white p-1.5 shadow-xl">
                        <button type="button" wire:click="$set('showCreateWorkflowModal', true)" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-100">Neuer Workflow</button>
                        <button type="button" wire:click="$set('showImportWorkflowModal', true)" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-blue-700 hover:bg-blue-50">Workflows importieren</button>
                    </div>
                </div>

                <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                        Weitere
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-cloak x-show="open" x-transition x-on:click.outside="open = false" class="absolute right-0 z-50 mt-2 w-52 rounded-lg border border-slate-200 bg-white p-1.5 shadow-xl">
                        <a href="{{ route('network.actions') }}" class="block rounded-md px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Aktionsplanung öffnen</a>
                        <a href="{{ route('processes.index') }}" class="block rounded-md px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Prozesse öffnen</a>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-1.5" aria-label="Workflow-Statistik">
                @foreach([
                    ['Workflows', $summary['workflows'], 'bg-slate-100 text-slate-700'],
                    ['Aktiv', $summary['active_workflows'], 'bg-emerald-50 text-emerald-700'],
                    ['Listen', $summary['lists'], 'bg-blue-50 text-blue-700'],
                    ['Tasks', $summary['task_cards'], 'bg-amber-50 text-amber-700'],
                ] as [$label, $value, $classes])
                    <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] leading-none {{ $classes }}">
                        <span class="font-medium opacity-75">{{ $label }}</span>
                        <span class="font-bold tabular-nums">{{ $value }}</span>
                    </span>
                @endforeach
            </div>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
            {{ session('error') }}
        </div>
    @endif

    <x-admin.panel title="Workflow-Gruppen">
        <div x-data="{ activeGroup: $persist(@entangle('activeGroup')) }" class="border-b border-slate-200 px-4">
            <nav class="-mb-px flex gap-4 overflow-x-auto" aria-label="Workflow Gruppen">
                <button type="button" wire:click="selectWorkflowGroup('all')" class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-semibold {{ $activeGroup === 'all' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}">
                    Alle
                    <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ $workflows->count() }}</span>
                </button>
                @foreach($groups as $group)
                    <button type="button" wire:click="selectWorkflowGroup(@js($group))" class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-semibold {{ $activeGroup === $group ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}">
                        {{ $groupLabels[$group] ?? $group }}
                        <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ $workflows->where('category', $group)->count() }}</span>
                    </button>
                @endforeach
            </nav>
        </div>

        @if($subcategories->isNotEmpty())
            <div class="flex flex-wrap gap-2 border-b border-slate-100 py-3 px-4">
                <button type="button" wire:click="$set('activeSubcategory', 'all')" class="rounded-md px-2.5 py-1.5 text-xs font-semibold ring-1 {{ $activeSubcategory === 'all' ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' }}">
                    Alle Unterkategorien
                    <span class="ml-1 opacity-75">{{ $groupWorkflows->count() }}</span>
                </button>
                @foreach($subcategories as $subcategory)
                    <button type="button" wire:click="$set('activeSubcategory', @js($subcategory))" class="rounded-md px-2.5 py-1.5 text-xs font-semibold ring-1 {{ $activeSubcategory === $subcategory ? 'bg-blue-600 text-white ring-blue-600' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' }}">
                        {{ $groupLabels[$subcategory] ?? str($subcategory)->replace(['_', '-'], ' ')->title() }}
                        <span class="ml-1 opacity-75">{{ $groupWorkflows->where('subcategory', $subcategory)->count() }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        @php
            $selectedIdStrings = collect($selectedWorkflowIds)->map(fn ($id) => (string) $id);
            $visibleIdStrings = $visibleWorkflows->pluck('id')->map(fn ($id) => (string) $id);
            $allVisibleSelected = $visibleIdStrings->isNotEmpty()
                && $visibleIdStrings->every(fn ($id) => $selectedIdStrings->contains($id));
        @endphp

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 py-3  px-4">
            <div class="text-xs font-medium text-slate-500">
                {{ count($selectedWorkflowIds) }} ausgewählt
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button" wire:click="toggleSelectAllVisibleWorkflows" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                    {{ $allVisibleSelected ? 'Sichtbare abwählen' : 'Alle sichtbaren auswählen' }}
                </button>
                @if(count($selectedWorkflowIds) < $workflows->count())
                    <button type="button" wire:click="selectAllWorkflows" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                        Alle {{ $workflows->count() }} auswählen
                    </button>
                @endif
                @if(count($selectedWorkflowIds) > 0)
                    <button type="button" wire:click="clearWorkflowSelection" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                        Auswahl aufheben
                    </button>
                @endif
                <button
                    type="button"
                    wire:click="exportSelectedWorkflows"
                    wire:loading.attr="disabled"
                    @disabled(count($selectedWorkflowIds) === 0)
                    class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Auswahl als ZIP exportieren
                </button>
            </div>
        </div>

        <div class="overflow-visible">
            <table class="w-full table-fixed divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="w-[4%] px-3 py-3 text-left">
                            <button type="button" wire:click="toggleSelectAllVisibleWorkflows" class="flex h-5 w-5 items-center justify-center rounded border {{ $allVisibleSelected ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 bg-white text-transparent' }}" aria-label="Alle sichtbaren Workflows auswählen">
                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-8 8a1 1 0 0 1-1.414 0l-4-4A1 1 0 0 1 4.71 9.29L8 12.586l7.296-7.296a1 1 0 0 1 1.408 0Z" clip-rule="evenodd" /></svg>
                            </button>
                        </th>
                        <th class="w-[32%] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Workflow</th>
                        <th class="w-[18%] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Gruppe</th>
                        <th class="w-[30%] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Daten</th>
                        <th class="w-[10%] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Status</th>
                        <th class="w-[6%] px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($visibleWorkflows as $workflow)
                        @php
                            $taskCardCount = $workflow->steps->sum(fn ($step) => count($step->task_cards));
                        @endphp
                        <tr
                            wire:key="workflow-row-{{ $workflow->id }}"
                            data-workflow-row-id="{{ $workflow->id }}"
                            data-assistant-highlight="workflow_row:{{ $workflow->id }}"
                            data-assistant-highlight-key="{{ $workflow->slug ?: $workflow->id }}"
                            class="hover:bg-slate-50"
                        >
                            <td class="px-3 py-3 align-middle">
                                <input type="checkbox" wire:model.live="selectedWorkflowIds" value="{{ $workflow->id }}" class="rounded border-slate-300 text-blue-600 shadow-sm focus:ring-blue-500" aria-label="{{ $workflow->name }} auswählen">
                            </td>
                            <td class="min-w-0 px-3 py-3">
                                <div class="min-w-0">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <a href="{{ route('network.workflows.manage', $workflow) }}" class="block min-w-0 truncate text-sm font-semibold text-slate-900 hover:text-blue-700">{{ $workflow->name }}</a>
                                        @if($workflow->is_edit_locked)
                                            <span title="{{ $workflow->lock_reason }}" class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700" aria-label="Workflow gesperrt">
                                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 0 0-9 0v3.75m-.75 0h10.5A2.25 2.25 0 0 1 19.5 12.75v6A2.25 2.25 0 0 1 17.25 21H6.75a2.25 2.25 0 0 1-2.25-2.25v-6A2.25 2.25 0 0 1 6.75 10.5Z" />
                                                </svg>
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ $workflow->description ?: $workflow->slug }}</p>
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <span class="inline-flex max-w-full truncate rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">
                                    {{ $groupLabels[$workflow->category] ?? $workflow->category }}
                                </span>
                                @if(trim((string) $workflow->subcategory) !== '')
                                    <span class="mt-1 inline-flex max-w-full truncate rounded-full bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700 ring-1 ring-blue-200">
                                        {{ $groupLabels[$workflow->subcategory] ?? str($workflow->subcategory)->replace(['_', '-'], ' ')->title() }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    <span title="Listen" class="inline-flex items-center rounded-full bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700 ring-1 ring-blue-200">L {{ $workflow->steps_count }}</span>
                                    <span title="Tasks" class="inline-flex items-center rounded-full bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700 ring-1 ring-amber-200">T {{ $taskCardCount }}</span>
                                    <span title="Benutzt" class="inline-flex items-center rounded-full bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">B {{ $workflow->runs_count }}</span>
                                    <span title="Erfolgreich" class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">OK {{ $workflow->successful_runs_count }}</span>
                                    <span title="Fehlerhaft" class="inline-flex items-center rounded-full bg-red-50 px-2 py-1 text-xs font-semibold text-red-700 ring-1 ring-red-200">F {{ $workflow->failed_runs_count }}</span>
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1 {{ $workflow->is_active ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-50 text-slate-500 ring-slate-200' }}">
                                    {{ $workflow->is_active ? 'Aktiv' : 'Inaktiv' }}
                                </span>
                            </td>
                            <td class="px-3 py-3 text-right">
                                <x-workflows.actions-dropdown :workflow="$workflow" edit-method="openEditWorkflow" duplicate-method="duplicateWorkflow" delete-method="deleteWorkflow" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">
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
                subcategory-model="newWorkflowSubcategory"
                description-model="newWorkflowDescription"
                development-model="newWorkflowDevelopment"
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
                subcategory-model="editingWorkflowSubcategory"
                description-model="editingWorkflowDescription"
                active-model="editingWorkflowActive"
                lock-model="editingWorkflowLocked"
                development-model="editingWorkflowDevelopment"
                :disabled="$editingWorkflowEffectiveLocked"
                :lock-disabled="$editingWorkflowIncluded"
                :lock-help="$editingWorkflowIncluded ? 'Automatisch gesperrt, weil dieser Workflow in einem anderen Workflow enthalten ist.' : ($editingWorkflowEffectiveLocked ? 'Haken entfernen und speichern, um den Workflow zu entsperren.' : 'Gesperrte Workflows bleiben fuer Admins im Manager bearbeitbar und werden dort mit einer Warnung markiert.')"
            />
        </x-slot>
        <x-slot name="footer">
            <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
            @if(! $editingWorkflowIncluded)
                <button type="button" wire:click="saveEditWorkflow" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">{{ $editingWorkflowEffectiveLocked ? 'Entsperren' : 'Speichern' }}</button>
            @endif
        </x-slot>
    </x-dialog-modal>

    <x-dialog-modal wire:model="showImportWorkflowModal" maxWidth="2xl">
        <x-slot name="title">Workflows importieren</x-slot>
        <x-slot name="content">
            <div class="space-y-4">
                <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    Vorhandene Workflows mit demselben Slug werden aktualisiert. Ihre Listen und Tasks werden durch den Stand aus der Importdatei ersetzt. Laufhistorien bleiben erhalten.
                </div>
                <div>
                    <label for="workflow-import-file" class="block text-sm font-medium text-slate-700">CSV- oder ZIP-Datei</label>
                    <input id="workflow-import-file" type="file" wire:model="workflowImportFile" accept=".csv,.zip,text/csv,application/zip" class="mt-1 block w-full rounded-md border border-slate-300 bg-white p-2 text-sm shadow-sm file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200">
                    <p class="mt-1 text-xs text-slate-500">Maximal 10 MB. ZIP-Dateien müssen eine Workflow-CSV enthalten.</p>
                    @error('workflowImportFile') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-slot>
        <x-slot name="footer">
            <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
            <button type="button" wire:click="importWorkflows" wire:loading.attr="disabled" wire:target="workflowImportFile,importWorkflows" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50">
                Importieren
            </button>
        </x-slot>
    </x-dialog-modal>
</div>
