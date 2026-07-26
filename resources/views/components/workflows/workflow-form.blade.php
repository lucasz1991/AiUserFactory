@props([
    'nameModel',
    'groupModel',
    'subcategoryModel' => null,
    'descriptionModel',
    'activeModel' => null,
    'lockModel' => null,
    'developmentModel' => null,
    'browserSessionEnabledModel' => null,
    'browserSessionLoadModel' => null,
    'browserSessionSaveModel' => null,
    'browserSessionKeyModel' => null,
    'browserSessionFallbackUrlModel' => null,
    'browserSessionTargetDomainModel' => null,
    'browserSessionWindowModel' => null,
    'browserSessionLabelModel' => null,
    'disabled' => false,
    'lockDisabled' => false,
    'lockHelp' => null,
])

<div class="space-y-4">
    <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(180px,240px)_minmax(180px,240px)]">
        <div>
            <label for="{{ $nameModel }}" class="block text-sm font-medium text-gray-700">Name</label>
            <input id="{{ $nameModel }}" type="text" wire:model.defer="{{ $nameModel }}" @disabled($disabled) class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:cursor-not-allowed disabled:bg-slate-100">
            @error($nameModel) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="{{ $groupModel }}" class="block text-sm font-medium text-gray-700">Gruppe</label>
            <input id="{{ $groupModel }}" type="text" wire:model.defer="{{ $groupModel }}" @disabled($disabled) placeholder="custom, mail, browser" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:cursor-not-allowed disabled:bg-slate-100">
            @error($groupModel) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        @if($subcategoryModel)
            <div>
                <label for="{{ $subcategoryModel }}" class="block text-sm font-medium text-gray-700">Unterkategorie</label>
                <input id="{{ $subcategoryModel }}" type="text" wire:model.defer="{{ $subcategoryModel }}" @disabled($disabled) placeholder="gmx, login, mailbox" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:cursor-not-allowed disabled:bg-slate-100">
                @error($subcategoryModel) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endif
    </div>

    <div>
        <label for="{{ $descriptionModel }}" class="block text-sm font-medium text-gray-700">Beschreibung</label>
        <textarea id="{{ $descriptionModel }}" rows="4" wire:model.defer="{{ $descriptionModel }}" @disabled($disabled) class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:cursor-not-allowed disabled:bg-slate-100"></textarea>
        @error($descriptionModel) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    @if($browserSessionEnabledModel)
        <section class="overflow-hidden rounded-xl border border-indigo-200 bg-indigo-50/60">
            <div class="flex flex-col gap-3 border-b border-indigo-200 bg-white/70 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-indigo-950">Browser-Session</h3>
                    <p class="mt-1 text-xs leading-5 text-indigo-800">
                        Die Session wird automatisch vor dem ersten Browser-Task geladen und nach ausgeführten Workflow-Abschnitten gesichert.
                    </p>
                </div>
                <label class="inline-flex shrink-0 items-center gap-3 text-sm font-semibold text-indigo-950">
                    <span>Automatik aktiv</span>
                    <span class="relative inline-flex h-6 w-11 items-center">
                        <input type="checkbox" role="switch" wire:model.defer="{{ $browserSessionEnabledModel }}" @disabled($disabled) class="peer sr-only">
                        <span class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-indigo-600 peer-disabled:cursor-not-allowed peer-disabled:opacity-50"></span>
                        <span class="absolute left-1 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                    </span>
                </label>
            </div>

            <div class="space-y-4 p-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex items-start gap-3 rounded-lg border border-indigo-100 bg-white p-3 text-sm text-slate-700">
                        <input type="checkbox" wire:model.defer="{{ $browserSessionLoadModel }}" @disabled($disabled) class="mt-0.5 rounded border-indigo-300 text-indigo-700 shadow-sm focus:ring-indigo-700 disabled:cursor-not-allowed">
                        <span>
                            <span class="font-semibold text-slate-900">Am Anfang laden</span>
                            <span class="mt-1 block text-xs leading-5 text-slate-500">Cookies und Storage werden vor der eigentlichen Browser-Automation wiederhergestellt.</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 rounded-lg border border-indigo-100 bg-white p-3 text-sm text-slate-700">
                        <input type="checkbox" wire:model.defer="{{ $browserSessionSaveModel }}" @disabled($disabled) class="mt-0.5 rounded border-indigo-300 text-indigo-700 shadow-sm focus:ring-indigo-700 disabled:cursor-not-allowed">
                        <span>
                            <span class="font-semibold text-slate-900">Am Ende speichern</span>
                            <span class="mt-1 block text-xs leading-5 text-slate-500">Der letzte gültige Browserzustand wird verschlüsselt für den nächsten Lauf gesichert.</span>
                        </span>
                    </label>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="{{ $browserSessionKeyModel }}" class="block text-sm font-medium text-slate-700">Session-Key <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="{{ $browserSessionKeyModel }}" type="text" wire:model.defer="{{ $browserSessionKeyModel }}" @disabled($disabled) placeholder="z. B. gemeinsames-webmail" class="mt-1 block w-full rounded-md border border-indigo-200 bg-white p-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-100">
                        <p class="mt-1.5 text-xs leading-5 text-slate-500">Derselbe Key teilt eine Session workflowübergreifend. Ohne Key wird automatisch <code>workflow-ID + Personen-ID</code> verwendet; beim Haupt-Verifikationskonto ist die Personen-ID <code>null</code>.</p>
                        @error($browserSessionKeyModel) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="{{ $browserSessionFallbackUrlModel }}" class="block text-sm font-medium text-slate-700">Fallback-URL <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="{{ $browserSessionFallbackUrlModel }}" type="url" wire:model.defer="{{ $browserSessionFallbackUrlModel }}" @disabled($disabled) placeholder="https://mail.example.com" class="mt-1 block w-full rounded-md border border-indigo-200 bg-white p-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-100">
                        <p class="mt-1.5 text-xs leading-5 text-slate-500">Wird nur geöffnet, wenn unter dem effektiven Session-Key noch keine gespeicherte Session existiert.</p>
                        @error($browserSessionFallbackUrlModel) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="{{ $browserSessionTargetDomainModel }}" class="block text-sm font-medium text-slate-700">Domain/URL <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="{{ $browserSessionTargetDomainModel }}" type="text" wire:model.defer="{{ $browserSessionTargetDomainModel }}" @disabled($disabled) placeholder="mail.example.com" class="mt-1 block w-full rounded-md border border-indigo-200 bg-white p-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-100">
                        <p class="mt-1.5 text-xs leading-5 text-slate-500">Begrenzt Auswahl und Speicherung auf die gewünschte Website. Leer verwendet die aktive Browser-URL.</p>
                        @error($browserSessionTargetDomainModel) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="{{ $browserSessionWindowModel }}" class="block text-sm font-medium text-slate-700">Browserfenster</label>
                        <input id="{{ $browserSessionWindowModel }}" type="text" wire:model.defer="{{ $browserSessionWindowModel }}" @disabled($disabled) placeholder="main" class="mt-1 block w-full rounded-md border border-indigo-200 bg-white p-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-100">
                        <p class="mt-1.5 text-xs leading-5 text-slate-500">Fenstername, in dem die Session automatisch geladen und gespeichert wird.</p>
                        @error($browserSessionWindowModel) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="{{ $browserSessionLabelModel }}" class="block text-sm font-medium text-slate-700">Anzeigename <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="{{ $browserSessionLabelModel }}" type="text" wire:model.defer="{{ $browserSessionLabelModel }}" @disabled($disabled) placeholder="Webmail, Kundenportal, Dashboard" class="mt-1 block w-full rounded-md border border-indigo-200 bg-white p-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-100">
                    @error($browserSessionLabelModel) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>
    @endif

    @if($activeModel)
        <label class="flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
            <input type="checkbox" wire:model.defer="{{ $activeModel }}" @disabled($disabled) class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900 disabled:cursor-not-allowed">
            Aktiv
        </label>
    @endif

    @if($developmentModel)
        <label class="flex items-center justify-between gap-4 rounded-md border border-cyan-200 bg-cyan-50 p-3 text-sm font-medium text-cyan-950">
            <span>
                Development
                <span class="mt-1 block text-xs font-normal text-cyan-800">Speichert vor und nach jeder Task-Karte DOM-Daten und behaelt alle Debug-Artefakte.</span>
            </span>
            <span class="relative inline-flex h-6 w-11 shrink-0 items-center">
                <input type="checkbox" role="switch" wire:model.defer="{{ $developmentModel }}" @disabled($disabled) class="peer sr-only">
                <span class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-cyan-600 peer-disabled:cursor-not-allowed peer-disabled:opacity-50"></span>
                <span class="absolute left-1 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
            </span>
        </label>
    @endif

    @if($lockModel)
        <label class="flex items-start gap-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm font-medium text-amber-900">
            <input type="checkbox" wire:model.defer="{{ $lockModel }}" @disabled($lockDisabled) class="mt-0.5 rounded border-amber-300 text-amber-700 shadow-sm focus:ring-amber-700 disabled:cursor-not-allowed">
            <span>
                Bearbeitung sperren
                @if($lockHelp)
                    <span class="mt-1 block text-xs font-normal text-amber-800">{{ $lockHelp }}</span>
                @endif
            </span>
        </label>
    @endif
</div>
