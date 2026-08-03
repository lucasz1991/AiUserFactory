@php
    $defaultBrowserWindow = data_get(collect($browserWindows)->firstWhere('active', true), 'name')
        ?: data_get($browserWindows, '0.name', 'main');
    $toolGroups = [
        [
            'label' => 'Ansicht',
            'tools' => [
                ['browser', 'Browser', 'Fenster und Live-Bilder', 'bg-blue-500'],
            ],
        ],
        [
            'label' => 'Laufdaten',
            'tools' => [
                ['data', 'Daten', 'Lauf- und Loop-Zustand', 'bg-blue-500'],
                ['steps', 'Schritte', 'Listenübersicht', 'bg-slate-400'],
                ['tasks', 'Tasks', 'Taskübersicht', 'bg-slate-400'],
                ['variables', 'Variablen', 'Persistierte Werte', 'bg-blue-500'],
            ],
        ],
        [
            'label' => 'Diagnose',
            'tools' => [
                ['logs', 'Logs', 'Sitzungsereignisse', 'bg-amber-500'],
                ['debug', 'Debug', 'Probe und Fehlversuche', 'bg-amber-500'],
                ['artifacts', 'Artefakte', 'Screenshots und DOM', 'bg-amber-500'],
                ['checkpoints', 'Checkpoints', 'Wiederaufnahmepunkte', 'bg-slate-400'],
            ],
        ],
    ];
@endphp

<nav class="ff-tool-dock relative z-20 shrink-0 border-b px-4 py-2 lg:px-6" aria-label="Workflow-Testwerkzeuge" data-workflow-studio-tool-bar>
    <div class="ff-studio-commandbar flex items-stretch gap-2 overflow-x-auto pb-0.5">
        <div class="flex shrink-0 items-center pr-1">
            <div>
                <p class="ff-kicker">Werkzeuge</p>
                <p class="mt-0.5 whitespace-nowrap text-[10px] font-semibold text-slate-500">Prüfen & auswerten</p>
            </div>
        </div>

        @foreach($toolGroups as $toolGroup)
            <div class="flex shrink-0 items-center gap-1 rounded-xl border border-slate-200/80 bg-white p-1 shadow-sm" role="group" aria-label="{{ $toolGroup['label'] }}">
                <span class="px-1.5 text-[9px] font-black uppercase tracking-[0.14em] text-slate-500">{{ $toolGroup['label'] }}</span>
                @foreach($toolGroup['tools'] as [$tool, $label, $description, $dotClass])
                    <button
                        type="button"
                        wire:click="openToolModal('{{ $tool }}')"
                        data-studio-tool-trigger="{{ $tool }}"
                        title="{{ $description }}"
                        class="ff-tool-button inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border px-2.5 text-[10px] font-bold focus:outline-none disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <span class="h-1.5 w-1.5 rounded-full {{ $dotClass }}" aria-hidden="true"></span>
                        {{ $label }}
                    </button>
                @endforeach

                @if($toolGroup['label'] === 'Ansicht' && ! $autonomousMode)
                    <button
                        type="button"
                        wire:click="openSelectorProbe(@js($defaultBrowserWindow))"
                        @disabled($historicalRunView)
                        title="Selector im aktiven Browserfenster prüfen"
                        class="ff-tool-button inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border px-2.5 text-[10px] font-bold focus:outline-none disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <svg class="h-3.5 w-3.5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M12 3v4m0 10v4M3 12h4m10 0h4"></path>
                            <circle cx="12" cy="12" r="4"></circle>
                        </svg>
                        Selektoren
                    </button>
                @endif
            </div>
        @endforeach

        <button
            type="button"
            wire:click="$set('showCopilotSettingsModal', true)"
            data-studio-copilot-settings-trigger
            @disabled($historicalRunView)
            class="ff-action-trigger ff-action-trigger--primary ml-auto inline-flex h-10 shrink-0 items-center gap-2 px-3 text-[10px] font-bold focus:outline-none disabled:cursor-not-allowed disabled:opacity-40"
        >
            <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-md bg-white/10 px-1 text-[9px] font-black" aria-hidden="true">AI</span>
            Copilot-Einstellungen
        </button>
    </div>
</nav>
