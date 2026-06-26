@php
    $isEdit = ($mode ?? 'create') === 'edit';
    $prefix = $isEdit ? 'editingTask' : 'newTask';
    $catalogKey = $isEdit ? $editingTaskCatalogKey : $newTaskCatalogKey;
    $selectedDefinition = collect($taskDefinitions)->firstWhere('key', $catalogKey) ?? [];
    $usesBrowserWindow = in_array($selectedDefinition['kind'] ?? '', ['browser', 'input', 'wait'], true) && $catalogKey !== 'wait.seconds';
    $form = array_replace([
        'browser_window' => $usesBrowserWindow,
        'browser_window_label' => $catalogKey === 'browser.open' ? 'Fenstername' : 'Browserfenster',
        'browser_window_placeholder' => $catalogKey === 'browser.open' ? 'main, registrierung, webmail' : 'Fenster auswaehlen',
        'selector' => false,
        'selector_label' => 'Selector',
        'selector_placeholder' => 'button[type=submit], input[name=email], text=Weiter',
        'value' => false,
        'value_label' => 'Wert',
        'value_placeholder' => 'person.email oder fester Wert',
        'url' => false,
        'url_label' => 'URL',
        'url_placeholder' => 'https://example.test',
        'success_payload' => false,
        'failure_payload' => false,
    ], is_array($selectedDefinition['form'] ?? null) ? $selectedDefinition['form'] : []);
    $currentBrowserWindow = $isEdit ? ($editingTaskBrowserWindow ?? 'main') : ($newTaskBrowserWindow ?? 'main');
    $browserWindowOptions = collect(['main'])
        ->merge(collect($steps)->flatMap(fn ($step) => collect($step->task_cards ?? [])
            ->map(fn ($task) => trim((string) data_get($task, 'browser_window_name', data_get($task, 'browser_window', ''))))))
        ->merge([$currentBrowserWindow])
        ->filter()
        ->unique()
        ->values();
    $browserWindowDatalistId = 'workflow-'.$prefix.'-browser-windows';
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
            <select wire:model.live="{{ $prefix }}CatalogKey" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @foreach($taskDefinitions as $taskDefinition)
                    <option value="{{ $taskDefinition['key'] }}">{{ $taskDefinition['label'] }} ({{ $taskDefinition['runner'] }})</option>
                @endforeach
            </select>
            @error($prefix.'CatalogKey') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            @if(($selectedDefinition['description'] ?? '') !== '')
                <p class="mt-2 text-xs leading-5 text-slate-500">{{ $selectedDefinition['description'] }}</p>
            @endif
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Kartentitel</label>
            <input type="text" wire:model.defer="{{ $prefix }}Title" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
            @error($prefix.'Title') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        @if($isEdit)
            <div>
                <label class="block text-sm font-medium text-gray-700">Timeout</label>
                <input type="number" min="0" max="3600" wire:model.defer="editingTaskTimeoutSeconds" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('editingTaskTimeoutSeconds') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endif

        @if($form['browser_window'])
            <div class="{{ $isEdit ? '' : 'md:col-span-2' }}">
                <label class="block text-sm font-medium text-gray-700">{{ $form['browser_window_label'] }}</label>
                @if($catalogKey === 'browser.open')
                    <input
                        type="text"
                        list="{{ $browserWindowDatalistId }}"
                        wire:model.defer="{{ $prefix }}BrowserWindow"
                        placeholder="{{ $form['browser_window_placeholder'] }}"
                        class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                    >
                    <datalist id="{{ $browserWindowDatalistId }}">
                        @foreach($browserWindowOptions as $browserWindowOption)
                            <option value="{{ $browserWindowOption }}"></option>
                        @endforeach
                    </datalist>
                @else
                    <select wire:model.defer="{{ $prefix }}BrowserWindow" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @foreach($browserWindowOptions as $browserWindowOption)
                            <option value="{{ $browserWindowOption }}">{{ $browserWindowOption }}</option>
                        @endforeach
                    </select>
                @endif
                @error($prefix.'BrowserWindow') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endif
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700">Beschreibung</label>
        <textarea rows="3" wire:model.defer="{{ $prefix }}Description" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
        @error($prefix.'Description') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    @if($form['selector'] || $form['value'] || $form['url'])
        <div class="grid gap-4 {{ ($form['selector'] && ($form['value'] || $form['url'])) ? 'md:grid-cols-2' : '' }}">
            @if($form['selector'])
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $form['selector_label'] }}</label>
                    <input type="text" wire:model.defer="{{ $prefix }}ElementSelector" placeholder="{{ $form['selector_placeholder'] }}" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @error($prefix.'ElementSelector') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            @endif

            @if($form['url'] || $form['value'])
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $form['url'] ? $form['url_label'] : $form['value_label'] }}</label>
                    <input type="text" wire:model.defer="{{ $prefix }}InputValue" placeholder="{{ $form['url'] ? $form['url_placeholder'] : $form['value_placeholder'] }}" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @error($prefix.'InputValue') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>
    @endif

    @if($form['success_payload'] || $form['failure_payload'])
        <div class="grid gap-4 {{ ($form['success_payload'] && $form['failure_payload']) ? 'md:grid-cols-2' : '' }}">
            @if($form['success_payload'])
                <div>
                    <label class="block text-sm font-medium text-gray-700">Daten bei Erfolg</label>
                    <textarea rows="3" wire:model.defer="{{ $prefix }}SuccessPayload" placeholder='{"email":"person.email"} oder Textwert' class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                    @error($prefix.'SuccessPayload') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            @endif
            @if($form['failure_payload'])
                <div>
                    <label class="block text-sm font-medium text-gray-700">Daten bei Fehler</label>
                    <textarea rows="3" wire:model.defer="{{ $prefix }}FailurePayload" placeholder='{"reason":"element_not_found"} oder Textwert' class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                    @error($prefix.'FailurePayload') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        @foreach([
            $prefix.'SuccessTarget' => 'Bei Erfolg',
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
