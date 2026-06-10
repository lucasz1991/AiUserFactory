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
                            <th class="px-4 py-3 text-left">API Key</th>
                            <th class="px-4 py-3 text-left">Server</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($nodes as $node)
                            <tr>
                                <td class="px-4 py-3">{{ $node->name }}</td>
                                <td class="px-4 py-3 text-xs text-gray-600">{{ $node->node_uuid }}</td>
                                <td class="px-4 py-3 text-xs break-all">{{ $node->api_key }}</td>
                                <td class="px-4 py-3 text-xs">{{ $node->current_server_domain ?: '-' }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $node->is_online ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">{{ $node->is_online ? 'online' : 'offline' }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <form method="POST" action="{{ route('client-controller.nodes.regenerate-api-key', $node) }}">@csrf<button class="rounded border border-blue-300 px-2 py-1 text-xs text-blue-700">Key neu</button></form>
                                        <form method="POST" action="{{ route('client-controller.nodes.destroy', $node) }}" onsubmit="return confirm('Node löschen?')">@csrf @method('DELETE')<button class="rounded border border-red-300 px-2 py-1 text-xs text-red-700">Löschen</button></form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="p-4">{{ $nodes->links() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
