{{-- resources/views/components/ui/forms/time-input.blade.php --}}
@props([
    'id' => null,
    'name' => null,
    'label' => null,
    'hint' => null,
    'value' => null,          // initialer Wert: "HH:ii"
    'min' => null,            // z.B. "08:00"
    'max' => null,            // z.B. "23:00"
    'placeholder' => 'HH:MM',
    'required' => false,
])

@php
    // minuteIncrement aus dem step-Attribut (Sekunden) ableiten: 300s => 5 Min
    $stepAttr = $attributes->get('step');
    $minuteIncrement = 5;
    if (is_numeric($stepAttr) && (int)$stepAttr > 0) {
        $minuteIncrement = max(1, (int)$stepAttr / 60);
    }

    $inputId = $id ?? $name ?? 'time_'.uniqid();
@endphp



<div class="space-y-1" x-data="{
    fp: null,
    init() {
        const el = this.$refs.input;

        // Flatpickr initialisieren
        this.fp = flatpickr(el, {
            enableTime: true,
            noCalendar: true,
            dateFormat: 'H:i',
            time_24hr: true,
            allowInput: true,
            minuteIncrement: {{ (int)$minuteIncrement }},
            {{ $min ? "minTime: '{$min}'," : '' }}
            {{ $max ? "maxTime: '{$max}'," : '' }}
            defaultDate: el.value || null,
            onOpen: () => { /* optional: Platz für Logik beim Öffnen */ },
            onChange: (selectedDates, dateStr) => {
                el.value = dateStr ?? '';
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        // Wenn der Wert extern (Livewire-Update) geändert wird, Flatpickr syncen
        el.addEventListener('input', () => {
            if (this.fp && this.fp.input.value !== el.value) {
                this.fp.setDate(el.value || null, false);
            }
        });
    }
}">
    @if($label)
        <label for="{{ $inputId }}" class="block text-sm font-medium text-gray-700 dark:text-gray-200">
            {{ $label }} @if($required)<span class="text-red-600">*</span>@endif
        </label>
    @endif

    <div class="relative">
        <input
            x-ref="input"
            id="{{ $inputId }}"
            name="{{ $name }}"
            type="text"
            inputmode="numeric"
            placeholder="{{ $placeholder }}"
            value="{{ old($name, $value) }}"
            @if($required) required @endif
            pattern="^\d{2}:\d{2}$"
            {{ $attributes->merge([
                'class' => 'block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100 disabled:text-gray-500',
                // Hinweis: min/max bei Flatpickr werden via JS gesetzt; HTML-Attribute sind optional
                'autocomplete' => 'off',
            ]) }}
        />

        <div class="pointer-events-none absolute inset-y-0 right-3 flex items-center">
            <i class="fal fa-clock text-gray-500 fa-lg"></i>
        </div>
    </div>

    @if($hint)
        <p class="text-xs text-gray-500">{{ $hint }}</p>
    @endif

    @error($name)
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
