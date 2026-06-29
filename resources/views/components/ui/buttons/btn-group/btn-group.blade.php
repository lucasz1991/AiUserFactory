@props([
    'label' => null,     // optional für aria-label
    'class' => '',       // zusätzliche Klassen
])

@php
$base = 'inline-flex flex-wrap items-stretch rounded-lg border border-gray-200
         bg-white shadow-sm overflow-hidden
         dark:bg-gray-800 dark:border-gray-700';
@endphp

<div {{ $attributes->merge([
        'class' => $base . ' ' . $class,
        'role'  => 'group',
        'aria-label' => $label ?? 'Button group'
    ]) }}>
    {{ $slot }}
</div>
