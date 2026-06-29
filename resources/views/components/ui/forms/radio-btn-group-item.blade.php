@props([
    'id'        => null,
    'label'     => null,
    'value'     => null,
    'icon'      => null,          // e.g. "fa-circle-check"
    'iconStyle' => 'fad',  // your FA Pro style class
    'disabled'  => false,
    'name'      => null,
])

@php
    $inputId = $id ?: \Illuminate\Support\Str::uuid();
@endphp

{{-- Wrap each item so input (peer) and label are siblings --}}
<div class="{{ $attributes->get('class') }}" data-rbg-item>
    <input
        id="{{ $inputId }}"
        type="radio"
        value="{{ $value }}"
        name="{{ $name }}"
        {{ $disabled ? 'disabled' : '' }}

        {{-- Allow Livewire binding on the input (wire:model etc.) --}}
        {{ $attributes->whereStartsWith('wire:model') }}

        class="sr-only peer"
    />

    <label
        for="{{ $inputId }}"
        class="inline-flex items-center gap-2 select-none
               text-sm font-medium text-gray-700 bg-white
               hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500
               transition-colors
               peer-checked:bg-blue-50 peer-checked:text-blue-700

               {{-- padding via CSS vars from group --}}
               px-[var(--rbg-pad-x,1rem)] py-[var(--rbg-pad-y,0.5rem)]

               {{-- full-width support if group is full --}}
               {{ str_contains($attributes->get('class',''), 'flex-1') ? 'w-full justify-center' : '' }}

               {{ $disabled ? 'opacity-50 pointer-events-none' : '' }}"
    >
        @if($icon)
            <i class="{{ $iconStyle }} {{ $icon }} fa-lg"></i>
        @endif
        <span>{{ $label }}</span>
    </label>
</div>
