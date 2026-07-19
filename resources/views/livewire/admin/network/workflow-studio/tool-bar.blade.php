@php
    $defaultBrowserWindow = data_get(collect($browserWindows)->firstWhere('active', true), 'name')
        ?: data_get($browserWindows, '0.name', 'main');
    $toolButtons = [
        ['browser', 'Browser', 'Fenster und Live-Bilder'],
        ['data', 'Daten', 'Lauf- und Loop-Zustand'],
        ['checkpoints', 'Checkpoints', 'Wiederaufnahmepunkte'],
        ['logs', 'Logs', 'Sitzungsereignisse'],
        ['debug', 'Debug', 'Probe und Fehlversuche'],
        ['steps', 'Schritte', 'Listenübersicht'],
        ['tasks', 'Tasks', 'Taskübersicht'],
        ['variables', 'Variablen', 'Persistierte Werte'],
        ['artifacts', 'Artefakte', 'Screenshots und DOM'],
    ];
@endphp

<nav class="relative z-20 shrink-0 border-b border-slate-200 bg-white px-4 py-2 lg:px-6" aria-label="Workflow-Testwerkzeuge" data-workflow-studio-tool-bar>
    <div class="flex items-center gap-2 overflow-x-auto pb-0.5">
        <span class="mr-1 shrink-0 text-[9px] font-black uppercase tracking-[0.18em] text-slate-400">Werkzeuge</span>
        @foreach($toolButtons as [$tool, $label, $description])
            <button
                type="button"
                wire:click="openToolModal('{{ $tool }}')"
                title="{{ $description }}"
                class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 text-[10px] font-bold text-slate-700 transition hover:border-cyan-300 hover:bg-cyan-50 hover:text-cyan-900 focus:outline-none focus:ring-2 focus:ring-cyan-500"
            >
                <span class="h-1.5 w-1.5 rounded-full {{ in_array($tool, ['browser', 'data', 'variables'], true) ? 'bg-cyan-500' : 'bg-slate-400' }}" aria-hidden="true"></span>
                {{ $label }}
            </button>
        @endforeach

        @if(! $autonomousMode)
            <button type="button" wire:click="openSelectorProbe(@js($defaultBrowserWindow))" title="Selector im aktiven Browserfenster prüfen" class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border border-cyan-200 bg-cyan-50 px-2.5 text-[10px] font-bold text-cyan-800 transition hover:border-cyan-400 hover:bg-cyan-100 focus:outline-none focus:ring-2 focus:ring-cyan-500"><span aria-hidden="true">⌖</span>Selektoren</button>
        @endif

        <span class="mx-1 h-5 w-px shrink-0 bg-slate-200" aria-hidden="true"></span>
        <button type="button" wire:click="$set('showCopilotSettingsModal', true)" class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg bg-cyan-700 px-3 text-[10px] font-bold text-white shadow-sm transition hover:bg-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:ring-offset-2"><span aria-hidden="true">AI</span>Copilot-Einstellungen</button>
    </div>
</nav>
