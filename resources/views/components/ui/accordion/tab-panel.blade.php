@props([
    'for' => null,
    'active' => null,
    'group' => null,
    'panelClass' => 'space-y-4 bg-white p-4 rounded-b-lg rounded-se-lg border border-blue-300 z-10',
])

@php
    $panelFor = (string) $for;
    $activeTab = (string) ($active ?? '');
    $groupKey = (string) ($group ?? '');
@endphp

<div
    x-data="{ localOpenTab: @js($activeTab) }"
    x-init="if (typeof openTab !== 'undefined' && openTab !== null) { localOpenTab = openTab; $watch('openTab', value => localOpenTab = value); }"
    x-show="localOpenTab === @js($panelFor)"
    x-on:ui-tab-selected.window="if (@js($groupKey) === '' || $event.detail.group === @js($groupKey)) localOpenTab = $event.detail.tab"
    x-cloak
    role="tabpanel"
    :aria-hidden="(localOpenTab !== @js($panelFor)).toString()"
    class="{{ $panelClass }}"
>
    {{ $slot }}
</div>
