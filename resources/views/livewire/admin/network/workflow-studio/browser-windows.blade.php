<section class="relative z-20 shrink-0 border-b border-slate-200 bg-white px-4 py-2.5 lg:px-6" data-studio-browser-windows>
    <div class="flex items-stretch gap-3 overflow-x-auto pb-0.5">
        <div class="flex w-28 shrink-0 flex-col justify-center">
            <p class="text-[9px] font-black uppercase tracking-[0.18em] text-cyan-700">Live-Sitzung</p>
            <p class="mt-1 text-[10px] leading-4 text-slate-500">Browserfenster oberhalb des Diagramms</p>
        </div>

        @foreach($browserWindows as $window)
            <article class="flex min-w-[23rem] max-w-[34rem] flex-1 items-center gap-3 rounded-xl border bg-slate-50 p-2 shadow-sm {{ $window['connected'] ? 'border-emerald-200' : 'border-slate-200' }}">
                <div class="min-w-0 flex-1 px-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="h-2 w-2 shrink-0 rounded-full {{ $window['connected'] ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                        <strong class="truncate text-xs text-slate-900">{{ $window['name'] }}</strong>
                        @if($window['active'])<span class="rounded bg-cyan-100 px-1.5 py-0.5 text-[8px] font-black uppercase tracking-wide text-cyan-800">aktiv</span>@endif
                    </div>
                    <p class="mt-1 truncate text-[10px] text-slate-500">{{ $window['title'] ?: ($window['url'] ?: 'Noch kein Browserzustand') }}</p>
                    <div class="mt-2 flex items-center gap-2">
                        <button type="button" wire:click="openToolModal('browser')" class="rounded-md border border-slate-200 bg-white px-2 py-1 text-[9px] font-bold text-slate-600 transition hover:border-cyan-300 hover:text-cyan-800">Öffnen</button>
                        @if(! $autonomousMode)
                            <button type="button" wire:click="openSelectorProbe(@js($window['name']))" class="rounded-md border border-cyan-200 bg-cyan-50 px-2 py-1 text-[9px] font-bold text-cyan-800 transition hover:bg-cyan-100">Selector prüfen</button>
                        @endif
                    </div>
                </div>

                <button type="button" wire:click="openToolModal('browser')" class="ml-auto h-20 w-36 shrink-0 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-inner transition hover:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-500" aria-label="Browserfenster {{ $window['name'] }} vergrößern">
                    @if(filled($window['screenshot_url'] ?? null))
                        <img src="{{ $window['screenshot_url'] }}" alt="Vorschau des Browserfensters {{ $window['name'] }}" class="h-full w-full object-contain">
                    @else
                        <span class="flex h-full w-full flex-col items-center justify-center gap-1 text-[9px] font-semibold text-slate-400">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M3 9h18M7 6.5h.01M10 6.5h.01"></path></svg>
                            Vorschau folgt
                        </span>
                    @endif
                </button>
            </article>
        @endforeach
    </div>
</section>
