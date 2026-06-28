@props([
    'nameModel',
    'groupModel',
    'subcategoryModel' => null,
    'descriptionModel',
    'activeModel' => null,
    'lockModel' => null,
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

    @if($activeModel)
        <label class="flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
            <input type="checkbox" wire:model.defer="{{ $activeModel }}" @disabled($disabled) class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900 disabled:cursor-not-allowed">
            Aktiv
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
