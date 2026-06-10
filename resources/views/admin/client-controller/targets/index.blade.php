@extends('layouts.master')

@section('content')
<div class="main-content group-data-[sidebar-size=sm]:ml-[70px]">
    <div class="page-content dark:bg-zinc-700">
        <div class="container-fluid px-[0.625rem] space-y-6">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm"><h1 class="text-2xl font-semibold text-gray-900">Network Targets</h1></div>

            @if(session('success'))
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('success') }}</div>
            @endif

            <form method="POST" action="{{ route('client-controller.targets.store') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm grid gap-3 md:grid-cols-4">
                @csrf
                <input name="name" placeholder="Name" class="rounded-md border border-gray-300 p-2 text-sm" required>
                <input type="url" name="url" placeholder="https://example.com" class="rounded-md border border-gray-300 p-2 text-sm" required>
                <input type="number" min="1" max="600" name="timeout" value="30" class="rounded-md border border-gray-300 p-2 text-sm">
                <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Target anlegen</button>
                <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" checked> Aktiv</label>
                <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="allow_api" value="1" checked> API</label>
                <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="allow_browser" value="1"> Browser</label>
                <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="allow_screenshots" value="1"> Screenshots</label>
            </form>

            <div class="rounded-lg border border-gray-200 bg-white shadow-sm overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr><th class="px-4 py-3 text-left">Name</th><th class="px-4 py-3 text-left">URL</th><th class="px-4 py-3 text-left">Flags</th><th class="px-4 py-3 text-left">Timeout</th><th class="px-4 py-3 text-left">Aktion</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($targets as $target)
                            <tr>
                                <td class="px-4 py-3">{{ $target->name }}</td>
                                <td class="px-4 py-3 text-xs break-all">{{ $target->url }}</td>
                                <td class="px-4 py-3 text-xs">{{ $target->is_active ? 'active' : 'inactive' }} / API:{{ $target->allow_api ? 'yes' : 'no' }} / Browser:{{ $target->allow_browser ? 'yes' : 'no' }}</td>
                                <td class="px-4 py-3">{{ $target->timeout }}s</td>
                                <td class="px-4 py-3"><form method="POST" action="{{ route('client-controller.targets.destroy', $target) }}" onsubmit="return confirm('Target löschen?')">@csrf @method('DELETE')<button class="rounded border border-red-300 px-2 py-1 text-xs text-red-700">Löschen</button></form></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="p-4">{{ $targets->links() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
