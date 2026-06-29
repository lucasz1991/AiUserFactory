@props([
    'model' => null,
    'selected' => null,
    'selectedLabel' => null,
    'placeholder' => 'Bitte waehlen',
    'options' => [],
    'disabled' => false,
    'icon' => null,
    'align' => 'right',
    'width' => 'auto',
    'dropdownClasses' => 'mt-2 w-56 bg-white rounded-lg shadow-lg border border-gray-200 overflow-hidden',
    'contentClasses' => 'bg-white',
    'panelClass' => ' text-sm text-gray-700 max-h-80 overflow-y-auto',
    'buttonClass' => 'h-[30px] inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-1 text-sm text-gray-700 shadow-sm transition hover:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400',
    'triggerTextClass' => 'truncate',
    'groupLabelClass' => 'px-3 pt-2 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400',
    'offset' => 6,
    'overlay' => false,
    'trap' => false,
    'scrollOnOpen' => false,
    'scrollOnTrigger' => false,
    'headerOffset' => 0,
    'matchTriggerWidth' => false,
    'showChevron' => true,
])

@php
    $hasCustomContent = trim((string) ($content ?? '')) !== '';
    $selectedValue = $selected ?? '';

    $flatOptions = collect($options)->flatMap(function ($option) {
        if (isset($option['options']) && is_array($option['options'])) {
            return $option['options'];
        }

        return [$option];
    });

    $currentOption = $flatOptions->first(function ($option) use ($selectedValue) {
        return (string) ($option['value'] ?? '') === (string) $selectedValue;
    });

    $triggerLabel = $selectedLabel ?? ($currentOption['label'] ?? $placeholder);
@endphp

<div class="relative">
    <x-ui.dropdown.anchor-dropdown
        :align="$align"
        :width="$width"
        :dropdown-classes="$dropdownClasses"
        :content-classes="$contentClasses"
        :overlay="$overlay"
        :trap="$trap"
        :scroll-on-open="$scrollOnOpen"
        :scroll-on-trigger="$scrollOnTrigger"
        :header-offset="$headerOffset"
        :match-trigger-width="$matchTriggerWidth"
        :offset="$offset"
    >
        <x-slot name="trigger">
            <button
                type="button"
                class="{{ $buttonClass }}"
                @disabled($disabled)
            >
                @if($icon)
                    <i class="{{ $icon }}"></i>
                @endif

                <span class="{{ $triggerTextClass }}">
                    {{ $triggerLabel }}
                </span>

                @if($showChevron)
                    <i class="fal fa-angle-down ml-1 text-xs text-gray-500"></i>
                @endif
            </button>
        </x-slot>

        <x-slot name="content">
            <div
                class="{{ $panelClass }}"
                @click="if ($event.target.closest('[data-lz-select-option]')) { $dispatch('close') }"
            >
                @if($hasCustomContent)
                    {{ $content }}
                @else
                    @foreach($options as $option)
                        @if(isset($option['options']) && is_array($option['options']))
                            @if(!empty($option['label']))
                                <div class="{{ $groupLabelClass }}">
                                    {{ $option['label'] }}
                                </div>
                            @endif

                            @foreach($option['options'] as $groupOption)
                                @php
                                    $optionValue = $groupOption['value'] ?? '';
                                    $isSelected = (string) $optionValue === (string) $selectedValue;
                                @endphp

                                <button
                                    type="button"
                                    data-lz-select-option
                                    wire:click="$set('{{ addslashes((string) $model) }}', '{{ addslashes((string) $optionValue) }}')"
                                    @class([
                                        'flex w-full items-start gap-3 px-3 py-2 text-left hover:bg-gray-50 cursor-pointer',
                                        'bg-sky-50 text-sky-700' => $isSelected,
                                    ])
                                >
                                    <span class="flex min-w-0 flex-1 flex-col">
                                        <span class="font-medium">
                                            {{ $groupOption['label'] ?? '' }}
                                        </span>

                                        @if(!empty($groupOption['description']))
                                            <span class="text-xs text-gray-500">
                                                {{ $groupOption['description'] }}
                                            </span>
                                        @endif
                                    </span>

                                    @if($isSelected)
                                        <i class="fal fa-check mt-0.5 text-sky-600"></i>
                                    @endif
                                </button>
                            @endforeach
                        @else
                            @php
                                $optionValue = $option['value'] ?? '';
                                $isSelected = (string) $optionValue === (string) $selectedValue;
                            @endphp

                            <button
                                type="button"
                                data-lz-select-option
                                wire:click="$set('{{ addslashes((string) $model) }}', '{{ addslashes((string) $optionValue) }}')"
                                @class([
                                    'flex w-full items-start gap-3 px-3 py-2 text-left hover:bg-gray-50 cursor-pointer',
                                    'bg-sky-50 text-sky-700' => $isSelected,
                                ])
                            >
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span class="font-medium">
                                        {{ $option['label'] ?? '' }}
                                    </span>

                                    @if(!empty($option['description']))
                                        <span class="text-xs text-gray-500">
                                            {{ $option['description'] }}
                                        </span>
                                    @endif
                                </span>

                                @if($isSelected)
                                    <i class="fal fa-check mt-0.5 text-sky-600"></i>
                                @endif
                            </button>
                        @endif
                    @endforeach
                @endif
            </div>
        </x-slot>
    </x-ui.dropdown.anchor-dropdown>
</div>
