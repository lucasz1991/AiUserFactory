@php
    $windowStatus = is_array($windowStatus ?? null) ? $windowStatus : [];
    $isAlive = (bool) data_get($windowStatus, 'alive', false);
    $statusText = data_get($windowStatus, 'statusText', 'Noch kein Lebenszeichen');
    $heartbeatAt = data_get($windowStatus, 'heartbeatAt');
    $ageSeconds = data_get($windowStatus, 'ageSeconds');
    $heartbeatTimezone = trim((string) config('app.timezone', 'Europe/Berlin')) ?: 'Europe/Berlin';
    if ($heartbeatAt) {
        try {
            $heartbeatDate = \Illuminate\Support\Carbon::parse((string) $heartbeatAt);
            if (! is_numeric($ageSeconds)) {
                $ageSeconds = max(0, (int) $heartbeatDate->diffInSeconds(now()));
            }
            $heartbeatAt = $heartbeatDate
                ->setTimezone($heartbeatTimezone)
                ->format('d.m.Y H:i:s');
        } catch (\Throwable) {
            // Bereits lokalisierte Anzeige aus dem Workflow-Preview beibehalten.
        }
    }
    $stage = data_get($windowStatus, 'stage');
    $ageLabel = is_numeric($ageSeconds)
        ? ($ageSeconds < 60 ? $ageSeconds.'s alt' : floor($ageSeconds / 60).'m alt')
        : null;
@endphp

<div class="mt-1 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-[10px] font-medium normal-case tracking-normal text-slate-400">
    <span class="inline-flex items-center gap-1">
        <span class="relative flex h-2 w-2">
            @if($isAlive)
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60"></span>
            @endif
            <span class="relative inline-flex h-2 w-2 rounded-full {{ $isAlive ? 'bg-emerald-400' : 'bg-amber-400' }}"></span>
        </span>
        <span>{{ $statusText }}</span>
    </span>
    @if($ageLabel)
        <span class="text-slate-500">{{ $ageLabel }}</span>
    @endif
    @if($heartbeatAt)
        <span class="truncate text-slate-500">Heartbeat: {{ $heartbeatAt }} · {{ $heartbeatTimezone }}</span>
    @endif
    @if($stage)
        <span class="truncate text-slate-500">Schritt: {{ $stage }}</span>
    @endif
</div>
