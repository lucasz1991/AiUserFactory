@props([
    'href'     => null,     // wenn gesetzt -> <a>, sonst <button>
    'active'   => false,    // aktiven Zustand hervorheben
    'disabled' => false,    // disabled Zustand
    'size'     => 'md',     // sm|md|lg
    'class'    => '',       // zusätzliche Klassen
])

@php
$sizeMap = [
    'sm' => 'px-2 py-1 text-xs',
    'md' => 'px-3 py-2 text-sm',
    'lg' => 'px-4 py-2.5 text-base',
];

$base = 'inline-flex items-center gap-2 font-medium
         focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1
         transition-colors select-none';

$colors = $active
    ? 'bg-blue-600 text-white hover:bg-blue-700 focus-visible:ring-blue-400'
    : 'bg-white text-gray-700 hover:bg-gray-50 focus-visible:ring-blue-400
       dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700';

$borders = 'border-l border-gray-200 first:border-l-0
            dark:border-gray-700';

$rounded = 'first:rounded-l-lg last:rounded-r-lg';

$disabledCls = $disabled
    ? 'opacity-50 cursor-not-allowed hover:bg-inherit'
    : '';

$cls = implode(' ', [
    $base,
    $sizeMap[$size] ?? $sizeMap['md'],
    $colors,
    $borders,
    $rounded,
    $disabledCls,
    $class,
]);
@endphp

@if($href)
    <a
        href="{{ $href }}"
        {{ $attributes->merge(['class' => $cls]) }}
        @if($disabled) aria-disabled="true" tabindex="-1" @endif
    >
        {{ $slot }}
    </a>
@else
    <button
        type="button"
        {{ $attributes->merge(['class' => $cls]) }}
        @disabled($disabled)
    >
        {{ $slot }}
    </button>
@endif
