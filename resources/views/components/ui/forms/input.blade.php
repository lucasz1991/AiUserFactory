@props([
    'type' => 'text',
    'disabled' => false,
    'readonly' => false,
    'autofocus' => false,
])

@php
    // Grundstil für alle Inputs
    $baseClasses = 'w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm';

    // Typ-spezifische Klassen (optional)
    $typeClasses = match($type) {
        'date', 'time', 'datetime-local' => 'px-2 py-1.5',
        'file'  => 'text-sm text-gray-700 border border-gray-300 bg-white',
        'number' => 'text-right',
        default => 'px-2 py-1.5',
    };

    $allClasses = $baseClasses . ' ' . $typeClasses;
@endphp

<input
    type="{{ $type }}"
    {{ $disabled ? 'disabled' : '' }}
    {{ $readonly ? 'readonly' : '' }}
    {{ $autofocus ? 'autofocus' : '' }}
    {!! $attributes->merge(['class' => $allClasses]) !!}
>
