@props(['id' => null, 'maxWidth' => null, 'interactiveAside' => false])

<x-ui.modal :id="$id" :maxWidth="$maxWidth" :interactive-aside="$interactiveAside" {{ $attributes }}>
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
</x-ui.modal>
