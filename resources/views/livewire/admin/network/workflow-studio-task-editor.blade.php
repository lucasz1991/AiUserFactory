<div>
    <x-dialog-modal wire:model="showEditTaskModal" maxWidth="5xl">
        <x-slot name="title">
            <div>
                <span class="text-base font-semibold text-slate-950">Task bearbeiten</span>
                <p class="mt-1 text-xs font-normal text-slate-500">Dieselben Einstellungen wie im Workflow-Manager. Beim Speichern entsteht automatisch eine neue Studio-Revision.</p>
            </div>
        </x-slot>
        <x-slot name="content">
            @include('livewire.admin.network.partials.workflow-task-form', ['mode' => 'edit'])
        </x-slot>
        <x-slot name="footer">
            <button type="button" x-on:click="$dispatch('close')" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Abbrechen</button>
            <button
                type="button"
                x-on:click.prevent="
                    const source = document.querySelector('[data-workflow-task-mailbox-source=&quot;editingTask&quot;]')?.value || 'person';
                    $wire.saveEditTaskCard(source);
                "
                class="ml-2 rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-500"
            >Speichern & Revision erstellen</button>
        </x-slot>
    </x-dialog-modal>
</div>
