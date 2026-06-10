@extends('layouts.master')

@section('content')
<div class="main-content group-data-[sidebar-size=sm]:ml-[70px]">
    <div class="page-content dark:bg-zinc-700">
        <div class="container-fluid px-[0.625rem] space-y-6">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm"><h1 class="text-2xl font-semibold text-gray-900">Jobs</h1></div>

            @if(session('success'))
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('success') }}</div>
            @endif

            <form method="POST" action="{{ route('client-controller.jobs.store') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm grid gap-3 md:grid-cols-3">
                @csrf
                <select name="network_node_id" class="rounded-md border border-gray-300 p-2 text-sm" required><option value="">Node wählen</option>@foreach($nodes as $node)<option value="{{ $node->id }}">{{ $node->name }}</option>@endforeach</select>
                <select name="device_id" class="rounded-md border border-gray-300 p-2 text-sm"><option value="">Gerät optional</option>@foreach($devices as $device)<option value="{{ $device->id }}">{{ $device->name }}</option>@endforeach</select>
                <select name="network_target_id" class="rounded-md border border-gray-300 p-2 text-sm"><option value="">Target optional</option>@foreach($targets as $target)<option value="{{ $target->id }}">{{ $target->name }}</option>@endforeach</select>
                <input name="type" placeholder="z.B. android_action" class="rounded-md border border-gray-300 p-2 text-sm" required>
                <input type="datetime-local" name="expires_at" class="rounded-md border border-gray-300 p-2 text-sm">
                <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Job erzeugen</button>
                <textarea name="payload_json" placeholder='{"action":"tap","selector":"..."}' class="md:col-span-3 rounded-md border border-gray-300 p-2 text-sm" rows="4"></textarea>
            </form>

            <div class="rounded-lg border border-gray-200 bg-white shadow-sm overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr><th class="px-4 py-3 text-left">UUID</th><th class="px-4 py-3 text-left">Node</th><th class="px-4 py-3 text-left">Typ</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Queued</th><th class="px-4 py-3 text-left">Aktion</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($jobs as $job)
                            <tr>
                                <td class="px-4 py-3 text-xs break-all">{{ $job->job_uuid }}</td>
                                <td class="px-4 py-3">{{ $job->networkNode?->name ?: '-' }}</td>
                                <td class="px-4 py-3">{{ $job->type }}</td>
                                <td class="px-4 py-3"><span class="rounded-full bg-gray-100 px-2 py-1 text-xs">{{ $job->status }}</span></td>
                                <td class="px-4 py-3 text-xs">{{ optional($job->queued_at)->format('d.m.Y H:i') }}</td>
                                <td class="px-4 py-3">
                                    @if(!in_array($job->status, ['success','failed','cancelled']))
                                        <form method="POST" action="{{ route('client-controller.jobs.cancel', $job) }}">@csrf<button class="rounded border border-amber-300 px-2 py-1 text-xs text-amber-700">Abbrechen</button></form>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="p-4">{{ $jobs->links() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
