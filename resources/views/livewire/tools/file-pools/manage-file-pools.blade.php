<div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm" wire:poll.10s="refreshFilePool">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">FilePool</h3>
            <p class="mt-1 text-sm text-gray-500">
                {{ $filePool?->title ?: 'Standard Ordner' }}{{ $filePool?->files?->count() ? ' - '.$filePool->files->count().' Dateien' : '' }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @if($filePool && $filePool->files->count() > 0)
                <button type="button" wire:click="downloadAll" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                    Alle herunterladen
                </button>
            @endif
            @if(! $readOnly)
                <button type="button" wire:click="$toggle('openFileForm')" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                    Datei hochladen
                </button>
            @endif
        </div>
    </div>

    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse($filePool?->files ?? [] as $file)
            <div class="rounded-md border border-gray-200 bg-gray-50 p-4" wire:key="filepool-file-{{ $file->id }}">
                <div class="flex items-start gap-3">
                    @if($file->is_image)
                        <img src="{{ $file->icon_or_thumbnail }}" alt="{{ $file->name }}" class="h-14 w-14 rounded-md object-cover">
                    @else
                        <img src="{{ $file->icon_or_thumbnail }}" alt="{{ $file->name }}" class="h-14 w-14 rounded-md object-contain bg-white p-2">
                    @endif

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-gray-900">{{ $file->name_with_extension }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ $file->getMimeTypeForHumans() }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ $file->sizeFormatted }}</p>
                        @if($file->expires_at)
                            <p class="mt-1 text-xs text-amber-700">Ablauf: {{ $file->expires_at->format('d.m.Y') }}</p>
                        @endif
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ $file->getEphemeralPublicUrl(10) }}" target="_blank" rel="noopener" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                        Oeffnen
                    </a>
                    <button type="button" wire:click="downloadFile({{ $file->id }})" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                        Download
                    </button>
                    @if(! $readOnly)
                        <button type="button" wire:click="editFile({{ $file->id }})" class="rounded-md border border-blue-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50">
                            Bearbeiten
                        </button>
                        <button type="button" wire:click="deleteFile({{ $file->id }})" onclick="return confirm('Diese Datei wirklich loeschen?')" class="rounded-md border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">
                            Loeschen
                        </button>
                    @endif
                </div>
            </div>
        @empty
            <div class="sm:col-span-2 xl:col-span-3 rounded-md border border-dashed border-gray-300 bg-gray-50 p-6 text-sm text-gray-500">
                Keine Dateien vorhanden.
            </div>
        @endforelse
    </div>

    <x-dialog-modal wire:model="openFileForm">
        <x-slot name="title">Datei-Upload</x-slot>
        <x-slot name="content">
            @if($filePool)
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Dateien</label>
                        <input type="file" wire:model="fileUploads.{{ $filePool->id }}" multiple class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                        @error('fileUploads.'.$filePool->id) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        @error('fileUploads.'.$filePool->id.'.*') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Ablaufdatum</label>
                        <input type="date" wire:model="expires.{{ $filePool->id }}" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                        @error('expires.'.$filePool->id) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif
        </x-slot>
        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="$toggle('openFileForm')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                    Abbrechen
                </button>
                @if($filePool)
                    <button type="button" wire:click="uploadFile({{ $filePool->id }})" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                        Hochladen
                    </button>
                @endif
            </div>
        </x-slot>
    </x-dialog-modal>

    <x-dialog-modal wire:model="openEditFileForm">
        <x-slot name="title">Datei bearbeiten</x-slot>
        <x-slot name="content">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Dateiname</label>
                    <input type="text" wire:model.defer="selectedFileName" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                    @error('selectedFileName') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Ablaufdatum</label>
                    <input type="date" wire:model.defer="selectedFileExpiresDate" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                    @error('selectedFileExpiresDate') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-slot>
        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="$toggle('openEditFileForm')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                    Abbrechen
                </button>
                <button type="button" wire:click="saveFile" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                    Speichern
                </button>
            </div>
        </x-slot>
    </x-dialog-modal>
</div>
