<div class="space-y-6" wire:loading.class="opacity-60 pointer-events-none">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('network.workflows') }}" class="text-sm font-semibold text-blue-700 hover:text-blue-900">Workflows</a>
                <span class="text-sm text-slate-400">/</span>
                <span class="text-sm text-slate-500">Management</span>
            </div>
            <h1 class="mt-2 text-2xl font-semibold text-gray-900">{{ $selectedWorkflow?->name ?? 'Workflow Management' }}</h1>
            <p class="mt-1 text-sm text-gray-500">
                Trello-Board: Workflow als Board, Aktionen als Listen, Step-Tasks als Karten mit Status-Verzweigungen.
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

    @if(! $selectedWorkflow)
        <x-admin.panel>
            <div class="text-sm text-gray-500">Dieser Workflow wurde nicht gefunden.</div>
        </x-admin.panel>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-admin.stat label="Aktionen" :value="$summary['actions']" tone="slate" />
            <x-admin.stat label="Listen" :value="$summary['lists']" tone="blue" />
            <x-admin.stat label="Step-Karten" :value="$summary['task_cards']" tone="amber" />
            <x-admin.stat label="Laeufe" :value="$summary['runs']" tone="emerald" />
        </div>

        <x-admin.panel title="Workflow">
            <x-slot name="actions">
                <button type="button" wire:click="saveWorkflow" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                    Speichern
                </button>
                <button type="button" wire:click="deleteWorkflow" wire:confirm="Workflow wirklich loeschen?" class="rounded-md border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">
                    Loeschen
                </button>
            </x-slot>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_180px]">
                <div>
                    <label for="workflow-name" class="block text-sm font-medium text-gray-700">Name</label>
                    <input id="workflow-name" type="text" wire:model.defer="workflowName" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('workflowName') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-end gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
                    <input type="checkbox" wire:model.defer="workflowActive" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                    Aktiv
                </label>

                <div class="lg:col-span-2">
                    <label for="workflow-description" class="block text-sm font-medium text-gray-700">Beschreibung</label>
                    <textarea id="workflow-description" rows="3" wire:model.defer="workflowDescription" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                    @error('workflowDescription') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-admin.panel>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div class="space-y-6">
                <x-admin.panel title="Board">
                    <div class="rounded-md border border-slate-200 bg-slate-100 p-4">
                        <div x-data x-sort="$wire.reorderStep($item, $position)" class="flex min-h-[520px] gap-4 overflow-x-auto pb-2">
                            @forelse($steps as $step)
                                <x-workflows.step-card :step="$step" x-sort:item="{{ $step->id }}" wire:key="workflow-step-{{ $step->id }}">
                                    <x-slot name="actions">
                                        <button type="button" wire:click="toggleStep({{ $step->id }})" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                                            {{ $step->is_enabled ? 'Pausieren' : 'Aktivieren' }}
                                        </button>
                                        <button type="button" wire:click="removeStep({{ $step->id }})" wire:confirm="Liste wirklich entfernen?" class="rounded-md border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 shadow-sm hover:bg-red-50">
                                            Entfernen
                                        </button>
                                    </x-slot>
                                </x-workflows.step-card>
                            @empty
                                <div class="rounded-md border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">
                                    Keine Listen. Fuege rechts eine Aktion/Liste hinzu.
                                </div>
                            @endforelse
                        </div>
                    </div>
                </x-admin.panel>

                <x-admin.panel title="Ausfuehrungs-Tasks">
                    <div wire:poll.10s>
                        <x-workflows.run-list :runs="$runs" />
                    </div>
                </x-admin.panel>
            </div>

            <div class="space-y-6">
                <x-admin.panel title="Ausfuehren">
                    <div class="space-y-4">
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
                        <button type="button" wire:click="runWorkflow" wire:loading.attr="disabled" class="w-full rounded-md bg-slate-900 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                            Workflow starten
                        </button>
                    </div>
                </x-admin.panel>

                <x-admin.panel title="Liste hinzufuegen">
                    <div class="space-y-4">
                        <div>
                            <label for="workflow-new-step-type" class="block text-sm font-medium text-gray-700">Aktion</label>
                            <select id="workflow-new-step-type" wire:model.live="newStepType" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="planned_action">Geplante Aktion</option>
                                <option value="mail_account_registration">E-Mail registrieren</option>
                                <option value="webmail_login">Webmail Login</option>
                                <option value="wait">Warten</option>
                            </select>
                        </div>
                        <div>
                            <label for="workflow-new-step-name" class="block text-sm font-medium text-gray-700">Listenname</label>
                            <input id="workflow-new-step-name" type="text" wire:model.defer="newStepName" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('newStepName') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="workflow-new-step-provider" class="block text-sm font-medium text-gray-700">Provider</label>
                                <select id="workflow-new-step-provider" wire:model.defer="newStepProvider" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="proton">Proton</option>
                                    <option value="gmx">GMX</option>
                                </select>
                                @error('newStepProvider') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="workflow-new-step-wait" class="block text-sm font-medium text-gray-700">Sekunden</label>
                                <input id="workflow-new-step-wait" type="number" min="0" max="3600" wire:model.defer="newStepWaitSeconds" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @error('newStepWaitSeconds') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <button type="button" wire:click="addStep" class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                            Liste hinzufuegen
                        </button>
                    </div>
                </x-admin.panel>

                <x-admin.panel title="Step-Karte hinzufuegen">
                    <div class="space-y-4">
                        <div>
                            <label for="workflow-new-task-list" class="block text-sm font-medium text-gray-700">Liste</label>
                            <select id="workflow-new-task-list" wire:model.defer="newTaskListId" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">Bitte waehlen</option>
                                @foreach($steps as $step)
                                    <option value="{{ $step->id }}">{{ $step->name }}</option>
                                @endforeach
                            </select>
                            @error('newTaskListId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="workflow-new-task-catalog" class="block text-sm font-medium text-gray-700">Funktion / Node-Skript</label>
                            <select id="workflow-new-task-catalog" wire:model.defer="newTaskCatalogKey" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @foreach($taskDefinitions as $taskDefinition)
                                    <option value="{{ $taskDefinition['key'] }}">{{ $taskDefinition['label'] }} ({{ $taskDefinition['runner'] }})</option>
                                @endforeach
                            </select>
                            @error('newTaskCatalogKey') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="workflow-new-task-title" class="block text-sm font-medium text-gray-700">Karte</label>
                            <input id="workflow-new-task-title" type="text" wire:model.defer="newTaskTitle" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('newTaskTitle') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="workflow-new-task-kind" class="block text-sm font-medium text-gray-700">Typ</label>
                            <select id="workflow-new-task-kind" wire:model.defer="newTaskKind" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="browser">Browser</option>
                                <option value="input">Input</option>
                                <option value="wait">Warten</option>
                                <option value="data">Daten</option>
                            </select>
                            @error('newTaskKind') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid gap-3">
                            <div>
                                <label for="workflow-new-task-success" class="block text-sm font-medium text-gray-700">Bei Erfolg</label>
                                <select id="workflow-new-task-success" wire:model.defer="newTaskSuccessTarget" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">Naechste Karte</option>
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
                            <div>
                                <label for="workflow-new-task-partial" class="block text-sm font-medium text-gray-700">Bei Teilstatus</label>
                                <select id="workflow-new-task-partial" wire:model.defer="newTaskPartialTarget" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
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
                            <div>
                                <label for="workflow-new-task-failed" class="block text-sm font-medium text-gray-700">Bei Fehler</label>
                                <select id="workflow-new-task-failed" wire:model.defer="newTaskFailedTarget" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">Keine Route</option>
                                    <option value="fail">Fehlerroute</option>
                                    <option value="end">Workflow beenden</option>
                                    @foreach($steps as $targetStep)
                                        <option value="step:{{ $targetStep->action_key }}">{{ $targetStep->name }}</option>
                                        @foreach($targetStep->task_cards as $targetTask)
                                            <option value="card:{{ $targetStep->id }}:{{ $targetTask['key'] ?? '' }}">Karte: {{ $targetStep->name }} / {{ $targetTask['title'] ?? 'Task' }}</option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label for="workflow-new-task-description" class="block text-sm font-medium text-gray-700">Beschreibung</label>
                            <textarea id="workflow-new-task-description" rows="2" wire:model.defer="newTaskDescription" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                            @error('newTaskDescription') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <button type="button" wire:click="addTaskCard" class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                            Karte hinzufuegen
                        </button>
                    </div>
                </x-admin.panel>

                <x-admin.panel title="Aktionsbibliothek">
                    <div class="space-y-4">
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

                    <div class="mt-5 space-y-3">
                        @forelse($actions as $action)
                            <x-workflows.action-template-card :action="$action" wire:key="workflow-action-template-{{ $action['id'] }}">
                                <x-slot name="actions">
                                    <button type="button" wire:click="addActionStep(@js($action['id']))" class="rounded-md border border-blue-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 shadow-sm hover:bg-blue-50">
                                        Hinzufuegen
                                    </button>
                                </x-slot>
                            </x-workflows.action-template-card>
                        @empty
                            <div class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500">
                                Keine geplanten Aktionen gefunden.
                            </div>
                        @endforelse
                    </div>
                </x-admin.panel>
            </div>
        </div>
    @endif
</div>
