@props(['id' => null, 'maxWidth' => null])

@php
$id = $id ?? md5($attributes->wire('model'));

$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
    '3xl' => 'sm:max-w-3xl',
    '4xl' => 'sm:max-w-4xl',
    '5xl' => 'sm:max-w-5xl',
    '6xl' => 'sm:max-w-6xl',
    '7xl' => 'sm:max-w-7xl',
][$maxWidth ?? '2xl'];
@endphp

<div
    x-data="{ show: @entangle($attributes->wire('model')) }"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    x-show="show"
    id="{{ $id }}"
    class="jetstream-modal fixed inset-0 z-[80] overflow-y-auto px-4 py-4 sm:px-6 sm:py-8 max-h-screen"
    style="display: none;"
>
    <div x-show="show" class="fixed inset-0 transform transition-all"  x-transition:enter="ease-out duration-300"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-200"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0">
        <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
    </div>

    <div class="flex min-h-full items-center justify-center">
        <div x-show="show" class="relative z-10 flex max-h-[calc(100vh-2rem)] w-full flex-col overflow-hidden rounded-lg bg-white shadow-xl transform transition-all sm:max-h-[calc(100vh-4rem)] {{ $maxWidth }}"
                    x-trap.inert.noscroll="show"
                    x-transition:enter="ease-out duration-300"
                    x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-200"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
            <div class="flex shrink-0 items-start justify-between gap-4 border-b border-gray-200 px-6 py-4">
                <div class="text-lg font-medium text-gray-900">
                    @isset($title)
                        {{ $title }}
                    @endisset
                </div>

                <button
                    type="button"
                    class="-m-2 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    x-on:click="show = false"
                >
                    <span class="sr-only">{{ __('Close') }}</span>
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto scroll-container">
                {{ $slot }}
            </div>

            @isset($actions)
                <div class="flex shrink-0 flex-row justify-end gap-3 border-t border-gray-200 bg-gray-50 px-6 py-4 text-end">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    </div>
</div>
