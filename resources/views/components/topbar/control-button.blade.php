@props(['type' => 'button'])

<button
    type="{{ $type }}"
    {{ $attributes->class('ff-topbar-control') }}
    data-ff-topbar-control
>
    {{ $slot }}
</button>
