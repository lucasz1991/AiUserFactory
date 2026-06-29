@props([
    'id'    => 'toggle-' . Str::random(6),
    'label' => null,
    'model' => null,   // z. B. maintenanceMode
    'alpineModel' => null,
    'change' => null,  // optional: JS change handler
    'checked' => false,
])

<label for="{{ $id }}" class="flex items-center cursor-pointer select-none">
    <input 
        id="{{ $id }}"
        type="checkbox"
        @if($model) wire:model.live="{{ $model }}" @endif
        @if($alpineModel) x-model="{{ $alpineModel }}" @endif
        @if($change) @change="{{ $change }}" @endif
        @checked($checked)
        class="sr-only peer"
    />

    {{-- Slider --}}
    <div class="relative w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-4
                peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer
                dark:bg-gray-700
                peer-checked:after:translate-x-full
                rtl:peer-checked:after:-translate-x-full
                peer-checked:after:border-white
                after:content-[''] after:absolute after:top-[2px] after:start-[2px]
                after:bg-white after:border-gray-300 after:border after:rounded-full
                after:h-4 after:w-4 after:transition-all dark:border-gray-600
                peer-checked:bg-blue-600">
    </div>

    @if($label)
        <span class="ms-3 text-sm font-medium text-gray-900 dark:text-gray-300">
            {{ $label }}
        </span>
    @endif
</label>
