@extends('layouts.master')

@section('content')
<div class="main-content group-data-[sidebar-size=sm]:ml-[70px]">
    <div class="page-content dark:bg-zinc-700">
        <div class="container-fluid px-[0.625rem]">
            <div class="space-y-6">
                <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <h1 class="text-2xl font-semibold text-gray-900">ClientController Übersicht</h1>
                    <p class="mt-2 text-sm text-gray-500">Zentrale Steuerung für Nodes, Geräte, Jobs, Rebind und Heartbeats.</p>
                </div>

                @if(session('success'))
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                        {{ session('success') }}
                    </div>
                @endif

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Nodes gesamt</p><p class="mt-2 text-2xl font-semibold">{{ $stats['nodes_total'] }}</p></div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Nodes online</p><p class="mt-2 text-2xl font-semibold text-emerald-700">{{ $stats['nodes_online'] }}</p></div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Geräte</p><p class="mt-2 text-2xl font-semibold">{{ $stats['devices_total'] }}</p></div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Jobs pending</p><p class="mt-2 text-2xl font-semibold text-amber-700">{{ $stats['jobs_pending'] }}</p></div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Targets</p><p class="mt-2 text-2xl font-semibold">{{ $stats['targets_total'] }}</p></div>
                </div>

                <form method="POST" action="{{ route('client-controller.settings.save') }}" class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm space-y-5">
                    @csrf
                    <h2 class="text-lg font-semibold text-gray-900">Server- und Sicherheits-Einstellungen</h2>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Primäre Server-Domain</label>
                            <input type="url" name="server_domain" value="{{ old('server_domain', $settings['server_domain'] ?? config('app.url')) }}" class="mt-1 w-full rounded-md border border-gray-300 p-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Fallback-Domain</label>
                            <input type="url" name="fallback_server_domain" value="{{ old('fallback_server_domain', $settings['fallback_server_domain'] ?? '') }}" class="mt-1 w-full rounded-md border border-gray-300 p-2 text-sm">
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Heartbeat-Intervall (Sek.)</label>
                            <input type="number" min="5" max="3600" name="default_heartbeat_interval_seconds" value="{{ old('default_heartbeat_interval_seconds', $settings['default_heartbeat_interval_seconds'] ?? 30) }}" class="mt-1 w-full rounded-md border border-gray-300 p-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Job-Timeout (Sek.)</label>
                            <input type="number" min="5" max="86400" name="default_job_timeout_seconds" value="{{ old('default_job_timeout_seconds', $settings['default_job_timeout_seconds'] ?? 180) }}" class="mt-1 w-full rounded-md border border-gray-300 p-2 text-sm">
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="require_signed_jobs" value="1" {{ old('require_signed_jobs', $settings['require_signed_jobs'] ?? true) ? 'checked' : '' }}>
                            Signierte Jobs erzwingen
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="allow_server_rebind" value="1" {{ old('allow_server_rebind', $settings['allow_server_rebind'] ?? true) ? 'checked' : '' }}>
                            Server-Rebind global erlauben
                        </label>
                    </div>

                    <div class="flex justify-end">
                        <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Einstellungen speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
