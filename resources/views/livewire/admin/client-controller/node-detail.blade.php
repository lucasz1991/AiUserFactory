<div class="main-content group-data-[sidebar-size=sm]:ml-[70px]" wire:poll.5s>
    <div class="page-content dark:bg-zinc-700">
        <div class="container-fluid px-[0.625rem] space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <div>
                    <a href="{{ route('client-controller.nodes.index') }}" class="text-sm text-blue-700 hover:underline">&larr; Nodes verwalten</a>
                    <h1 class="mt-2 text-2xl font-semibold text-gray-900">{{ $node->name }}</h1>
                    <p class="mt-1 break-all text-xs text-gray-500">{{ $node->node_uuid }}</p>
                </div>
                <span class="rounded-full px-3 py-1 text-sm font-semibold {{ $nodeIsOnline ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">
                    {{ $nodeIsOnline ? 'Online' : 'Offline' }}
                </span>
            </div>

            @if(session('success'))
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('success') }}</div>
            @endif
            @if(session('info'))
                <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">{{ session('info') }}</div>
            @endif
            @error('command')
                <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">{{ $message }}</div>
            @enderror

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-lg border border-gray-200 bg-white p-4"><div class="text-xs text-gray-500">Letzter Kontakt</div><div class="mt-1 text-sm font-semibold">{{ $node->last_seen_at?->timezone(config('app.timezone'))->format('d.m.Y H:i:s') ?? '-' }}</div></div>
                <div class="rounded-lg border border-gray-200 bg-white p-4"><div class="text-xs text-gray-500">Öffentliche IP</div><div class="mt-1 text-sm font-semibold">{{ $node->public_ip ?: '-' }}</div></div>
                <div class="rounded-lg border border-gray-200 bg-white p-4"><div class="text-xs text-gray-500">Betriebssystem</div><div class="mt-1 text-sm font-semibold">{{ trim(($node->os ?: '-').' '.($node->version ?: '')) }}</div></div>
                <div class="rounded-lg border border-gray-200 bg-white p-4"><div class="text-xs text-gray-500">Geräte</div><div class="mt-1 text-sm font-semibold">{{ $devices->count() }}</div></div>
                <div class="rounded-lg border border-gray-200 bg-white p-4"><div class="text-xs text-gray-500">Standort</div><div class="mt-1 text-sm font-semibold">{{ collect([$node->city, $node->country])->filter()->implode(', ') ?: '-' }}</div></div>
            </div>

            <div class="grid gap-6 xl:grid-cols-2">
                <form wire:submit="save" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm space-y-4">
                    <h2 class="text-lg font-semibold text-gray-900">Node konfigurieren</h2>
                    <div><label class="mb-1 block text-sm text-gray-700">Name</label><input wire:model="name" class="w-full rounded-md border border-gray-300 p-2 text-sm">@error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label class="mb-1 block text-sm text-gray-700">Server</label><input wire:model="currentServerDomain" class="w-full rounded-md border border-gray-300 p-2 text-sm">@error('currentServerDomain')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label class="mb-1 block text-sm text-gray-700">Status</label><select wire:model="status" class="w-full rounded-md border border-gray-300 p-2 text-sm"><option value="active">Aktiv</option><option value="paused">Pausiert</option><option value="disabled">Deaktiviert</option></select></div>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="allowServerRebind"> Server-Rebind erlauben</label>
                    <div class="flex flex-wrap gap-2"><button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white" wire:loading.attr="disabled">Speichern</button><button type="button" wire:click="regenerateApiKey" wire:confirm="API-Key wirklich erneuern?" class="rounded-md border border-amber-300 px-4 py-2 text-sm text-amber-800">API-Key erneuern</button></div>
                </form>

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Fernsteuerung und Tests</h2>
                    <p class="mt-1 text-sm text-gray-600">Befehle werden signiert und vom Autopilot des Nodes abgeholt.</p>
                    <div class="mt-4 grid gap-2 sm:grid-cols-2">
                        <button wire:click="queueCommand('ping')" class="rounded-md border border-blue-300 px-3 py-2 text-sm text-blue-800">Verbindung testen</button>
                        <button wire:click="queueCommand('node_diagnostics')" class="rounded-md border border-blue-300 px-3 py-2 text-sm text-blue-800">Diagnose starten</button>
                        <button wire:click="queueCommand('node_outbox_list')" class="rounded-md border border-blue-300 px-3 py-2 text-sm text-blue-800">Outbox abrufen</button>
                        <button wire:click="queueCommand('node_outbox_clear')" wire:confirm="Outbox auf dem Node vollständig leeren?" class="rounded-md border border-red-300 px-3 py-2 text-sm text-red-700">Outbox leeren</button>
                        <button wire:click="queueCommand('node_discover_devices')" class="rounded-md border border-blue-300 px-3 py-2 text-sm text-blue-800">Geräte suchen</button>
                        <button wire:click="queueCommand('node_sync')" class="rounded-md border border-emerald-300 px-3 py-2 text-sm text-emerald-800">Vollständig synchronisieren</button>
                    </div>
                    <div wire:loading class="mt-3 text-xs text-gray-500">Befehl wird eingeplant …</div>
                    <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-gray-500">Letzter Server</dt><dd class="break-all">{{ $node->last_successful_server_domain ?: '-' }}</dd></div>
                        <div><dt class="text-gray-500">Funktionen</dt><dd>{{ collect($node->capabilities_json)->filter()->keys()->implode(', ') ?: '-' }}</dd></div>
                    </dl>
                </section>
            </div>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm overflow-x-auto">
                <div class="p-5"><h2 class="text-lg font-semibold text-gray-900">Geräte am Node</h2></div>
                <table class="min-w-full text-sm"><thead class="bg-gray-50"><tr><th class="px-4 py-3 text-left">Name</th><th class="px-4 py-3 text-left">Plattform</th><th class="px-4 py-3 text-left">ADB / UUID</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Zuletzt gesehen</th></tr></thead><tbody class="divide-y divide-gray-100">
                    @forelse($devices as $device)<tr><td class="px-4 py-3">{{ $device->name }}</td><td class="px-4 py-3">{{ $device->platform ?: '-' }}</td><td class="px-4 py-3 text-xs">{{ $device->adb_serial ?: $device->device_uuid }}</td><td class="px-4 py-3">{{ $device->status }}</td><td class="px-4 py-3">{{ $device->last_seen_at?->timezone(config('app.timezone'))->format('d.m.Y H:i:s') ?? '-' }}</td></tr>@empty<tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Keine Geräte erkannt.</td></tr>@endforelse
                </tbody></table>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm overflow-x-auto">
                <div class="p-5"><h2 class="text-lg font-semibold text-gray-900">Letzte Remote-Jobs</h2></div>
                <table class="min-w-full text-sm"><thead class="bg-gray-50"><tr><th class="px-4 py-3 text-left">Typ</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Zeit</th><th class="px-4 py-3 text-left">Ergebnis / Fehler</th><th class="px-4 py-3 text-left">Aktion</th></tr></thead><tbody class="divide-y divide-gray-100">
                    @forelse($jobs as $job)<tr class="align-top"><td class="px-4 py-3 font-medium">{{ $job->type }}</td><td class="px-4 py-3">{{ $job->status }}</td><td class="px-4 py-3 whitespace-nowrap">{{ ($job->completed_at ?: $job->queued_at)?->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}</td><td class="max-w-xl px-4 py-3"><pre class="max-h-48 overflow-auto whitespace-pre-wrap text-xs">{{ $job->error_message ?: ($job->result_json ? json_encode($job->result_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '-') }}</pre></td><td class="px-4 py-3">@if(in_array($job->status, ['pending', 'dispatched'], true))<button wire:click="cancelJob({{ $job->id }})" class="text-xs text-red-700 hover:underline">Abbrechen</button>@endif</td></tr>@empty<tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Noch keine Jobs.</td></tr>@endforelse
                </tbody></table>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm overflow-x-auto">
                <div class="p-5"><h2 class="text-lg font-semibold text-gray-900">Heartbeat-Verlauf</h2></div>
                <table class="min-w-full text-sm"><thead class="bg-gray-50"><tr><th class="px-4 py-3 text-left">Empfangen</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Payload</th></tr></thead><tbody class="divide-y divide-gray-100">
                    @forelse($heartbeats as $heartbeat)<tr class="align-top"><td class="px-4 py-3 whitespace-nowrap">{{ $heartbeat->received_at?->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}</td><td class="px-4 py-3">{{ $heartbeat->status }}</td><td class="px-4 py-3"><pre class="max-h-32 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode($heartbeat->payload_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></td></tr>@empty<tr><td colspan="3" class="px-4 py-6 text-center text-gray-500">Noch keine Heartbeats.</td></tr>@endforelse
                </tbody></table>
            </section>
        </div>
    </div>
</div>
