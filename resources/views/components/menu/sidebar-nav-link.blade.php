@props([
    'href',
    'icon' => null,
    'active' => false,
    'nested' => false,
    'navigate' => true,
])

<li>
    <a
        href="{{ $href }}"
        data-menu-active="{{ $active ? 'true' : 'false' }}"
        data-ff-sidebar-link
        wire:current="active"
        @if($active) aria-current="page" @endif
        {{ $attributes->class([
            'ff-sidebar-link',
            'ff-sidebar-link--nested' => $nested,
            'active' => $active,
        ]) }}
        @if($navigate) wire:navigate @endif
    >
        @if($icon)
            <span class="ff-sidebar-link__icon" aria-hidden="true">
                <i data-feather="{{ $icon }}"></i>
            </span>
        @endif
        <span class="ff-sidebar-link__label">{{ $slot }}</span>
    </a>
</li>
