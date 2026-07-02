@extends('layouts.master')

@section('content')
<div class="main-content group-data-[sidebar-size=sm]:ml-[70px]">
    <div class="page-content dark:bg-zinc-700">
        <div class="container-fluid px-[0.625rem] space-y-6">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h1 class="text-2xl font-semibold text-gray-900">ClientController-Jobs</h1>
                <p class="mt-1 text-sm text-gray-500">Workflows auf einem ClientController-Node oder technische Roh-Jobs einplanen.</p>
            </div>

            @if(session('success'))
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('success') }}</div>
            @endif
            @if($errors->any())
                <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                    <ul class="list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" action="{{ route('client-controller.jobs.store') }}" x-data="{ mode: @js(old('job_mode', 'workflow')) }" class="grid gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm md:grid-cols-3">
                @csrf
                <div class="md:col-span-3 flex gap-4 border-b border-gray-100 pb-3">
                    <label class="flex items-center gap-2 text-sm"><input type="radio" name="job_mode" value="workflow" x-model="mode"> Workflow testen</label>
                    <label class="flex items-center gap-2 text-sm"><input type="radio" name="job_mode" value="raw" x-model="mode"> Technischer Roh-Job</label>
                </div>

                <select name="network_node_id" class="rounded-md border border-gray-300 p-2 text-sm" required>
                    <option value="">Node waehlen</option>
                    @foreach($nodes as $node)<option value="{{ $node->id }}" @selected((string) old('network_node_id') === (string) $node->id)>{{ $node->name }}</option>@endforeach
                </select>
                <select name="device_id" class="rounded-md border border-gray-300 p-2 text-sm">
                    <option value="">Direkt auf dem Node (kein Geraet)</option>
                    @foreach($devices as $device)<option value="{{ $device->id }}" @selected((string) old('device_id') === (string) $device->id)>{{ $device->name }} · {{ $device->status }} · Node #{{ $device->network_node_id }}</option>@endforeach
                </select>

                <template x-if="mode === 'workflow'">
                    <div class="contents">
                        <select name="workflow_id" class="rounded-md border border-gray-300 p-2 text-sm" required>
                            <option value="">Workflow waehlen</option>
                            @foreach($workflows as $workflow)<option value="{{ $workflow->id }}" @selected((string) old('workflow_id') === (string) $workflow->id)>{{ $workflow->name }}</option>@endforeach
                        </select>
                        <select name="person_id" class="rounded-md border border-gray-300 p-2 text-sm md:col-span-2">
                            <option value="">System (bisheriges Haupt-Verifikationskonto)</option>
                            @foreach($persons as $person)<option value="{{ $person->id }}" @selected((string) old('person_id') === (string) $person->id)>{{ $person->display_name }} · {{ $person->profile_key }}</option>@endforeach
                        </select>
                        <div class="rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
                            Ohne Geraeteauswahl laeuft der Workflow direkt im CloakBrowser des Nodes.
                        </div>
                    </div>
                </template>

                <template x-if="mode === 'raw'">
                    <div class="contents">
                        <select name="network_target_id" class="rounded-md border border-gray-300 p-2 text-sm"><option value="">Target optional</option>@foreach($targets as $target)<option value="{{ $target->id }}">{{ $target->name }}</option>@endforeach</select>
                        <input name="type" placeholder="z.B. ping" class="rounded-md border border-gray-300 p-2 text-sm" required>
                        <input type="datetime-local" name="expires_at" class="rounded-md border border-gray-300 p-2 text-sm">
                        <textarea name="payload_json" placeholder='{"value":"..."}' class="rounded-md border border-gray-300 p-2 text-sm md:col-span-3" rows="4">{{ old('payload_json') }}</textarea>
                    </div>
                </template>
                <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white md:col-span-3">Einplanen</button>
            </form>

            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr><th class="px-4 py-3 text-left">UUID</th><th class="px-4 py-3 text-left">Node / Geraet</th><th class="px-4 py-3 text-left">Typ</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Queued</th><th class="px-4 py-3 text-left">Aktion</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($jobs as $job)
                            <tr>
                                <td class="px-4 py-3 text-xs break-all">{{ $job->job_uuid }}</td>
                                <td class="px-4 py-3">{{ $job->networkNode?->name ?: '-' }}<div class="text-xs text-gray-500">{{ $job->device?->name ?: 'direkt auf Node' }}</div></td>
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
