@php
    $orderedBrowserWindows = collect($browserWindows)
        ->sortByDesc(fn (array $window): int => ($window['active'] ?? false) ? 1 : 0)
        ->values();
    $activeBrowserWindow = $orderedBrowserWindows->first() ?? ['name' => 'main', 'title' => '', 'url' => '', 'runtime' => false];
    $additionalBrowserWindows = $orderedBrowserWindows->skip(1);
    $browserConnected = $isActive && (bool) ($activeBrowserWindow['connected'] ?? false);
    $browserStateLabel = $browserConnected
        ? 'Verbunden'
        : ($isActive ? 'Wartet auf Browser' : (($activeBrowserWindow['runtime'] ?? false) ? 'Letzte Vorschau' : 'Noch nicht geöffnet'));
@endphp

<section
    class="ff-browser-strip ff-browser-strip--compact relative z-20 shrink-0 border-b px-3 py-1.5 sm:px-4"
    data-studio-browser-windows
    wire:key="studio-browser-windows-{{ $session->id }}"
    aria-label="Browserfenster"
>
    <div class="ff-studio-browser-row">
        <div class="ff-studio-browser-current">
            <svg class="ff-studio-browser-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                <path d="M3 9h18M7 6.5h.01M10 6.5h.01"></path>
            </svg>
            <div class="min-w-0">
                <div class="ff-studio-browser-label">
                    <strong data-studio-active-browser-name>{{ $activeBrowserWindow['name'] ?? 'Browser' }}</strong>
                    <span data-studio-active-browser-status class="ff-studio-browser-state" data-connected="{{ $browserConnected ? 'true' : 'false' }}" role="status" aria-live="polite">
                        <span class="ff-studio-browser-state-dot" aria-hidden="true"></span>
                        {{ $browserStateLabel }}
                    </span>
                </div>
                <p class="ff-studio-browser-location" title="{{ $activeBrowserWindow['url'] ?: ($activeBrowserWindow['title'] ?: 'Browserfenster erscheint beim Teststart') }}">
                    {{ $activeBrowserWindow['title'] ?: ($activeBrowserWindow['url'] ?: 'Browserfenster erscheint beim Teststart') }}
                </p>
            </div>
        </div>

        <div class="ff-studio-browser-actions">
            <button type="button" wire:click="openToolModal('browser')" data-studio-browser-preview-trigger class="ff-studio-control" aria-label="Browser-Vorschau öffnen">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 7V3h4m6 0h4v4m0 6v4h-4m-6 0H3v-4" /></svg>
                Vorschau
            </button>
            @if($additionalBrowserWindows->isNotEmpty())
                <details data-studio-additional-browser-windows wire:key="studio-additional-windows-{{ $session->id }}" class="ff-studio-disclosure" x-data="{ open: false }" x-bind:open="open" x-on:toggle="open = $el.open" x-on:click.outside="open = false" x-on:keydown.escape.prevent.stop="open = false; $refs.summary.focus()">
                    <summary x-ref="summary" class="ff-studio-control">
                        Weitere <span class="ff-studio-window-count">{{ $additionalBrowserWindows->count() }}</span>
                        <svg class="ff-studio-disclosure-chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m6 8 4 4 4-4" /></svg>
                    </summary>
                    <div class="ff-studio-disclosure-menu ff-studio-browser-menu">
                        @foreach($additionalBrowserWindows as $window)
                            <article wire:key="studio-browser-window-{{ $session->id }}-{{ $window['name'] }}" class="ff-studio-browser-list-item">
                                <div class="min-w-0 flex-1">
                                    <strong class="block truncate text-xs">{{ $window['name'] }}</strong>
                                    <p class="ff-studio-browser-location" title="{{ $window['url'] ?: ($window['title'] ?: 'Noch nicht geöffnet') }}">{{ $window['title'] ?: ($window['url'] ?: 'Noch nicht geöffnet') }}</p>
                                    <button type="button" wire:click="openToolModal('browser')" x-on:click="open = false" class="ff-studio-control ff-studio-menu-action" aria-label="Vorschau des Browserfensters {{ $window['name'] }} öffnen">Vorschau öffnen</button>
                                    @if(! $autonomousMode)
                                        <button type="button" wire:click="openSelectorProbe(@js($window['name']))" x-on:click="open = false" @disabled($historicalRunView || ! $isPaused) title="{{ $isPaused ? 'Echte Browseraktion im pausierten Lauf vorbereiten' : 'Für Live-Proben den Lauf zuerst manuell pausieren' }}" class="ff-studio-control ff-studio-menu-action">Live-Probe</button>
                                    @endif
                                </div>
                                <div class="ff-browser-preview h-20 w-36 shrink-0 overflow-hidden rounded-lg border" aria-hidden="true">
                                    @if(filled($window['screenshot_url'] ?? null))
                                        <img src="{{ $window['screenshot_url'] }}" alt="" loading="lazy" class="h-full w-full object-contain">
                                    @else
                                        <span class="ff-studio-browser-placeholder">Noch keine Vorschau</span>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                </details>
            @endif
        </div>
    </div>
    <p data-studio-browser-description class="sr-only">Aktives Browserfenster und letzte verfügbare Vorschau. Weitere Browserfenster stehen bei Bedarf im Menü.</p>
</section>
