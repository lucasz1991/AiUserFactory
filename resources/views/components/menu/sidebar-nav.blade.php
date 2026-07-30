@props([
    'label' => null,
])

@if(filled($label))
    <li class="ff-sidebar-section" data-ff-sidebar-section>
        {{ $label }}
    </li>
@endif

{{ $slot }}
