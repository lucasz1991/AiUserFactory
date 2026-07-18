@php
    $isEdit = ($mode ?? 'create') === 'edit';
    $prefix = $isEdit ? 'editingTask' : 'newTask';
    $catalogKey = $isEdit ? $editingTaskCatalogKey : $newTaskCatalogKey;
    $isLoopPairEdit = $isEdit && in_array($editingTaskLoopPairSegment ?? '', ['start', 'end'], true);
    $selectedDefinition = collect($taskDefinitions)->firstWhere('key', $catalogKey) ?? [];
    $documentation = is_array($selectedDefinition['documentation'] ?? null) ? $selectedDefinition['documentation'] : [];
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
        'value_source_control' => false,
        'value_label' => 'Wert',
        'value_placeholder' => 'person.email oder fester Wert',
        'value_help' => '',
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

    $extraFieldTabForField = function (array $field) use ($catalogKey): string {
        $name = trim((string) ($field['name'] ?? ''));

        if (str_starts_with($catalogKey, 'mail.')) {
            if ($catalogKey === 'mail.list_action_loop') {
                if (in_array($name, ['input_array_name', 'open_selector', 'open_wait_ms'], true)) {
                    return 'Quelle & Oeffnen';
                }

                if (in_array($name, ['confirm_selector', 'action_steps', 'return_selector'], true)) {
                    return 'Aktionen';
                }

                if (in_array($name, ['action_wait_ms', 'action_timeout_ms', 'max_items', 'continue_on_error'], true)) {
                    return 'Laufzeit';
                }
            }

            if (in_array($name, ['subject_selector', 'subject_filter', 'title_selector', 'title_filter', 'mail_ids'], true)) {
                return 'Filter';
            }

            if (in_array($name, ['date_selector', 'date_attribute', 'max_age_minutes', 'wait_for_new_mail_seconds'], true)) {
                return 'Datum & Warten';
            }

            if (in_array($name, ['input_array_name', 'search_fields'], true)) {
                return 'Quelle & Suche';
            }

            if (in_array($name, ['max_open_count', 'open_wait_ms', 'stop_on_first_match'], true)) {
                return 'Oeffnen';
            }

            if (in_array($name, ['regex'], true)) {
                return 'Wert ermitteln';
            }

            if (str_starts_with($name, 'output_') || in_array($name, ['max_items'], true)) {
                return 'Ergebnis';
            }
        }

        return trim((string) ($field['group'] ?? $field['tab'] ?? 'Einstellungen')) ?: 'Einstellungen';
    };

    $extraFieldGroups = collect(is_array($form['extra_fields'] ?? null) ? $form['extra_fields'] : [])
        ->filter(fn ($field) => is_array($field) && trim((string) ($field['name'] ?? '')) !== '')
        ->groupBy(fn ($field) => $extraFieldTabForField($field));

    if (str_starts_with($catalogKey, 'mail.')) {
        $preferredMailTabs = [
            'Quelle & Suche',
            'Quelle & Oeffnen',
            'Filter',
            'Datum & Warten',
            'Oeffnen',
            'Aktionen',
            'Laufzeit',
            'Wert ermitteln',
            'Ergebnis',
        ];

        $extraFieldGroups = $extraFieldGroups->sortBy(
            fn ($fields, $tabLabel) => array_search((string) $tabLabel, $preferredMailTabs, true) === false
                ? 99
                : array_search((string) $tabLabel, $preferredMailTabs, true)
        );
    }

    $taskSpecificTabLabels = collect();
    $inputTabLabel = str_starts_with($catalogKey, 'mail.') ? 'Quelle & Suche' : 'Eingabe';
    $runtimeTabLabel = 'Ausfuehrung';
    $payloadTabLabel = 'Daten';
    $taskSettingsTabIcons = [
        'Quelle & Suche' => 'fad fa-database',
        'Quelle & Oeffnen' => 'fad fa-envelope-open',
        'Filter' => 'fad fa-filter',
        'Datum & Warten' => 'fad fa-clock',
        'Oeffnen' => 'fad fa-envelope-open',
        'Wert ermitteln' => 'fad fa-magnifying-glass',
        'Ergebnis' => 'fad fa-check',
        'Ausfuehrung' => 'fad fa-play',
        'Eingabe' => 'fad fa-keyboard',
        'Eingaben' => 'fad fa-list-check',
        'Hover' => 'fad fa-computer-mouse',
        'Daten' => 'fad fa-code',
        'Session' => 'fad fa-cookie',
        'Loeschen' => 'fad fa-trash-can',
        'Aktionen' => 'fad fa-list-check',
        'Bedingung' => 'fad fa-code-branch',
    ];

    if ($form['selector'] || $form['value'] || $form['url']) {
        $taskSpecificTabLabels->push($inputTabLabel);
    }

    if ($form['browser_window'] || $isEdit || $form['timeout'] || $form['mailbox_source']) {
        $taskSpecificTabLabels->push($runtimeTabLabel);
    }

    $extraFieldGroups->keys()->each(fn ($tabLabel) => $taskSpecificTabLabels->push((string) $tabLabel));

    if ($form['success_payload'] || $form['failure_payload']) {
        $taskSpecificTabLabels->push($payloadTabLabel);
    }

    $taskSpecificTabIds = [];
    $taskSettingsTabOptions = [];

    foreach ($taskSpecificTabLabels->unique()->values() as $index => $tabLabel) {
        $tabLabel = (string) $tabLabel;
        $baseTabId = \Illuminate\Support\Str::slug($tabLabel) ?: 'tab-'.$index;
        $tabId = $baseTabId;
        $suffix = 2;

        while (in_array($tabId, $taskSpecificTabIds, true)) {
            $tabId = $baseTabId.'-'.$suffix;
            $suffix++;
        }

        $taskSpecificTabIds[$tabLabel] = $tabId;
        $taskSettingsTabOptions[$tabId] = $tabLabel;
    }

    $defaultExtraTab = (string) ($taskSpecificTabIds[$inputTabLabel] ?? array_key_first($taskSettingsTabOptions) ?? '');
    $extraFieldTabGroup = 'workflow-task-'.$prefix.'-'.(\Illuminate\Support\Str::slug((string) $catalogKey) ?: 'task').'-settings';
    $taskSettingsTabsKey = 'workflow-task-settings-tabs-'.$prefix.'-'.(\Illuminate\Support\Str::slug((string) $catalogKey) ?: 'task').'-'.md5(json_encode(array_keys($taskSettingsTabOptions)));
    $browserWindowDatalistId = 'workflow-'.$prefix.'-browser-windows';
    $valueSourceProperty = $prefix.'ValueSource';
