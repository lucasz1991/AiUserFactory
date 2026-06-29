@props([
    'size'   => 'md',     // sm|md
    'full'   => false,    // true => items grow equally
    'divide' => true,     // show vertical dividers between items
])

@php
    $pad = $size === 'sm' ? 'px-3 py-1.5' : 'px-4 py-2';
@endphp

<div
    {{ $attributes->class([
        // Outer frame carries border & rounded
        "inline-flex items-stretch overflow-hidden rounded-md border border-gray-200 bg-white",
        // Optional dividers between buttons (purely visual; no double borders)
        "divide-x divide-gray-200" => $divide,
        // Make all children flex equally when desired
        "w-full" => $full,
    ]) }}
    role="group"
    data-rbg-group
>
    <style>
        [data-rbg-group] [data-rbg-item] { --rbg-pad-x: {{ $size === 'sm' ? '0.75rem' : '1rem' }}; --rbg-pad-y: {{ $size === 'sm' ? '0.375rem' : '0.5rem' }}; }
    </style>
    {{ $slot }}
</div>
