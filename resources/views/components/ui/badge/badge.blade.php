@props([
    'color' => 'gray',
    'size' => 'md',
])

@php
    $base = 'inline-flex items-center font-medium rounded-lg';
    $sizes = [
        'sm' => 'px-2 py-0.5 text-xs',
        'md' => 'px-3 py-1 text-sm',
        'lg' => 'px-4 py-1.5 text-base',
    ];

    $colors = [
        'gray'   => 'bg-gray-100 text-gray-800',
        'blue'   => 'bg-blue-100 text-blue-800',
        'yellow' => 'bg-yellow-100 text-yellow-800',
        'green'  => 'bg-green-100 text-green-800',
        'red'    => 'bg-red-100 text-red-800',
        'purple' => 'bg-purple-100 text-purple-800',
        'primary'=> 'bg-primary-100 text-primary-800',
    ];
@endphp

<span {{ $attributes->merge(['class' => "$base {$sizes[$size]} {$colors[$color]}"]) }}>
    {{ $slot }}
</span>
