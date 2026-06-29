@php
    $isEdit = ($mode ?? 'create') === 'edit';
    $prefix = $isEdit ? 'editingTask' : 'newTask';
    $catalogKey = $isEdit ? $editingTaskCatalogKey : $newTaskCatalogKey;
    $selectedDefinition = collect($taskDefinitions)->firstWhere('key', $catalogKey) ?? [];
    $usesBrowserWindow = in_array($selectedDefinition['kind'] ?? '', ['browser', 'input', 'wait'], true) && $catalogKey !== 'wait.seconds';
    $form = array_replace([
        'browser_window' => $usesBrowserWindow,
        'browser_window_create' => false,
        'browser_window_label' => $catalogKey === 'browser.open' ? 'Fenstername' : 'Browserfenster',
        'browser_window_placeholder' => $catalogKey === 'browser.open' ? 'main, registrierung, webmail' : 'Fenster auswaehlen',
        'selector' => false,
        'selector_label' => 'Selector',
        'selector_placeholder' => 'button[type=submit], button:has(span:has-text("Login"))',
        'value' => false,
        'value_required' => true,
        'value_label' => 'Wert',
        'value_placeholder' => 'person.email oder fester Wert',
        'url' => false,
        'url_label' => 'URL',
        'url_placeholder' => 'https://example.test',
        'mailbox_source' => true,
        'mailbox_source_label' => 'Script-Bezugsperson',
        'mailbox_source_options' => [
            'person' => 'Bezugs-Person',
            'verification' => 'Haupt-Verifikationskonto',
        ],
        'success_payload' => false,
        'success_payload_label' => 'Daten bei Erfolg',
        'success_payload_placeholder' => '{"email":"person.email"} oder Textwert',
        'failure_payload' => false,
        'failure_payload_label' => 'Daten bei Fehler',
        'failure_payload_placeholder' => '{"reason":"element_not_found"} oder Textwert',
        'extra_fields' => [],
        'timeout' => false,
        'timeout_label' => 'Timeout in Sekunden',
        'timeout_help' => '',
        'timeout_min' => 0,
        'timeout_max' => 3600,
    ], is_array($selectedDefinition['form'] ?? null) ? $selectedDefinition['form'] : []);
    $currentBrowserWindow = $isEdit ? ($editingTaskBrowserWindow ?? 'main') : ($newTaskBrowserWindow ?? 'main');
    $browserWindowOptions = collect(['main'])
        ->merge(collect($steps)->flatMap(fn ($step) => collect($step->task_cards ?? [])
            ->map(fn ($task) => trim((string) data_get($task, 'browser_window_name', data_get($task, 'browser_window', ''))))))
        ->merge([$currentBrowserWindow])
        ->filter()
        ->unique()
        ->values();
    $activeBrowserWindowOptions = collect();

    foreach ($steps as $step) {
        foreach ($step->task_cards ?? [] as $task) {
            $taskWindowName = trim((string) data_get($task, 'browser_window_name', data_get($task, 'browser_window', 'main'))) ?: 'main';

            if (($task['task_key'] ?? '') === 'browser.open' && ! $activeBrowserWindowOptions->contains($taskWindowName)) {
                $activeBrowserWindowOptions->push($taskWindowName);
            }

            if (($task['task_key'] ?? '') === 'browser.close') {
                $activeBrowserWindowOptions = $activeBrowserWindowOptions
                    ->reject(fn ($activeWindowName) => $activeWindowName === $taskWindowName)
                    ->values();
            }
        }
    }

    $selectBrowserWindowOptions = ($catalogKey === 'browser.open' ? $browserWindowOptions : $activeBrowserWindowOptions)
        ->merge([$currentBrowserWindow])
        ->filter()
        ->unique()
        ->values();

    if ($selectBrowserWindowOptions->isEmpty()) {
        $selectBrowserWindowOptions = collect(['main']);
    }

    $extraFieldGroups = collect(is_array($form['extra_fields'] ?? null) ? $form['extra_fields'] : [])
        ->filter(fn ($field) => is_array($field) && trim((string) ($field['name'] ?? '')) !== '')
        ->groupBy(fn ($field) => trim((string) ($field['tab'] ?? 'Einstellungen')) ?: 'Einstellungen');
    $extraFieldTabs = $extraFieldGroups->keys()->values();
    $defaultExtraTab = (string) ($extraFieldTabs->first() ?? '');
    $browserWindowDatalistId = 'workflow-'.$prefix.'-browser-windows';
@endphp

