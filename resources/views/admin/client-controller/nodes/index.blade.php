@extends('layouts.master')

@section('content')
<div class="main-content group-data-[sidebar-size=sm]:ml-[70px]">
    <div class="page-content dark:bg-zinc-700">
        <div class="container-fluid px-[0.625rem] space-y-6">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h1 class="text-2xl font-semibold text-gray-900">Nodes verwalten</h1>
            </div>

            @if(session('success'))
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('success') }}</div>
            @endif

            <form method="GET" action="{{ route('client-controller.nodes.index') }}" class="flex gap-2 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <input name="search" value="{{ request('search') }}" placeholder="Name, UUID, IP oder Betriebssystem suchen" class="min-w-0 flex-1 rounded-md border border-gray-300 p-2 text-sm">
                <button class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">Suchen</button>
            </form>

            <form method="POST" action="{{ route('client-controller.nodes.store') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm grid gap-3 md:grid-cols-4">
                @csrf
                <input name="name" placeholder="Node-Name" class="rounded-md border border-gray-300 p-2 text-sm" required>
                <input name="current_server_domain" placeholder="https://app.followflow.de" class="rounded-md border border-gray-300 p-2 text-sm">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="allow_server_rebind" value="1" checked> Rebind erlauben</label>
                <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Node anlegen</button>
            </form>

            <div class="rounded-lg border border-gray-200 bg-white shadow-sm overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left">Name</th>
                            <th class="px-4 py-3 text-left">UUID</th>
                            <th class="px-4 py-3 text-left">IP-Adresse</th>
                            <th class="px-4 py-3 text-left">Betriebssystem</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($nodes as $node)
                            @php($nodeIsOnline = $node->isAvailable())
                            <tr>
                                <td class="px-4 py-3"><a href="{{ route('client-controller.nodes.show', $node) }}" class="font-semibold text-blue-700 hover:underline">{{ $node->name }}</a></td>
                                <td class="px-4 py-3 text-xs text-gray-600">{{ $node->node_uuid }}</td>
                                <td class="px-4 py-3 text-xs">{{ $node->public_ip ?: '-' }}</td>
                                <td class="px-4 py-3 text-xs">{{ trim(($node->os ?: '-').' '.($node->version ?: '')) }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $nodeIsOnline ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">{{ $nodeIsOnline ? 'online' : 'offline' }}</span>
                                    <div class="mt-1 whitespace-nowrap text-xs text-gray-500">{{ $node->last_seen_at?->timezone(config('app.timezone'))->format('d.m.Y H:i:s') ?? 'nie' }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <a href="{{ route('client-controller.nodes.show', $node) }}" class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-700">Details</a>
                                        <form method="POST" action="{{ route('client-controller.nodes.regenerate-api-key', $node) }}">@csrf<button class="rounded border border-blue-300 px-2 py-1 text-xs text-blue-700">Key neu</button></form>
                                        <form method="POST" action="{{ route('client-controller.nodes.destroy', $node) }}" onsubmit="return confirm('Node löschen?')">@csrf @method('DELETE')<button class="rounded border border-red-300 px-2 py-1 text-xs text-red-700">Löschen</button></form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Keine Nodes vorhanden.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="p-4">{{ $nodes->links() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
