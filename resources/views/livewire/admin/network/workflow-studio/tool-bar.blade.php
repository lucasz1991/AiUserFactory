@php
    $defaultBrowserWindow = data_get(collect($browserWindows)->firstWhere('active', true), 'name')
        ?: data_get($browserWindows, '0.name', 'main');
    $toolGroups = [
        [
            'key' => 'data',
            'label' => 'Laufdaten',
            'tools' => [
                ['browser', 'Browser', 'Fenster und Live-Bilder'],
                ['data', 'Daten', 'Lauf- und Loop-Zustand'],
                ['steps', 'Schritte', 'Listenübersicht'],
                ['tasks', 'Tasks', 'Taskübersicht'],
                ['variables', 'Variablen', 'Persistierte Werte'],
            ],
        ],
        [
            'key' => 'diagnostics',
            'label' => 'Diagnose',
            'tools' => [
                ['logs', 'Logs', 'Sitzungsereignisse'],
                ['debug', 'Debug', 'Probe und Fehlversuche'],
                ['artifacts', 'Artefakte', 'Screenshots und DOM'],
                ['checkpoints', 'Checkpoints', 'Wiederaufnahmepunkte'],
            ],
        ],
    ];
@endphp

<nav wire:key="studio-tool-dock-{{ $session->id }}" class="ff-tool-dock ff-studio-tools--compact relative z-20 shrink-0 border-b px-3 py-1 sm:px-4" aria-label="Workflow-Testwerkzeuge" data-workflow-studio-tool-bar>
    <div class="ff-studio-tools-row">
        @if(! $autonomousMode)
            <button type="button" wire:click="openSelectorProbe(@js($defaultBrowserWindow))" @disabled($historicalRunView) title="Selector im aktiven Browserfenster prüfen" class="ff-studio-control">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M10 2v3m0 10v3M2 10h3m10 0h3" /><circle cx="10" cy="10" r="4" /></svg>
                Selektoren
            </button>
        @endif

        @foreach($toolGroups as $toolGroup)
            <details data-studio-tool-group="{{ $toolGroup['key'] }}" wire:key="studio-tool-group-{{ $session->id }}-{{ $toolGroup['key'] }}" class="ff-studio-disclosure" x-data="{ open: false }" x-bind:open="open" x-on:toggle="open = $el.open" x-on:click.outside="open = false" x-on:keydown.escape.prevent.stop="open = false; $refs.summary.focus()">
                <summary x-ref="summary" class="ff-studio-control">
                    {{ $toolGroup['label'] }}
                    <svg class="ff-studio-disclosure-chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m6 8 4 4 4-4" /></svg>
                </summary>
                <div class="ff-studio-disclosure-menu">
                    @foreach($toolGroup['tools'] as [$tool, $label, $description])
                        <button type="button" wire:click="openToolModal('{{ $tool }}')" x-on:click="open = false" data-studio-tool-trigger="{{ $tool }}" title="{{ $description }}" class="ff-studio-control ff-studio-menu-action">{{ $label }}</button>
                    @endforeach
                    @if($toolGroup['key'] === 'diagnostics' && ! $autonomousMode)
                        <button type="button" wire:click="openSelectorProbe(@js($defaultBrowserWindow))" x-on:click="open = false" @disabled($historicalRunView || ! $isPaused) title="{{ $isPaused ? 'Echte Browseraktion im pausierten Lauf vorbereiten' : 'Für Live-Proben den Lauf zuerst manuell pausieren' }}" class="ff-studio-control ff-studio-menu-action">Live-Probe</button>
                    @endif
                </div>
            </details>
        @endforeach

        <button type="button" wire:click="$set('showCopilotSettingsModal', true)" data-studio-copilot-settings-trigger @disabled($historicalRunView) class="ff-studio-control ff-studio-copilot-settings" aria-label="Copilot-Einstellungen" title="Copilot-Einstellungen">Copilot<span class="ff-studio-settings-label">-Einstellungen</span></button>
    </div>
</nav>
