<section class="relative z-20 shrink-0 border-b border-slate-200 bg-slate-50 px-4 py-2.5 lg:px-6" data-studio-browser-windows>
    <div class="flex items-center gap-3 overflow-x-auto pb-0.5">
        <div class="w-32 shrink-0">
            <p class="text-[9px] font-black uppercase tracking-[0.18em] text-cyan-700">Browserfenster</p>
            <p class="mt-1 text-[10px] text-slate-500">Live-Sitzung</p>
        </div>
        @foreach($browserWindows as $window)
            <article class="flex min-w-[270px] max-w-[420px] flex-1 items-center gap-3 rounded-xl border bg-white px-3 py-2 shadow-sm {{ $window['connected'] ? 'border-emerald-200' : 'border-slate-200' }}">
                <span class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-cyan-700 ring-1 ring-slate-200">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M3 9h18M7 6.5h.01M10 6.5h.01"></path></svg>
                    <span class="absolute -right-1 -top-1 h-2.5 w-2.5 rounded-full border-2 border-white {{ $window['connected'] ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2"><strong class="truncate text-xs text-slate-900">{{ $window['name'] }}</strong><span class="rounded bg-slate-100 px-1.5 py-0.5 text-[8px] font-bold text-slate-500">{{ $window['runtime'] ? 'live' : $window['task_count'].' Tasks' }}</span>@if($window['active'])<span class="rounded bg-cyan-100 px-1.5 py-0.5 text-[8px] font-black uppercase tracking-wide text-cyan-800">aktiv</span>@endif</div>
                    <p class="mt-1 truncate text-[10px] text-slate-500">{{ $window['title'] ?: ($window['url'] ?: 'Noch kein Browserzustand') }}</p>
                </div>
                @if(! $autonomousMode)
                    <button type="button" wire:click="openSelectorProbe(@js($window['name']))" class="shrink-0 rounded-lg border border-cyan-200 bg-cyan-50 px-2.5 py-1.5 text-[9px] font-bold text-cyan-800 transition hover:border-cyan-300 hover:bg-cyan-100">Selector prüfen</button>
                @endif
            </article>
        @endforeach
    </div>
</section>
