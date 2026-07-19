@props([
    'workflow',
    'editMethod' => null,
    'duplicateMethod' => null,
    'deleteMethod' => null,
])

<x-ui.dropdown align="right" width="48" contentClasses="bg-white py-1">
    <x-slot name="trigger">
        <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600 shadow-sm hover:bg-slate-50 hover:text-slate-900" aria-label="Workflow-Aktionen">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5ZM12 12.75a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5ZM12 18.75a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Z" />
            </svg>
        </button>
    </x-slot>

    <x-slot name="content">
        <a href="{{ route('network.workflows.studio', ['workflow' => $workflow, 'mode' => 'manual']) }}" class="block px-3 py-2 text-sm font-semibold text-cyan-700 hover:bg-cyan-50">
            Workflow Studio
        </a>
        <a href="{{ route('network.workflows.manage', $workflow) }}" class="block px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            Klassischen Manager oeffnen
        </a>

        @if($editMethod)
            <button type="button" wire:click="{{ $editMethod }}({{ $workflow->id }})" class="block w-full px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Bearbeiten
            </button>
        @endif

        @if($duplicateMethod)
            <button type="button" wire:click="{{ $duplicateMethod }}({{ $workflow->id }})" class="block w-full px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Duplizieren
            </button>
        @endif

        @if($deleteMethod)
            @if(! $workflow->is_edit_locked)
                <button type="button" wire:click="{{ $deleteMethod }}({{ $workflow->id }})" wire:confirm="Workflow samt Aufgaben, Tasks und Ausfuehrungen wirklich loeschen?" class="block w-full px-3 py-2 text-left text-sm font-semibold text-red-700 hover:bg-red-50">
                    Loeschen
                </button>
            @endif
        @endif
    </x-slot>
</x-ui.dropdown>
