@php
    $supportedLocales = config('app.supported_locales', []);
    $currentLocale = app()->getLocale();
    $labels = [
        'de' => __('base.german'),
        'en' => __('base.english'),
    ];
@endphp

@if (count($supportedLocales) > 1)
    <x-dropdown align="right" width="48">
        <x-slot name="trigger">
            <button
                class="flex items-center space-x-2 text-sm border border-gray-200 rounded-full px-3 py-1.5 hover:bg-gray-50 transition">
                <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold uppercase text-gray-700">
                    {{ strtoupper($currentLocale) }}
                </span>
                <span class="hidden xl:inline-block text-gray-700 font-medium">
                    {{ $labels[$currentLocale] ?? strtoupper($currentLocale) }}
                </span>
                <i class="hidden xl:block mdi mdi-chevron-down text-gray-500"></i>
            </button>
        </x-slot>

        <x-slot name="content">
            <div class="py-1">
                @foreach ($supportedLocales as $locale)
                    @php $isActive = $locale === $currentLocale; @endphp
                    <form method="POST" action="{{ route('locale.switch', $locale) }}">
                        @csrf
                        <button type="submit"
                            class="flex w-full items-center px-4 py-2 text-sm text-left hover:bg-gray-100 {{ $isActive ? 'font-semibold text-gray-900' : 'text-gray-700' }}">
                            <span class="inline-flex h-6 w-6 items-center justify-center rounded border border-gray-200 text-[10px] font-bold uppercase mr-2">
                                {{ $locale }}
                            </span>
                            <span class="flex-1">
                                {{ $labels[$locale] ?? strtoupper($locale) }}
                            </span>
                            @if ($isActive)
                                <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" stroke-width="2"
                                    viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            @endif
                        </button>
                    </form>
                @endforeach
            </div>
        </x-slot>
    </x-dropdown>
@endif
