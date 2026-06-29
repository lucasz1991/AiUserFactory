@props([
    'id' => null,
    'model' => null,  
    'label' => null,
    'placeholder' => 'Datum wählen …',
    'required' => false,
    'min' => null,
    'max' => null,
    'enableTime' => false,
    'inline' => false, 
    'dateFormat' => 'Y-m-d',
    'altFormat' => 'Y-m-d',
    'altInput' => true,
    'mode' => 'single',
])

@php
    $inputId = $id ?: \Illuminate\Support\Str::uuid();
@endphp

<div class="w-full" wire:ignore x-data="{ inline: @js($inline), lwModel: @js($model) }">
    @if($label)
        <x-ui.forms.label for="{{ $inputId }}" :value="$label" />
    @endif
    @if($model)
        <x-ui.forms.input-error :for="$model" />
    @endif
    <input
        id="{{ $inputId }}"
        type="text"
        placeholder="{{ $placeholder }}"
        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 "
        :class="inline ? 'hidden' : ''"
        @if($required) required @endif

        x-data="{ lwModel: @js($model) }"
        x-init="
            const opts = {
                dateFormat: @js($dateFormat),
                altInput: @js($altInput),
                altFormat: @js($altFormat),
                mode: @js($mode),
                allowInput: true,
                enableTime: @js($enableTime),
                time_24hr: true,
                inline: @js($inline),
                locale: 'de',
                minDate: @js($min),
                maxDate: @js($max),
                onChange: (selectedDates, dateStr) => {
                    if (lwModel) $wire.set(lwModel, dateStr)
                },
            };

            if (lwModel) {
                const initial = $wire.get(lwModel);
                if (initial) opts.defaultDate = initial;
            }

            const fp = flatpickr($el, opts);

            if (lwModel) {
                $watch(() => $wire.get(lwModel), (val) => {
                    const current = fp.input.value;
                    const alt = fp.altInput ? fp.altInput.value : null;
                    if (!val) { fp.clear(); return; }
                    if (val !== current && val !== alt) {
                        fp.setDate(val, true);
                    }
                });
            }
        "
    />


</div>
