@php
    $orderedBrowserWindows = collect($browserWindows)
        ->sortByDesc(fn (array $window): int => ($window['active'] ?? false) ? 1 : 0)
        ->values();
    $connectedBrowserWindows = $orderedBrowserWindows->where('connected', true)->count();
@endphp

<section
    x-data="{ expanded: false }"
    class="ff-browser-strip relative z-20 shrink-0 border-b px-4 py-2.5 lg:px-6"
    data-studio-browser-windows
>
    <div class="mb-2 flex min-w-0 items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-600" aria-hidden="true">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                    <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                    <path d="M3 9h18M7 6.5h.01M10 6.5h.01"></path>
                </svg>
            </span>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <p class="ff-kicker">Live-Browser</p>
                    <span class="text-[10px] font-semibold text-slate-500">
                        {{ $orderedBrowserWindows->count() }} Fenster
                    </span>
                    <span class="inline-flex items-center gap-1 text-[10px] font-semibold {{ $connectedBrowserWindows > 0 ? 'text-emerald-700' : 'text-slate-500' }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $connectedBrowserWindows > 0 ? 'bg-emerald-500' : 'bg-slate-400' }}" aria-hidden="true"></span>
                        {{ $connectedBrowserWindows }} verbunden
                    </span>
                </div>
                <p class="mt-0.5 truncate text-[10px] text-slate-500">Aktives Fenster im Blick, weitere Browser bei Bedarf einblenden.</p>
            </div>
        </div>

        @if($orderedBrowserWindows->count() > 1)
            <button
                type="button"
                x-on:click="expanded = !expanded"
                x-bind:aria-expanded="expanded"
                class="ff-action-trigger inline-flex h-8 shrink-0 items-center gap-1.5 px-2.5 text-[10px] font-bold"
            >
                <span x-text="expanded ? 'Nur aktives Fenster' : 'Alle Fenster'"></span>
                <span class="inline-flex min-w-5 items-center justify-center rounded-md bg-slate-100 px-1 py-0.5 text-[9px] text-slate-600">{{ $orderedBrowserWindows->count() }}</span>
                <svg class="h-3.5 w-3.5 transition-transform duration-200 motion-reduce:transition-none" x-bind:class="expanded ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m6 9 6 6 6-6"></path>
                </svg>
            </button>
        @endif
    </div>

    <div class="ff-studio-commandbar flex items-stretch gap-2 overflow-x-auto pb-0.5">
        @forelse($orderedBrowserWindows as $window)
            <article
                @if(! $loop->first)
                    x-cloak
                    x-show="expanded"
                    x-transition:enter="transition duration-200 ease-out motion-reduce:transition-none"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition duration-150 ease-in motion-reduce:transition-none"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1"
                @endif
                class="ff-browser-card flex min-w-[20rem] max-w-[32rem] flex-1 items-center gap-3 border p-2 {{ $window['active'] ? 'ring-1 ring-blue-200' : '' }}"
            >
                <div class="min-w-0 flex-1 px-1">
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="relative flex h-2 w-2 shrink-0">
                            @if($window['connected'])
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-40 motion-reduce:animate-none" aria-hidden="true"></span>
                            @endif
                            <span class="relative inline-flex h-2 w-2 rounded-full {{ $window['connected'] ? 'bg-emerald-500' : 'bg-slate-400' }}" aria-hidden="true"></span>
                        </span>
                        <strong class="truncate text-xs text-slate-950">{{ $window['name'] }}</strong>
                        @if($window['active'])
                            <span class="rounded-md border border-blue-200 bg-blue-50 px-1.5 py-0.5 text-[8px] font-black uppercase tracking-wide text-blue-700">aktiv</span>
                        @endif
                    </div>

                    <p class="mt-1 truncate text-[10px] font-semibold text-slate-700" title="{{ $window['title'] ?: 'Noch kein Seitentitel' }}">
                        {{ $window['title'] ?: 'Noch kein Seitentitel' }}
                    </p>
                    <p class="mt-0.5 truncate font-mono text-[9px] text-slate-500" title="{{ $window['url'] ?: 'Noch keine URL' }}">
                        {{ $window['url'] ?: 'Noch keine URL' }}
                    </p>

                    <div class="mt-2 flex items-center gap-1.5">
                        <button type="button" wire:click="openToolModal('browser')" class="ff-tool-button inline-flex h-9 items-center rounded-md border px-2.5 text-[10px] font-bold">Öffnen</button>
                        @if(! $autonomousMode)
                            <button type="button" wire:click="openSelectorProbe(@js($window['name']))" class="ff-tool-button inline-flex h-9 items-center rounded-md border px-2.5 text-[10px] font-bold">Selector prüfen</button>
                        @endif
                    </div>
                </div>

                <button
                    type="button"
                    wire:click="openToolModal('browser')"
                    class="ml-auto h-20 w-36 shrink-0 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-inner transition duration-200 hover:-translate-y-0.5 hover:border-blue-300 focus:outline-none motion-reduce:transform-none motion-reduce:transition-none"
                    aria-label="Browserfenster {{ $window['name'] }} vergrößern"
                >
                    @if(filled($window['screenshot_url'] ?? null))
                        <img src="{{ $window['screenshot_url'] }}" alt="Vorschau des Browserfensters {{ $window['name'] }}" class="h-full w-full object-contain">
                    @else
                        <span class="flex h-full w-full flex-col items-center justify-center gap-1 bg-slate-50 text-[9px] font-semibold text-slate-400">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                                <path d="M3 9h18M7 6.5h.01M10 6.5h.01"></path>
                            </svg>
                            Vorschau folgt
                        </span>
                    @endif
                </button>
            </article>
        @empty
            <div class="flex min-h-20 w-full items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 text-xs font-semibold text-slate-500">
                Browserfenster erscheinen, sobald ein Testlauf eine Sitzung öffnet.
            </div>
        @endforelse
    </div>
</section>
