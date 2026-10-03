@props(['id' => null, 'maxWidth' => null, 'interactiveAside' => false, 'open' => false, 'closeAction' => null, 'returnFocus' => null, 'panelClass' => '', 'bodyClass' => '', 'renderDialog' => true])

@if($renderDialog)
@php
$model = $attributes->wire('model')->value();
$id = $id ?? 'ui-modal-'.md5($model ?: ($attributes->get('wire:key') ?: $closeAction ?? 'dialog'));
$widthClass = [
    'sm' => 'sm:max-w-sm', 'md' => 'sm:max-w-md', 'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl', '2xl' => 'sm:max-w-2xl', '3xl' => 'sm:max-w-3xl',
    '4xl' => 'sm:max-w-4xl', '5xl' => 'sm:max-w-5xl', '6xl' => 'sm:max-w-6xl',
    '7xl' => 'sm:max-w-7xl', 'screen' => 'sm:max-w-[96rem]',
][$maxWidth ?? '2xl'] ?? 'sm:max-w-2xl';
@endphp

<div x-data class="contents" wire:key="{{ $id }}-host">
<template x-teleport="body">
<div
    x-data="{
        show: {{ $model ? '$wire.entangle('.\Illuminate\Support\Js::from($model).')'.($attributes->wire('model')->hasModifier('live') ? '.live' : '') : ($open ? 'true' : 'false') }},
        closing: false,
        async closeModal() {
            if (this.closing) return;
            this.closing = true;
            try {
                @if($closeAction)
                    await $wire[@js($closeAction)]();
                @else
                    this.show = false;
                @endif
                @if($returnFocus)
                    $nextTick(() => document.querySelector(@js($returnFocus))?.focus({ preventScroll: true }));
                @endif
            } finally { this.closing = false; }
        },
    }"
    x-on:close.stop="closeModal()"
    x-on:keydown.escape.prevent.stop="closeModal()"
    x-show.important="show"
    wire:ignore.self
    id="{{ $id }}"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $id }}-title"
    @if($interactiveAside) data-interactive-aside @endif
    {{ $attributes->except(['wire:model', 'wire:model.live'])->class('jetstream-modal fixed inset-0 z-[80] overflow-hidden p-2 sm:p-6') }}
    x-cloak
    style="display: none;"
>
    <div class="absolute inset-0 bg-slate-950/45" aria-hidden="true"></div>
    <div class="relative flex h-full min-h-0 items-center justify-center" x-on:click.self="closeModal()">
        <section
            x-show.important="show"
            class="relative flex max-h-full w-full min-w-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl {{ $widthClass }} {{ $panelClass }}"
            @if(! $interactiveAside) x-trap.inert.noscroll="show" @endif
            x-transition:enter="motion-safe:transition-opacity motion-safe:duration-150"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="motion-safe:transition-opacity motion-safe:duration-100"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
        >
            <header class="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-6 sm:py-4">
                <div id="{{ $id }}-title" class="min-w-0 text-lg font-medium text-slate-900">
                    @isset($title) {{ $title }} @endisset
                </div>
                <button type="button" x-on:click="closeModal()" aria-label="{{ __('Close') }}" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500">
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </header>
            <div class="min-h-0 min-w-0 flex-1 overflow-y-auto overscroll-contain scroll-container scroll-container-hover {{ $bodyClass }}" data-ui-modal-body>
                {{ $slot }}
            </div>
            @isset($actions)
                <footer class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3 text-end sm:px-6">
                    {{ $actions }}
                </footer>
            @endisset
        </section>
    </div>
</div>
</template>
</div>
@else
    {{ $slot }}
@endif
