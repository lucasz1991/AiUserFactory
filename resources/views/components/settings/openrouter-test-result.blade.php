@props([
    'result' => null,
])

@if(is_array($result))
    <div
        role="status"
        {{ $attributes->merge(['class' => 'mt-3 rounded-md border p-3 text-xs '.(($result['state'] ?? '') === 'success'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
            : 'border-red-200 bg-red-50 text-red-900')]) }}
    >
        <div class="flex items-center justify-between gap-2">
            <span class="font-semibold">{{ ($result['state'] ?? '') === 'success' ? 'Test erfolgreich' : 'Test fehlgeschlagen' }}</span>
            @if(($result['duration_ms'] ?? 0) > 0)
                <span class="text-[11px] opacity-70">{{ number_format(($result['duration_ms'] ?? 0) / 1000, 1, ',', '.') }} s</span>
            @endif
        </div>
        @if(trim((string) ($result['output'] ?? '')) !== '')
            <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap break-words font-sans">{{ $result['output'] }}</pre>
        @endif
        @foreach(($result['images'] ?? []) as $imageUrl)
            <img src="{{ $imageUrl }}" alt="Generiertes Testbild" class="mt-2 max-h-48 rounded-md border border-emerald-200 bg-white object-contain" />
        @endforeach
    </div>
@endif