@endphp

<div
    class="space-y-4"
    x-data="{
        failedTarget: @entangle($prefix.'FailedTarget').live,
        valueSource: @entangle($valueSourceProperty).live,
    }"
>
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
            <select wire:model.live="{{ $prefix }}CatalogKey" @if($isLoopPairEdit) disabled @endif class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-slate-100 disabled:text-slate-500">
                @foreach($taskDefinitions as $taskDefinition)
                    <option value="{{ $taskDefinition['key'] }}">{{ $taskDefinition['label'] }} ({{ $taskDefinition['runner'] }})</option>
                @endforeach
            </select>
            @error($prefix.'CatalogKey') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            @if($isLoopPairEdit)
                <p class="mt-2 rounded-md border border-emerald-200 bg-emerald-50 p-2 text-xs leading-5 text-emerald-900">
                    Loop-Start und Loop-Ende sind gekoppelt. Dieses Modal speichert beide Segmente gemeinsam.
                </p>
            @endif
            @if(($selectedDefinition['description'] ?? '') !== '')
                <p class="mt-2 text-xs leading-5 text-slate-500">{{ $selectedDefinition['description'] }}</p>
            @endif
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Kartentitel</label>
            <input type="text" wire:model.defer="{{ $prefix }}Title" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
            @error($prefix.'Title') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    @if($documentation !== [])
        <details class="group rounded-xl border border-cyan-200 bg-cyan-50/70 p-4">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-bold text-cyan-950">
                <span>Ausführliche Task-Erklärung</span>
                <span class="text-xs text-cyan-700 group-open:hidden">anzeigen</span>
                <span class="hidden text-xs text-cyan-700 group-open:inline">schließen</span>
            </summary>
            <div class="mt-4 grid gap-4 text-xs leading-5 text-slate-700 lg:grid-cols-2">
                <section>
                    <h4 class="font-bold text-slate-950">Wann und wofür?</h4>
                    <p class="mt-1">{{ $documentation['use_when'] ?? $documentation['purpose'] ?? '' }}</p>
                    @if(filled($documentation['workflow_role'] ?? null))
                        <p class="mt-2 rounded-lg bg-white/80 p-2.5"><strong>Rolle im Workflow:</strong> {{ $documentation['workflow_role'] }}</p>
                    @endif
                </section>
                <section>
                    <h4 class="font-bold text-slate-950">Ausführung</h4>
                    <p class="mt-1">{{ $documentation['execution'] ?? '' }}</p>
                </section>
                @if(! empty($documentation['outputs']))
                    <section>
                        <h4 class="font-bold text-slate-950">Ausgaben</h4>
                        <ul class="mt-1 list-disc space-y-1 pl-4">
                            @foreach($documentation['outputs'] as $output)
                                <li>{{ $output }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif
                @if(! empty($documentation['routing']))
                    <section>
                        <h4 class="font-bold text-slate-950">Weiterleitungen</h4>
                        <ul class="mt-1 list-disc space-y-1 pl-4">
                            @foreach($documentation['routing'] as $routeExplanation)
                                <li>{{ $routeExplanation }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif
                @if(! empty($documentation['important_notes']))
                    <section class="lg:col-span-2">
                        <h4 class="font-bold text-slate-950">Wichtige Hinweise</h4>
                        <ul class="mt-1 grid list-disc gap-x-6 gap-y-1 pl-4 lg:grid-cols-2">
                            @foreach($documentation['important_notes'] as $note)
                                <li>{{ $note }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>
        </details>
    @endif

    <div>
        <label class="block text-sm font-medium text-gray-700">Beschreibung</label>
        <textarea rows="3" wire:model.defer="{{ $prefix }}Description" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
        @error($prefix.'Description') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

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

    @if($taskSettingsTabOptions !== [])
        <x-ui.accordion.tabs
            wire:key="{{ $taskSettingsTabsKey }}"
            :tabs="$taskSettingsTabOptions"
            :default="$defaultExtraTab"
            :group="$extraFieldTabGroup"
            :persist="false"
            collapse-at="md"
        >
            @foreach($taskSettingsTabOptions as $tabId => $tabLabel)
                <x-ui.accordion.tab-panel
                    :for="$tabId"
                    :active="$defaultExtraTab"
                    :group="$extraFieldTabGroup"
                    panel-class="space-y-4 rounded-b-lg border border-slate-200 bg-white p-4"
                    icon="{{ $taskSettingsTabIcons[(string) $tabLabel] ?? 'fad fa-sliders' }}"
                >
                    @if($tabLabel === $inputTabLabel && ($form['selector'] || $form['value'] || $form['url']))
                        <div class="grid gap-4 {{ ($form['selector'] && ($form['value'] || $form['url'])) ? 'md:grid-cols-2' : '' }}">
                            @if($form['selector'])
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">{{ $form['selector_label'] }}</label>
                                    <input type="text" wire:model.defer="{{ $prefix }}ElementSelector" placeholder="{{ $form['selector_placeholder'] }}" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    @error($prefix.'ElementSelector') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                            @endif

                            @if($form['url'] || $form['value'])
                                <div
                                    @if($form['value_source_control'] ?? false)
                                        x-cloak
                                        x-show="String(valueSource || 'fixed') === 'fixed'"
                                    @endif
                                >
                                    <label class="block text-sm font-medium text-gray-700">{{ $form['url'] ? $form['url_label'] : $form['value_label'] }}</label>
                                    <input type="text" wire:model.defer="{{ $prefix }}InputValue" placeholder="{{ $form['url'] ? $form['url_placeholder'] : $form['value_placeholder'] }}" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    @if(! $form['url'] && ($form['value_help'] ?? '') !== '')
                                        <p class="mt-1 text-xs text-slate-500">{{ $form['value_help'] }}</p>
                                    @endif
                                    @error($prefix.'InputValue') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                            @endif
                        </div>
                    @endif

                    @if($tabLabel === $runtimeTabLabel)
                        <div class="grid gap-4 md:grid-cols-2">
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
                                <div>
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
                        </div>
                    @endif

                    @if($extraFieldGroups->has($tabLabel))
                        <div class="grid gap-4 md:grid-cols-2">
                            @foreach($extraFieldGroups->get($tabLabel) as $field)
                                @php
                                    $fieldName = (string) ($field['name'] ?? '');
                                    $fieldType = (string) ($field['type'] ?? 'text');
                                    $fieldLabel = (string) ($field['label'] ?? $fieldName);
                                    $fieldPlaceholder = (string) ($field['placeholder'] ?? '');
                                    $fieldHelp = (string) ($field['help'] ?? '');
                                    $fieldRows = max(2, (int) ($field['rows'] ?? 2));
                                    $fieldClass = ($field['span'] ?? '') === 'full' ? 'md:col-span-2' : '';
                                    $fieldOptions = is_array($field['options'] ?? null) ? $field['options'] : [];
                                    $visibleWhen = is_array($field['visible_when'] ?? null) ? $field['visible_when'] : [];
                                    $visibleWhenField = trim((string) ($visibleWhen['field'] ?? ''));
                                    $visibleWhenValue = (string) ($visibleWhen['equals'] ?? '');
                                    $dedicatedValueSourceModels = [
                                        'value_source' => $prefix.'ValueSource',
                                        'workflow_variable' => $prefix.'WorkflowVariable',
                                        'value_fallback' => $prefix.'ValueFallback',
                                    ];
                                    $fieldModel = ($form['value_source_control'] ?? false) && isset($dedicatedValueSourceModels[$fieldName])
                                        ? $dedicatedValueSourceModels[$fieldName]
                                        : $prefix.'Extra.'.$fieldName;
                                @endphp
                                @if($fieldName !== '')
                                    <div
                                        class="{{ $fieldClass }}"
                                        @if($visibleWhenField === 'value_source')
                                            x-cloak
                                            x-show="String(valueSource || 'fixed') === @js($visibleWhenValue)"
                                        @endif
                                    >
                                        <label class="block text-sm font-medium text-gray-700">{{ $fieldLabel }}</label>
                                        @if($fieldType === 'textarea')
                                            <textarea
                                                rows="{{ $fieldRows }}"
                                                wire:key="{{ $prefix }}-extra-{{ $catalogKey }}-{{ $fieldName }}"
                                                wire:model="{{ $fieldModel }}"
                                                placeholder="{{ $fieldPlaceholder }}"
                                                class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                            ></textarea>
                                        @elseif($fieldType === 'select')
                                            <select
                                                wire:key="{{ $prefix }}-extra-{{ $catalogKey }}-{{ $fieldName }}"
                                                wire:model.live="{{ $fieldModel }}"
                                                class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                            >
                                                @foreach($fieldOptions as $optionValue => $optionLabel)
                                                    <option value="{{ $optionValue }}">{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <input
                                                type="{{ $fieldType === 'number' ? 'number' : 'text' }}"
                                                wire:key="{{ $prefix }}-extra-{{ $catalogKey }}-{{ $fieldName }}"
                                                @if(isset($field['min'])) min="{{ $field['min'] }}" @endif
                                                @if(isset($field['max'])) max="{{ $field['max'] }}" @endif
                                                @if(isset($field['step'])) step="{{ $field['step'] }}" @endif
                                                wire:model="{{ $fieldModel }}"
                                                placeholder="{{ $fieldPlaceholder }}"
                                                class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                            >
                                        @endif
                                        @if($fieldHelp !== '')
                                            <p class="mt-1 text-xs text-slate-500">{{ $fieldHelp }}</p>
                                        @endif
                                        @error($fieldModel) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    @if($tabLabel === $payloadTabLabel && ($form['success_payload'] || $form['failure_payload']))
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
                </x-ui.accordion.tab-panel>
            @endforeach
        </x-ui.accordion.tabs>
    @endif
</div>
