<section class="relative z-20 shrink-0 border-b border-slate-200 bg-slate-900 px-4 py-2.5 text-white lg:px-6" data-studio-browser-windows>
    <div class="flex items-center gap-3 overflow-x-auto pb-0.5">
        <div class="w-32 shrink-0">
            <p class="text-[9px] font-black uppercase tracking-[0.18em] text-cyan-300">Browserfenster</p>
            <p class="mt-1 text-[10px] text-slate-400">Immer im Blick</p>
        </div>
        @foreach($browserWindows as $window)
            <article class="flex min-w-[270px] max-w-[420px] flex-1 items-center gap-3 rounded-xl border px-3 py-2 {{ $window['connected'] ? 'border-emerald-400/35 bg-emerald-400/10' : 'border-white/10 bg-white/5' }}">
                <span class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-950/60 text-cyan-300 ring-1 ring-white/10">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M3 9h18M7 6.5h.01M10 6.5h.01"></path></svg>
                    <span class="absolute -right-1 -top-1 h-2.5 w-2.5 rounded-full border-2 border-slate-900 {{ $window['connected'] ? 'bg-emerald-400' : 'bg-slate-500' }}"></span>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2"><strong class="truncate text-xs text-white">{{ $window['name'] }}</strong><span class="rounded-full bg-white/10 px-1.5 py-0.5 text-[8px] font-bold text-slate-300">{{ $window['runtime'] ? 'live' : $window['task_count'].' Tasks' }}</span>@if($window['active'])<span class="rounded-full bg-cyan-300 px-1.5 py-0.5 text-[8px] font-black uppercase tracking-wide text-slate-950">aktiv</span>@endif</div>
                    <p class="mt-1 truncate text-[10px] text-slate-400">{{ $window['title'] ?: ($window['url'] ?: 'Noch kein Browserzustand') }}</p>
                </div>
                <button type="button" wire:click="openSelectorProbe(@js($window['name']))" class="shrink-0 rounded-lg border border-cyan-300/20 bg-cyan-300/10 px-2.5 py-1.5 text-[9px] font-bold text-cyan-200 transition hover:border-cyan-300/50 hover:bg-cyan-300/20">Selector prüfen</button>
            </article>
        @endforeach
    </div>
</section>
