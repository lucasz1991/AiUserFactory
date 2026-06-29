<div    x-data="{ isHovered: false }" 
        class="relative border border-gray-300 rounded-lg overflow-hidden bg-white shadow mb-2 group"
        @mouseenter="isHovered = true"
        @mouseleave="isHovered = false"
        @touchstart="isHovered = true"
        @touchend="isHovered = false"
        >
    <div class="transition group-hover:blur-sm">
            <img src="{{ $file->icon_or_thumbnail }}" alt="{{ $file->name }}" class="w-full max:w-24 mx-auto aspect-square object-cover">
    </div>
    <div class="p-2 space-y-2 bg-gray-100 transition group-hover:blur-sm"  >
        <div class="text-sm text-gray-800 truncate ">{{ $file->name }}</div>
        <div class="text-xs text-gray-500 ">
            <span>{{ number_format($file->size / 1024, 1) }} KB</span>
        </div>
        @if($file->expires_at)
            @if(!$file->isExpired())
                <span class="text-gray-500 text-xs p-1 bg-gray-100 rounded border absolute top-2 group-hover:hidden">läuft ab am {{ $file->expires_at->format('d.m.Y') }}</span>
            @else
                <span class="text-red-500 text-xs  p-1 bg-red-100 rounded border absolute top-2 group-hover:hidden">abgelaufen</span>
            @endif
        @endif
    </div>
    <div class="absolute inset-0 flex items-center justify-center  flex-wrap  bg-white bg-opacity-65 rounded-lg " x-show="isHovered" x-collapse>
        <a href="{{ Storage::url($file->path) }}" target="_blank" class="text-gray-600 hover:text-blue-600 underline text-sm bg-gray-300 rounded-full p-2  m-2 ">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 aspect-square " viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </a>
        <button wire:click="downloadFile({{ $file->id }})" class="text-gray-600 hover:text-blue-600 text-sm bg-gray-300 rounded-full p-2 m-2    ">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 aspect-square " viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        </button>
        <button wire:click="editFile({{ $file->id }})" class="text-gray-600 hover:text-blue-600 text-sm bg-gray-300 rounded-full p-2  m-2 ">
            <svg xmlns="http://www.w3.org/2000/svg"  class="w-5 aspect-square " viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>        
        </button>
        <button wire:click="deleteFile({{ $file->id }})"  wire:confirm="Bist du dir sicher? Das Löschen ist unwiederruflich." class="text-red-600 text-sm bg-gray-300 rounded-full p-2  m-2 ">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 aspect-square " viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
        </button>
    </div>
</div>