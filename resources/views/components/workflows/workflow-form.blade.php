@props([
    'nameModel',
    'groupModel',
    'descriptionModel',
    'activeModel' => null,
])

<div class="space-y-4">
    <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(180px,240px)]">
        <div>
            <label for="{{ $nameModel }}" class="block text-sm font-medium text-gray-700">Name</label>
            <input id="{{ $nameModel }}" type="text" wire:model.defer="{{ $nameModel }}" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
            @error($nameModel) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="{{ $groupModel }}" class="block text-sm font-medium text-gray-700">Gruppe</label>
            <input id="{{ $groupModel }}" type="text" wire:model.defer="{{ $groupModel }}" placeholder="custom, mail, browser" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
            @error($groupModel) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label for="{{ $descriptionModel }}" class="block text-sm font-medium text-gray-700">Beschreibung</label>
        <textarea id="{{ $descriptionModel }}" rows="4" wire:model.defer="{{ $descriptionModel }}" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
        @error($descriptionModel) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    @if($activeModel)
        <label class="flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
            <input type="checkbox" wire:model.defer="{{ $activeModel }}" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
            Aktiv
        </label>
    @endif
</div>
