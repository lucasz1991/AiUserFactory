<div
    wire:poll.5s="refreshClock"
    title="Laravel: {{ $timezone }} {{ $offset }} | PHP: {{ $phpTimezone }} | Config: {{ $configuredTimezone }} | UTC: {{ $utcTime }}"
    class="hidden items-center gap-2 rounded-full border border-slate-200 bg-slate-50/80 px-3 py-1 text-[11px] font-medium text-slate-500 shadow-sm md:flex"
>
    <i class="far fa-clock text-[11px] text-slate-400" aria-hidden="true"></i>
    <span class="hidden uppercase tracking-wide text-slate-400 xl:inline">Systemzeit</span>
    <span class="tabular-nums text-slate-700">{{ $serverTime }}</span>
    <span class="rounded-full bg-white px-1.5 py-0.5 text-[10px] text-slate-500">{{ $offset }}</span>
</div>
