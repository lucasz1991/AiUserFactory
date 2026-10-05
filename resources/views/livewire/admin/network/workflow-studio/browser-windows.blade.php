@php
    $orderedBrowserWindows = collect($browserWindows)
        ->sortByDesc(fn (array $window): int => ($window['active'] ?? false) ? 1 : 0)
        ->values();
    if ($orderedBrowserWindows->isEmpty()) {
        $orderedBrowserWindows = collect([['name' => 'main', 'title' => '', 'url' => '', 'runtime' => false, 'connected' => false, 'active' => true, 'screenshot_url' => null]]);
    }
@endphp

<section
    class="ff-browser-strip ff-browser-strip--compact ff-browser-strip--mini relative z-20 shrink-0 border-b px-3 py-1.5 sm:px-4"
    data-studio-browser-windows
    wire:key="studio-browser-windows-{{ $session->id }}"
    aria-label="Browserfenster"
>
    <div class="ff-studio-browser-mini-list" data-studio-browser-mini-list role="list" aria-label="Browser-Miniansichten">
        @foreach($orderedBrowserWindows as $window)
            @php
                $isPrimaryWindow = $loop->first;
                $windowConnected = $isActive && ! $historicalRunView
                    && (bool) ($window['runtime'] ?? false)
                    && (bool) ($window['connected'] ?? false);
                $windowStateLabel = $windowConnected
                    ? 'Verbunden'
                    : ($isActive && ! $historicalRunView
                        ? 'Wartet auf Browser'
                        : (($window['runtime'] ?? false) ? 'Letzte Vorschau' : 'Noch nicht geöffnet'));
                $hasScreenshot = filled($window['screenshot_url'] ?? null);
                $previewPlaceholder = $hasScreenshot
                    ? 'Vorschau wird geladen'
                    : ($isActive && ! $historicalRunView ? 'Vorschau folgt' : 'Noch keine Vorschau');
            @endphp
            <article
                wire:key="studio-browser-window-{{ $session->id }}-{{ $window['name'] }}"
                data-studio-browser-mini-window="{{ $window['name'] }}"
                data-active="{{ ($window['active'] ?? false) ? 'true' : 'false' }}"
                class="ff-studio-browser-mini-window"
                role="listitem"
            >
                <button
                    type="button"
                    wire:click="openToolModal('browser')"
                    @if($isPrimaryWindow) data-studio-browser-preview-trigger @endif
                    data-studio-browser-mini-preview="{{ $window['name'] }}"
                    data-has-screenshot="{{ $hasScreenshot ? 'true' : 'false' }}"
                    x-data="{ imageFailed: false, imageLoaded: false }"
                    x-bind:data-image-ready="imageLoaded && ! imageFailed ? 'true' : 'false'"
                    class="ff-studio-browser-mini-preview h-20 w-36"
                    aria-label="Browserfenster {{ $window['name'] }}: Vorschau öffnen"
                    title="Browserfenster {{ $window['name'] }} vergrößern"
                >
                    <span
                        data-studio-browser-mini-fallback
                        class="ff-studio-browser-mini-fallback"
                        x-text="imageFailed ? 'Vorschau nicht verfügbar' : @js($previewPlaceholder)"
                        aria-hidden="true"
                    >{{ $previewPlaceholder }}</span>
                    @if($hasScreenshot)
                        <img
                            data-studio-browser-mini-image
                            src="{{ $window['screenshot_url'] }}"
                            alt=""
                            aria-hidden="true"
                            loading="lazy"
                            decoding="async"
                            width="144"
                            height="80"
                            class="ff-studio-browser-mini-image"
                            x-bind:class="imageFailed ? 'opacity-0' : ''"
                            x-on:load="imageFailed = false; imageLoaded = true"
                            x-on:error="imageFailed = true; imageLoaded = false"
                        >
                    @endif
                </button>

                <div class="ff-studio-browser-mini-caption">
                    <div class="ff-studio-browser-label">
                        <strong @if($isPrimaryWindow) data-studio-active-browser-name @endif title="{{ $window['name'] }}">{{ $window['name'] }}</strong>
                        @if($window['active'] ?? false)
                            <span class="ff-studio-browser-mini-active">Aktiv</span>
                        @endif
                    </div>
                    @if(filled($window['workflow_path'] ?? null))
                        <p data-studio-browser-workflow-path class="ff-studio-browser-location" title="{{ $window['workflow_path'] }}">{{ $window['workflow_path'] }}</p>
                    @endif
                    <span @if($isPrimaryWindow) data-studio-active-browser-status @endif class="ff-studio-browser-state" data-connected="{{ $windowConnected ? 'true' : 'false' }}" role="status" aria-live="polite">
                        <span class="ff-studio-browser-state-dot" aria-hidden="true"></span>
                        {{ $windowStateLabel }}
                    </span>
                    <p class="ff-studio-browser-location" title="{{ $window['url'] ?: ($window['title'] ?: 'Browserfenster erscheint beim Teststart') }}">{{ $window['title'] ?: ($window['url'] ?: 'Browserfenster erscheint beim Teststart') }}</p>
                    @if($window['title'] && $window['url'])
                        <p class="ff-studio-browser-location ff-studio-browser-mini-url" title="{{ $window['url'] }}">{{ $window['url'] }}</p>
                    @endif
                </div>
                @if(! $autonomousMode)
                    <button type="button" wire:click="openSelectorProbe(@js($window['name']))" @disabled($historicalRunView || ! $isPaused) class="ff-studio-control ff-studio-control--icon ff-studio-browser-mini-probe" aria-label="Live-Probe im Browserfenster {{ $window['name'] }}" title="{{ $isPaused ? 'Live-Probe im Browserfenster '.$window['name'] : 'Für Live-Proben den Lauf zuerst manuell pausieren' }}">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M10 2v3m0 10v3M2 10h3m10 0h3" /><circle cx="10" cy="10" r="4" /></svg>
                    </button>
                @endif
            </article>
        @endforeach
    </div>
    <p data-studio-browser-description class="sr-only">Browser-Miniansichten zeigen die zuletzt verfügbare Vorschau. Ein Klick öffnet den Browserdialog. Weitere Fenster sind horizontal erreichbar.</p>
</section>
