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
    x-data="{ localOpenTab: @js($activeTab) }"
    x-init="$nextTick(() => { if (typeof openTab !== 'undefined' && openTab !== null) { localOpenTab = openTab; $watch('openTab', value => localOpenTab = value); } if (@js($iconClass) !== '') { $dispatch('ui-tab-icon', { group: @js($groupKey), tab: @js($panelFor), icon: @js($iconClass) }); } })"
    x-show="localOpenTab === @js($panelFor)"
    x-collapse
    x-on:ui-tab-selected.window="if (@js($groupKey) === '' || $event.detail.group === @js($groupKey)) localOpenTab = $event.detail.tab"
    x-cloak
    role="tabpanel"
    aria-labelledby="{{ $htmlIdPrefix }}-item-{{ $panelFor }}"
    :aria-hidden="(localOpenTab !== @js($panelFor)).toString()"
    @unless($isInitiallyActive) style="display: none;" @endunless
>
    <div class="{{ $panelClass }}">
        {{ $slot }}
    </div>
</div>
