@props([
    'label',
    'icon',
    'active' => false,
    'id' => null,
])

@php
    $groupId = $id ?: 'sidebar-group-'.\Illuminate\Support\Str::slug($label);
    $triggerId = $groupId.'-trigger';
@endphp

<li @class(['mm-active' => $active]) data-ff-sidebar-group-item>
    <button
        type="button"
        id="{{ $triggerId }}"
        data-menu-active="{{ $active ? 'true' : 'false' }}"
        data-ff-sidebar-group
        aria-expanded="{{ $active ? 'true' : 'false' }}"
        aria-controls="{{ $groupId }}"
        {{ $attributes->class(['ff-sidebar-link', 'has-arrow', 'w-full', 'text-left', 'active' => $active]) }}
    >
        <span class="ff-sidebar-link__icon" aria-hidden="true">
            <i data-feather="{{ $icon }}"></i>
        </span>
        <span class="ff-sidebar-link__label">{{ $label }}</span>
        <span class="ff-sidebar-link__chevron" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
                <path d="m9 18 6-6-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>
    </button>

    <ul
        id="{{ $groupId }}"
        aria-labelledby="{{ $triggerId }}"
        @class(['mm-collapse', 'mm-show' => $active])
    >
        {{ $slot }}
    </ul>
</li>
