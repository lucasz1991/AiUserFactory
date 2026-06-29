@props([
    'file',                 // Pflicht: dein File-/Attachment-Objekt
    'previewUrl'  => null,  // optional, falls du explizit was setzen willst
    'downloadUrl' => null,  // optional, falls du explizit was setzen willst
])

@php
    // Titel & Meta
    $name      = $file->name ?? $file->original_name ?? 'Datei';
    $size      = $file->size_formatted ?? null;
    $mime      = $file->mime_type ?? $file->mimetype ?? null;

    // Icon / Thumbnail
    $icon      = $file->icon_or_thumbnail
        ?? ($file->icon ?? null)
        ?? asset('images/fileicons/file-generic.svg');

    // Fallback-URLs: wenn das Model eine URL-Methode hat
    $previewUrl  = $previewUrl  ?? (method_exists($file, 'getEphemeralPublicUrl') ? $file->getEphemeralPublicUrl() : null);
@endphp

<div class="flex items-center justify-between gap-3 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm">

    {{-- Linke Seite: Icon + Infos --}}
    <div class="flex items-center gap-3 min-w-0">
        <div class="flex-shrink-0 w-9 h-9 flex items-center justify-center">
            @if($icon)
                <img
                    class="w-9 h-9 object-contain"
                    src="{{ $icon }}"
                    alt="Datei-Icon"
                >
            @else
                <div class="w-8 h-8 rounded bg-slate-100 flex items-center justify-center text-slate-400">
                    <i class="fal fa-file"></i>
                </div>
            @endif
        </div>

        <div class="flex-1 min-w-0">
            <div class="font-medium truncate">
                {{ $name }}
            </div>

            <div class="flex items-center gap-2 text-[11px] text-slate-500">
                @if($size)
                    <span>{{ $size }}</span>
                @endif
                @if($mime)
                    <span class="inline-block w-1 h-1 rounded-full bg-slate-300"></span>
                    <span>{{ strtoupper($mime) }}</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Rechte Seite: Aktionen (immer gleich) --}}
    <div class="flex items-center gap-1 flex-shrink-0">

        {{-- Vorschau --}}
        @if($previewUrl)
            <a
                href="{{ $previewUrl }}"
                target="_blank"
                class="inline-flex items-center gap-1 rounded-md border border-slate-200 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                title="Datei in neuem Tab öffnen"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M15 3h6v6"/>
                    <path d="M10 14 21 3"/>
                    <path d="M5 5v14h14"/>
                </svg>
                <span>Download</span>
            </a>
        @endif
    </div>
</div>
