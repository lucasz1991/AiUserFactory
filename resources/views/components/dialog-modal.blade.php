@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <x-slot name="title">
        {{ $title }}
    </x-slot>

    <div class="px-6 py-4">
        <div class="text-sm text-gray-600">
            {{ $content }}
        </div>
    </div>

    <x-slot name="actions">
        {{ $footer }}
    </x-slot>
</x-modal>
