@props([
    'for' => null,
    'active' => null,
    'group' => null,
    'icon' => null,
    'panelClass' => ' rounded-b-lg rounded-se-lg border border-blue-300 z-10',
])

@php
    $panelFor = (string) $for;
    $activeTab = (string) ($active ?? '');
    $groupKey = (string) ($group ?? '');
    $iconClass = trim((string) ($icon ?? ''));
    $htmlIdPrefix = 'tabs-'.substr(md5($groupKey), 0, 10);
    $isInitiallyActive = $activeTab === '' || $activeTab === $panelFor;
@endphp

<div
    id="{{ $htmlIdPrefix }}-panel-{{ $panelFor }}"
    x-init="$nextTick(() => { if (@js($iconClass) !== '') { $dispatch('ui-tab-icon', { group: @js($groupKey), tab: @js($panelFor), icon: @js($iconClass) }); } })"
    x-show.important="openTab === @js($panelFor)"
    x-transition.opacity.duration.150ms
    x-cloak
    role="tabpanel"
    aria-labelledby="{{ $htmlIdPrefix }}-item-{{ $panelFor }}"
    :aria-hidden="(openTab !== @js($panelFor)).toString()"
    @unless($isInitiallyActive) style="display: none;" @endunless
>
    <div class="{{ $panelClass }}">
        {{ $slot }}
    </div>
</div>
