@props([
    'eyebrow' => null,
    'title',
    'description' => null,
])

<x-ui.surface.card {{ $attributes->class('ff-page-header') }} aria-labelledby="ff-page-title">
    <div class="ff-page-header__copy">
        @if(filled($eyebrow))
            <p class="ff-page-header__eyebrow">{{ $eyebrow }}</p>
        @endif
        <h1 id="ff-page-title" class="ff-page-header__title">{{ $title }}</h1>
        @if(filled($description))
            <p class="ff-page-header__description">{{ $description }}</p>
        @endif
    </div>

    @if(isset($actions))
        <div class="ff-page-header__actions">
            {{ $actions }}
        </div>
    @endif
</x-ui.surface.card>
