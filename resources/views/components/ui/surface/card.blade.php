@props([
    'as' => 'section',
    'padding' => true,
])

<{{ $as }}
    {{ $attributes->class([
        'ff-ui-surface',
        'ff-ui-surface--padded' => $padding,
    ]) }}
    data-ff-surface
>
    {{ $slot }}
</{{ $as }}>
