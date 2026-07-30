@props([
    'label',
    'icon',
    'active' => false,
])

<li @class(['mm-active' => $active]) data-ff-sidebar-group-item>
    <a
        href="#"
        data-menu-active="{{ $active ? 'true' : 'false' }}"
        data-ff-sidebar-group
        aria-expanded="{{ $active ? 'true' : 'false' }}"
        {{ $attributes->class(['ff-sidebar-link', 'has-arrow', 'active' => $active]) }}
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
    </a>

    <ul @class(['mm-collapse', 'mm-show' => $active])>
        {{ $slot }}
    </ul>
</li>