<div class="space-y-4" x-data="{ failedTarget: @entangle($prefix.'FailedTarget').live, extraTab: @js($defaultExtraTab) }">
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

        @if($isEdit || $form['timeout'])
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ $form['timeout'] ? $form['timeout_label'] : 'Timeout in Sekunden' }}</label>
                <input type="number" min="{{ $form['timeout'] ? $form['timeout_min'] : 0 }}" max="{{ $form['timeout_max'] }}" wire:model.defer="{{ $prefix }}TimeoutSeconds" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @if($form['timeout'] && $form['timeout_help'] !== '')
                    <p class="mt-1 text-xs text-slate-500">{{ $form['timeout_help'] }}</p>
                @endif
                @error($prefix.'TimeoutSeconds') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endif

        @if($form['browser_window'])
            <div class="{{ $isEdit ? '' : 'md:col-span-2' }}">
                <label class="block text-sm font-medium text-gray-700">{{ $form['browser_window_label'] }}</label>
                @if($catalogKey === 'browser.open' || ($form['browser_window_create'] ?? false))
                    <input
                        type="text"
                        list="{{ $browserWindowDatalistId }}"
                        wire:model.defer="{{ $prefix }}BrowserWindow"
                        placeholder="{{ $form['browser_window_placeholder'] }}"
                        class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                    >
                    <datalist id="{{ $browserWindowDatalistId }}">
                        @foreach($selectBrowserWindowOptions as $browserWindowOption)
                            <option value="{{ $browserWindowOption }}"></option>
                        @endforeach
                    </datalist>
                @else
                    <select wire:model.defer="{{ $prefix }}BrowserWindow" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @foreach($selectBrowserWindowOptions as $browserWindowOption)
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

    @if($extraFieldGroups->isNotEmpty())
        <div class="space-y-4">
            @if($extraFieldTabs->count() > 1)
                <div class="flex flex-wrap gap-2 rounded-lg bg-slate-100 p-1">
                    @foreach($extraFieldTabs as $tabLabel)
                        <button
                            type="button"
                            x-on:click="extraTab = @js($tabLabel)"
                            class="rounded-md px-3 py-1.5 text-sm font-semibold transition"
                            x-bind:class="extraTab === @js($tabLabel) ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:bg-white/70 hover:text-slate-900'"
                        >
                            {{ $tabLabel }}
                        </button>
                    @endforeach
                </div>
            @endif

            @foreach($extraFieldGroups as $tabLabel => $fields)
                <div
                    class="grid gap-4 md:grid-cols-2"
                    @if($extraFieldTabs->count() > 1) x-show="extraTab === @js($tabLabel)" x-cloak @endif
                >
                    @foreach($fields as $field)
                        @php
                            $fieldName = (string) ($field['name'] ?? '');
                            $fieldType = (string) ($field['type'] ?? 'text');
                            $fieldLabel = (string) ($field['label'] ?? $fieldName);
                            $fieldPlaceholder = (string) ($field['placeholder'] ?? '');
                            $fieldHelp = (string) ($field['help'] ?? '');
                            $fieldRows = max(2, (int) ($field['rows'] ?? 2));
                            $fieldClass = ($field['span'] ?? '') === 'full' ? 'md:col-span-2' : '';
                        @endphp
                        @if($fieldName !== '')
                            <div class="{{ $fieldClass }}">
                                <label class="block text-sm font-medium text-gray-700">{{ $fieldLabel }}</label>
                                @if($fieldType === 'textarea')
                                    <textarea rows="{{ $fieldRows }}" wire:model.live.debounce.500ms="{{ $prefix }}Extra.{{ $fieldName }}" placeholder="{{ $fieldPlaceholder }}" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                                @else
                                    <input
                                        type="{{ $fieldType === 'number' ? 'number' : 'text' }}"
                                        @if(isset($field['min'])) min="{{ $field['min'] }}" @endif
                                        @if(isset($field['max'])) max="{{ $field['max'] }}" @endif
                                        @if(isset($field['step'])) step="{{ $field['step'] }}" @endif
                                        wire:model.live.debounce.500ms="{{ $prefix }}Extra.{{ $fieldName }}"
                                        placeholder="{{ $fieldPlaceholder }}"
                                        class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    >
                                @endif
                                @if($fieldHelp !== '')
                                    <p class="mt-1 text-xs text-slate-500">{{ $fieldHelp }}</p>
                                @endif
                                @error($prefix.'Extra.'.$fieldName) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    @if($form['mailbox_source'])
        <div>
            <label class="block text-sm font-medium text-gray-700">{{ $form['mailbox_source_label'] }}</label>
            <select data-workflow-task-mailbox-source="{{ $prefix }}" wire:model.change="{{ $prefix }}MailboxSource" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @foreach($form['mailbox_source_options'] as $mailboxSourceValue => $mailboxSourceLabel)
                    <option value="{{ $mailboxSourceValue }}">{{ $mailboxSourceLabel }}</option>
                @endforeach
            </select>
            @error($prefix.'MailboxSource') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
    @endif

    @if($form['success_payload'] || $form['failure_payload'])
        <div class="grid gap-4 {{ ($form['success_payload'] && $form['failure_payload']) ? 'md:grid-cols-2' : '' }}">
            @if($form['success_payload'])
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $form['success_payload_label'] }}</label>
                    <textarea rows="3" wire:model.defer="{{ $prefix }}SuccessPayload" placeholder='{{ $form['success_payload_placeholder'] }}' class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                    @error($prefix.'SuccessPayload') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            @endif
            @if($form['failure_payload'])
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $form['failure_payload_label'] }}</label>
                    <textarea rows="3" wire:model.defer="{{ $prefix }}FailurePayload" placeholder='{{ $form['failure_payload_placeholder'] }}' class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
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
                <select @if($model === $prefix.'FailedTarget') x-model="failedTarget" @else wire:model.defer="{{ $model }}" @endif class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">{{ $label === 'Bei Erfolg' ? 'Naechste Karte' : 'Keine Route' }}</option>
                    @if($label === 'Bei Fehler')
                        <option value="next">Naechste Karte</option>
                    @endif
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

    <div x-cloak x-show="String(failedTarget || '').startsWith('card:')" class="rounded-md border border-amber-200 bg-amber-50 p-3">
        <label class="block text-sm font-medium text-amber-900">Maximale Fehlerrueckspruenge</label>
        <input type="number" min="0" max="20" wire:model.defer="{{ $prefix }}FailedRetryLimit" class="mt-1 block w-full rounded-md border border-amber-300 bg-white p-2 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500">
        <p class="mt-1 text-xs text-amber-800">Wird nur angewendet, wenn der Fehlerweg zu dieser oder einer vorherigen Task-Karte zurueckfuehrt. 0 bedeutet unbegrenzt.</p>
        @error($prefix.'FailedRetryLimit') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
    </div>
</div>
