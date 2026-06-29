@props([
    'as' => 'button',
    'href' => null,
    'type' => 'button',
])

@php
    $baseClasses = 'inline-flex items-center w-full px-4 py-2 text-left text-sm leading-5 text-gray-700 transition duration-150 ease-in-out focus:outline-none';
@endphp

@if($as === 'a')
    <a
        href="{{ $href }}"
        {{ $attributes->merge(['class' => $baseClasses . ' hover:bg-gray-100 focus:bg-gray-100']) }}
    >
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $attributes->merge(['class' => $baseClasses . ' hover:bg-gray-100 focus:bg-gray-100']) }}
    >
        {{ $slot }}
    </button>
@endif
