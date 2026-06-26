<div class="space-y-6" wire:loading.class="opacity-60 pointer-events-none">
    <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('network.workflows') }}" class="text-sm font-semibold text-blue-700 hover:text-blue-900">Workflows</a>
                    <span class="text-sm text-slate-400">/</span>
                    <span class="text-sm text-slate-500">Management</span>
                </div>
                <h1 class="mt-2 text-2xl font-semibold text-gray-900">{{ $selectedWorkflow?->name ?? 'Workflow Management' }}</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Workflow als Prozessablauf: Aufgaben als Listen, Tasks als Karten, Verzweigungen nach Ergebnisstatus.
                </p>
            </div>

            @if($selectedWorkflow)
                <div class="flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        wire:click="openLatestRunPreview"
                        @disabled(! $quickPreviewRun)
                        class="rounded-md border border-indigo-200 bg-white px-3 py-2 text-sm font-semibold text-indigo-700 shadow-sm hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ $quickPreviewRun && in_array($quickPreviewRun->status, ['queued', 'running', 'waiting'], true) ? 'Laufenden Test öffnen' : 'Letzten Test öffnen' }}
                    </button>
                    <button type="button" wire:click="$set('showWorkflowModal', true)" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                        Workflow
                    </button>
                    <button type="button" wire:click="$set('showRunModal', true)" class="rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                        Testen
                    </button>
                    <button type="button" wire:click="$set('showAddStepModal', true)" class="rounded-md border border-blue-200 bg-white px-3 py-2 text-sm font-semibold text-blue-700 shadow-sm hover:bg-blue-50">
                        Liste
                    </button>
                    <button type="button" wire:click="$set('showTaskPanel', true)" class="rounded-md border border-emerald-200 bg-white px-3 py-2 text-sm font-semibold text-emerald-700 shadow-sm hover:bg-emerald-50">
                        Tasks
                    </button>
                    <button type="button" wire:click="$set('showActionLibraryModal', true)" class="rounded-md border border-amber-200 bg-white px-3 py-2 text-sm font-semibold text-amber-700 shadow-sm hover:bg-amber-50">
                        Aktionen
                    </button>
                    <a href="{{ route('processes.index') }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                        Prozesse
                    </a>
                    <button type="button" wire:click="deleteWorkflow" wire:confirm="Workflow samt Aufgaben, Tasks und Ausfuehrungen wirklich loeschen?" class="rounded-md border border-red-300 bg-white px-3 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">
                        Loeschen
                    </button>
                </div>
            @endif
        </div>
    </div>

    @if (session()->has('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    @if(! $selectedWorkflow)
        <x-admin.panel>
            <div class="text-sm text-gray-500">Dieser Workflow wurde nicht gefunden.</div>
        </x-admin.panel>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-admin.stat label="Aufgaben" :value="$summary['actions']" tone="slate" />
            <x-admin.stat label="Listen" :value="$summary['lists']" tone="blue" />
            <x-admin.stat label="Tasks" :value="$summary['task_cards']" tone="amber" />
            <x-admin.stat label="Laeufe" :value="$summary['runs']" tone="emerald" />
        </div>

        <x-admin.panel title="Board">
            <div
                x-data="{ focusedTask: '' }"
                class="relative max-h-[calc(100vh-220px)] overflow-auto rounded-md border border-[#075985] bg-[#0079bf] p-4 shadow-sm"
            >
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-white">{{ $selectedWorkflow->name }}</p>
                        <p class="text-xs text-blue-100">Tasks aus dem rechten Panel auf eine Liste ziehen.</p>
                    </div>
                    <button type="button" wire:click="$set('showTaskPanel', true)" class="rounded-md bg-white/95 px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-white">
                        Task-Bibliothek
                    </button>
                </div>

                <div x-sort="$dispatch('reorderWorkflowSteps', { item: $item, position: $position })" class="flex min-h-[560px] items-start gap-0 pb-2">
                    @forelse($steps as $step)
                        <div class="flex items-start" x-sort:item="{{ $step->id }}" wire:key="workflow-step-wrap-{{ $step->id }}">
                            <x-workflows.step-card :step="$step" wire:key="workflow-step-{{ $step->id }}">
                                <x-slot name="actions">
                                    <button type="button" wire:click="openEditStep({{ $step->id }})" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                        Bearbeiten
                                    </button>
                                    <button type="button" wire:click="toggleStep({{ $step->id }})" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                        {{ $step->is_enabled ? 'Pausieren' : 'Aktivieren' }}
                                    </button>
                                    <button type="button" wire:click="removeStep({{ $step->id }})" wire:confirm="Liste samt Tasks wirklich entfernen?" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-red-700 hover:bg-red-50">
                                        Entfernen
                                    </button>
                                </x-slot>
                            </x-workflows.step-card>
                            @if(! $loop->last)
                                <div class="flex h-24 w-10 shrink-0 items-center px-2">
                                    <div class="h-px flex-1 bg-white/50"></div>
                                    <div class="h-0 w-0 border-y-4 border-l-8 border-y-transparent border-l-white/70"></div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-md border border-dashed border-white/40 bg-white/90 p-6 text-center text-sm text-slate-600">
                            Keine Listen. Nutze oben den Button "Liste".
                        </div>
                    @endforelse

                    <button
                        type="button"
                        wire:click="$set('showAddStepModal', true)"
                        class="flex min-h-[220px] w-[260px] shrink-0 items-start rounded-md border border-dashed border-white/45 bg-transparent p-3 text-left text-sm font-semibold text-blue-50 transition hover:border-white hover:bg-white/10 hover:text-white"
                    >
                        + Neue Liste rechts anlegen
                    </button>
                </div>

                @if($showTaskPanel)
                    <div class="fixed inset-y-0 right-0 z-40 flex w-full max-w-sm flex-col border-l border-slate-200 bg-white shadow-2xl">
                        <div class="flex items-start justify-between gap-3 border-b border-slate-200 p-4">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900">Task-Bibliothek</h2>
                                <p class="mt-1 text-xs text-slate-500">Task auf eine Liste ziehen, danach oeffnet sich das Formular.</p>
                            </div>
                            <button type="button" wire:click="$set('showTaskPanel', false)" class="flex h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900">
                                x
                            </button>
                        </div>
                        <div class="border-b border-slate-200 px-4">
                            <nav class="-mb-px flex gap-4 overflow-x-auto" aria-label="Task Gruppen">
                                @foreach($taskGroups as $taskGroup)
                                    <button type="button" wire:click="$set('activeTaskGroup', @js($taskGroup))" class="whitespace-nowrap border-b-2 py-3 text-sm font-semibold {{ $activeTaskGroup === $taskGroup ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}">
                                        {{ $taskGroupLabels[$taskGroup] ?? $taskGroup }}
                                        <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ collect($taskDefinitions)->where('kind', $taskGroup)->count() }}</span>
                                    </button>
                                @endforeach
                            </nav>
                        </div>
                        <div class="flex-1 space-y-3 overflow-y-auto p-4">
                            @foreach($visibleTaskDefinitions as $taskDefinition)
                                <div
                                    draggable="true"
                                    x-on:dragstart="$event.dataTransfer.setData('application/x-workflow-task-catalog', @js($taskDefinition['key'])); $event.dataTransfer.setData('text/plain', @js($taskDefinition['key'])); $event.dataTransfer.effectAllowed = 'copy'"
                                    class="cursor-grab rounded-md border border-slate-200 bg-white p-3 shadow-sm transition hover:border-blue-300 hover:shadow-md active:cursor-grabbing"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <p class="text-sm font-semibold text-slate-900">{{ $taskDefinition['label'] }}</p>
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">{{ $taskDefinition['kind'] }}</span>
                                    </div>
                                    <p class="mt-2 line-clamp-2 text-xs leading-5 text-slate-500">{{ $taskDefinition['description'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </x-admin.panel>

        <x-dialog-modal wire:model="showWorkflowModal" maxWidth="2xl">
            <x-slot name="title">Workflow bearbeiten</x-slot>
            <x-slot name="content">
                <x-workflows.workflow-form
                    name-model="workflowName"
                    group-model="workflowGroup"
                    description-model="workflowDescription"
                    active-model="workflowActive"
                />
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="saveWorkflow" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">Speichern</button>
            </x-slot>
        </x-dialog-modal>

        <x-dialog-modal wire:model="showRunModal" maxWidth="xl">
            <x-slot name="title">Workflow testen</x-slot>
            <x-slot name="content">
                <div>
                    <label for="workflow-run-person" class="block text-sm font-medium text-gray-700">Person</label>
                    <select id="workflow-run-person" wire:model.defer="runPersonId" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">Keine Person binden</option>
                        @foreach($persons as $person)
                            <option value="{{ $person->id }}">{{ $person->display_name }} - {{ $person->profile_key }}</option>
                        @endforeach
                    </select>
                    @error('runPersonId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="runWorkflow" wire:loading.attr="disabled" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-60">Testen</button>
            </x-slot>
        </x-dialog-modal>

        <x-dialog-modal wire:model="showRunPreviewModal" maxWidth="6xl">
            <x-slot name="title">Workflow-Vorschau</x-slot>
            <x-slot name="content">
                <div @if($showRunPreviewModal) wire:poll.3s @endif>
                    @if($previewWorkflowRun)
                        <x-workflows.run-preview :workflow-run="$previewWorkflowRun" />
                    @else
                        <div class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                            Dieser Workflow-Lauf wurde noch nicht geladen.
                        </div>
                    @endif
                </div>
            </x-slot>
            <x-slot name="footer">
                <button type="button" wire:click="closeRunPreview" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                    Schliessen
                </button>
            </x-slot>
        </x-dialog-modal>

        <x-dialog-modal wire:model="showAddStepModal" maxWidth="2xl">
            <x-slot name="title">Liste / Aufgabe hinzufuegen</x-slot>
            <x-slot name="content">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="workflow-new-step-type" class="block text-sm font-medium text-gray-700">Aufgabentyp</label>
                        <select id="workflow-new-step-type" wire:model.live="newStepType" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="preparation">Vorbereitung</option>
                            <option value="data_processing">Daten verarbeiten</option>
                            <option value="browser_control">Browsersteuerung</option>
                            <option value="interaction">Interaktion</option>
                            <option value="decision">Status pruefen</option>
                            <option value="cleanup">Abschluss</option>
                        </select>
                    </div>
                    <div>
                        <label for="workflow-new-step-name" class="block text-sm font-medium text-gray-700">Listenname</label>
                        <input id="workflow-new-step-name" type="text" wire:model.defer="newStepName" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('newStepName') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="addStep" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Hinzufuegen</button>
            </x-slot>
        </x-dialog-modal>

        <x-dialog-modal wire:model="showEditStepModal" maxWidth="2xl">
            <x-slot name="title">Liste / Aufgabe bearbeiten</x-slot>
            <x-slot name="content">
                <div class="space-y-4">
                    <div>
                        <label for="workflow-edit-step-name" class="block text-sm font-medium text-gray-700">Name</label>
                        <input id="workflow-edit-step-name" type="text" wire:model.defer="editingStepName" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('editingStepName') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="workflow-edit-step-description" class="block text-sm font-medium text-gray-700">Beschreibung</label>
                        <textarea id="workflow-edit-step-description" rows="3" wire:model.defer="editingStepDescription" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
                            <input type="checkbox" wire:model.defer="editingStepEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                            Aktiv
                        </label>
                        <div>
                            <label for="workflow-edit-step-wait" class="block text-sm font-medium text-gray-700">Pause danach</label>
                            <input id="workflow-edit-step-wait" type="number" min="0" max="3600" wire:model.defer="editingStepWaitAfterSeconds" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach([
                            'editingStepSuccessTarget' => 'Bei Erfolg',
                            'editingStepFailedTarget' => 'Bei Fehler',
                        ] as $model => $label)
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ $label }}</label>
                                <select wire:model.defer="{{ $model }}" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">Keine Route</option>
                                    <option value="end">Workflow beenden</option>
                                    <option value="fail">Fehlerroute</option>
                                    @foreach($steps as $targetStep)
                                        <option value="step:{{ $targetStep->action_key }}">{{ $targetStep->name }}</option>
                                        @foreach($targetStep->task_cards as $targetTask)
                                            <option value="card:{{ $targetStep->id }}:{{ $targetTask['key'] ?? '' }}">Karte: {{ $targetStep->name }} / {{ $targetTask['title'] ?? 'Task' }}</option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="saveEditStep" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">Speichern</button>
            </x-slot>
        </x-dialog-modal>

        <x-dialog-modal wire:model="showAddTaskModal" maxWidth="3xl">
            <x-slot name="title">Step-Karte hinzufuegen</x-slot>
            <x-slot name="content">
                @include('livewire.admin.network.partials.workflow-task-form', [
                    'mode' => 'create',
                    'steps' => $steps,
                    'taskDefinitions' => $taskDefinitions,
                ])
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="addTaskCard" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Hinzufuegen</button>
            </x-slot>
        </x-dialog-modal>

        <x-dialog-modal wire:model="showEditTaskModal" maxWidth="3xl">
            <x-slot name="title">Step-Karte bearbeiten</x-slot>
            <x-slot name="content">
                @include('livewire.admin.network.partials.workflow-task-form', [
                    'mode' => 'edit',
                    'steps' => $steps,
                    'taskDefinitions' => $taskDefinitions,
                ])
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="saveEditTaskCard" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">Speichern</button>
            </x-slot>
        </x-dialog-modal>

        <x-dialog-modal wire:model="showActionLibraryModal" maxWidth="5xl">
            <x-slot name="title">Aktionsbibliothek</x-slot>
            <x-slot name="content">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="workflow-action-person" class="block text-sm font-medium text-gray-700">Person</label>
                        <select id="workflow-action-person" wire:model.live="actionPersonFilter" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">Alle Personen</option>
                            @foreach($personOptions as $person)
                                <option value="{{ $person['id'] }}">{{ $person['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="workflow-action-type" class="block text-sm font-medium text-gray-700">Typ</label>
                        <select id="workflow-action-type" wire:model.live="actionTypeFilter" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="all">Alle Aktionen</option>
                            <option value="step">Session-Schritte</option>
                            <option value="content">Content</option>
                        </select>
                    </div>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-2">
                    @forelse($actions as $action)
                        <x-workflows.action-template-card :action="$action" wire:key="workflow-action-template-{{ $action['id'] }}">
                            <x-slot name="actions">
                                <button type="button" wire:click="addActionStep(@js($action['id']))" class="rounded-md border border-blue-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 shadow-sm hover:bg-blue-50">
                                    Hinzufuegen
                                </button>
                            </x-slot>
                        </x-workflows.action-template-card>
                    @empty
                        <div class="md:col-span-2 rounded-md border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500">
                            Keine geplanten Aktionen gefunden.
                        </div>
                    @endforelse
                </div>
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Schliessen</button>
            </x-slot>
        </x-dialog-modal>
    @endif
</div>
