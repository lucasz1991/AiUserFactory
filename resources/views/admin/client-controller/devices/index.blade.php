@extends('layouts.master')

@section('content')
<div class="main-content group-data-[sidebar-size=sm]:ml-[70px]">
    <div class="page-content dark:bg-zinc-700">
        <div class="container-fluid px-[0.625rem] space-y-6">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm"><h1 class="text-2xl font-semibold text-gray-900">Geräte verwalten</h1></div>

            @if(session('success'))
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('success') }}</div>
            @endif

            <form method="POST" action="{{ route('client-controller.devices.store') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm grid gap-3 md:grid-cols-4">
                @csrf
                <select name="network_node_id" class="rounded-md border border-gray-300 p-2 text-sm"><option value="">Node wählen</option>@foreach($nodes as $node)<option value="{{ $node->id }}">{{ $node->name }}</option>@endforeach</select>
                <input name="name" placeholder="Gerätename" class="rounded-md border border-gray-300 p-2 text-sm" required>
                <input name="platform" value="android" class="rounded-md border border-gray-300 p-2 text-sm" required>
                <input name="device_uuid" placeholder="device-uuid" class="rounded-md border border-gray-300 p-2 text-sm" required>
                <input name="adb_serial" placeholder="adb serial" class="rounded-md border border-gray-300 p-2 text-sm">
                <input name="appium_endpoint" placeholder="http://127.0.0.1:4723" class="rounded-md border border-gray-300 p-2 text-sm">
                <select name="status" class="rounded-md border border-gray-300 p-2 text-sm"><option>offline</option><option>online</option><option>busy</option><option>error</option></select>
                <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Gerät anlegen</button>
            </form>

            <div class="rounded-lg border border-gray-200 bg-white shadow-sm overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr><th class="px-4 py-3 text-left">Name</th><th class="px-4 py-3 text-left">Node</th><th class="px-4 py-3 text-left">UUID</th><th class="px-4 py-3 text-left">ADB</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Aktion</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($devices as $device)
                            <tr>
                                <td class="px-4 py-3">{{ $device->name }}</td>
                                <td class="px-4 py-3">{{ $device->networkNode?->name ?: '-' }}</td>
                                <td class="px-4 py-3 text-xs">{{ $device->device_uuid }}</td>
                                <td class="px-4 py-3 text-xs">{{ $device->adb_serial ?: '-' }}</td>
                                <td class="px-4 py-3">{{ $device->status }}</td>
                                <td class="px-4 py-3">
                                    <form method="POST" action="{{ route('client-controller.devices.destroy', $device) }}" onsubmit="return confirm('Gerät löschen?')">@csrf @method('DELETE')<button class="rounded border border-red-300 px-2 py-1 text-xs text-red-700">Löschen</button></form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="p-4">{{ $devices->links() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
