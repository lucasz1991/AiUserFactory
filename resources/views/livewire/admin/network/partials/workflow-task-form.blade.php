@php
    $isEdit = ($mode ?? 'create') === 'edit';
    $prefix = $isEdit ? 'editingTask' : 'newTask';
@endphp

<div class="space-y-4">
    <div class="grid gap-4 md:grid-cols-2">
        @if(! $isEdit)
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
        @endif

        <div class="{{ $isEdit ? 'md:col-span-2' : '' }}">
            <label class="block text-sm font-medium text-gray-700">Funktion / Node-Skript</label>
            <select wire:model.defer="{{ $prefix }}CatalogKey" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @foreach($taskDefinitions as $taskDefinition)
                    <option value="{{ $taskDefinition['key'] }}">{{ $taskDefinition['label'] }} ({{ $taskDefinition['runner'] }})</option>
                @endforeach
            </select>
            @error($prefix.'CatalogKey') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Kartentitel</label>
            <input type="text" wire:model.defer="{{ $prefix }}Title" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
            @error($prefix.'Title') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Typ</label>
            <select wire:model.defer="{{ $prefix }}Kind" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <option value="browser">Browser</option>
                <option value="input">Input</option>
                <option value="wait">Warten</option>
                <option value="data">Daten</option>
            </select>
            @error($prefix.'Kind') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        @if($isEdit)
            <div>
                <label class="block text-sm font-medium text-gray-700">Timeout</label>
                <input type="number" min="0" max="3600" wire:model.defer="editingTaskTimeoutSeconds" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('editingTaskTimeoutSeconds') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endif
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700">Beschreibung</label>
        <textarea rows="3" wire:model.defer="{{ $prefix }}Description" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
        @error($prefix.'Description') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <div>
            <label class="block text-sm font-medium text-gray-700">Element-Selector</label>
            <input type="text" wire:model.defer="{{ $prefix }}ElementSelector" placeholder="button[type=submit], #login, text=Weiter" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
            @error($prefix.'ElementSelector') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Input-Selector</label>
            <input type="text" wire:model.defer="{{ $prefix }}InputSelector" placeholder="input[name=email]" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
            @error($prefix.'InputSelector') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Input-Wert / Quelle</label>
            <input type="text" wire:model.defer="{{ $prefix }}InputValue" placeholder="person.email oder fester Wert" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
            @error($prefix.'InputValue') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <div>
            <label class="block text-sm font-medium text-gray-700">Daten bei Erfolg</label>
            <textarea rows="3" wire:model.defer="{{ $prefix }}SuccessPayload" placeholder='{"email":"{{ "person.email" }}"} oder Textwert' class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
            @error($prefix.'SuccessPayload') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Daten bei Fehler</label>
            <textarea rows="3" wire:model.defer="{{ $prefix }}FailurePayload" placeholder='{"reason":"element_not_found"} oder Textwert' class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
            @error($prefix.'FailurePayload') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        @foreach([
            $prefix.'SuccessTarget' => 'Bei Erfolg',
            $prefix.'PartialTarget' => 'Bei Teilstatus',
            $prefix.'FailedTarget' => 'Bei Fehler',
        ] as $model => $label)
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ $label }}</label>
                <select wire:model.defer="{{ $model }}" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">{{ $label === 'Bei Erfolg' ? 'Naechste Karte' : 'Keine Route' }}</option>
                    <option value="end">Workflow beenden</option>
                    <option value="fail">Fehlerroute</option>
                    @foreach($steps as $targetStep)
                        <option value="step:{{ $targetStep->action_key }}">{{ $targetStep->name }}</option>
                        @foreach($targetStep->task_cards as $targetTask)
                            <option value="card:{{ $targetStep->id }}:{{ $targetTask['key'] ?? '' }}">Karte: {{ $targetStep->name }} / {{ $targetTask['title'] ?? 'Task' }}</option>
                        @endforeach
                    @endforeach
                </select>
                @error($model) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endforeach
    </div>
</div>
