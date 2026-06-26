<div class="space-y-6" wire:loading.class="opacity-60 pointer-events-none">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Workflows</h1>
            <p class="mt-1 text-sm text-gray-500">
                Uebersicht aller Workflow-Boards mit Aktionen, Listen, Step-Karten und Ausfuehrungen.
            </p>
        </div>
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('network.actions') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                Aktionen
            </a>
            <a href="{{ route('processes.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                Prozesse
            </a>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-admin.stat label="Workflows" :value="$summary['workflows']" tone="slate" />
        <x-admin.stat label="Aktiv" :value="$summary['active_workflows']" tone="emerald" />
        <x-admin.stat label="Listen" :value="$summary['lists']" tone="blue" />
        <x-admin.stat label="Step-Karten" :value="$summary['task_cards']" tone="amber" />
    </div>

    <x-admin.panel title="Neuer Workflow">
        <div class="grid gap-4 lg:grid-cols-[minmax(0,280px)_minmax(0,1fr)_auto]">
            <div>
                <label for="new-workflow-name" class="block text-sm font-medium text-gray-700">Name</label>
                <input id="new-workflow-name" type="text" wire:model.defer="newWorkflowName" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('newWorkflowName') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="new-workflow-description" class="block text-sm font-medium text-gray-700">Beschreibung</label>
                <input id="new-workflow-description" type="text" wire:model.defer="newWorkflowDescription" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('newWorkflowDescription') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end">
                <button type="button" wire:click="createWorkflow" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                    Erstellen
                </button>
            </div>
        </div>
    </x-admin.panel>

    <div class="grid gap-4 xl:grid-cols-2">
        @forelse($workflows as $workflow)
            <x-workflows.workflow-summary-card :workflow="$workflow" wire:key="workflow-summary-{{ $workflow->id }}" />
        @empty
            <div class="xl:col-span-2 rounded-md border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm text-slate-500">
                Keine Workflows in der Datenbank. Die Seeder legen die Default-Workflows wieder an, wenn du sie ausfuehrst.
            </div>
        @endforelse
    </div>
</div>
