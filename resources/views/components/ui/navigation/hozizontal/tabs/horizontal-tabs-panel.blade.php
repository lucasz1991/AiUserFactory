@props([
    // ['anwesenheit' => 'Anwesenheit'] ODER ['anwesenheit' => ['label'=>'…','icon'=>'…']]
    'tabs' => [],
    'default' => null,
    'persistKey' => null,
    'persist' => true,
    'syncOnInit' => false,
    'group' => null,
    'tabListClass' => 'pt-2',
    'contentClass' => 'content-wrap bg-white',
    'variant' => 'primary',
    // optional: 'sm' | 'md' | 'lg' | 'xl' | '2xl'
    'collapseAt' => null,
])

@php
    use Illuminate\Support\Str;

    $firstKey   = array_key_first($tabs);
    $initial    = $default ?? $firstKey ?? 'tab-1';

    $routeName  = optional(request()->route())->getName() ?? request()->path();
    $tabsSig    = implode(',', array_keys($tabs));
    $autoKey    = 'tabs:' . $routeName . $tabsSig;

    $key = $persistKey ?: $autoKey;
    $groupKey = $group ?: $key;
    $htmlIdPrefix = 'tabs-'.substr(md5($groupKey), 0, 10);
    $tabCount = count($tabs);
    $tabVariant = in_array($variant, ['primary', 'subnav'], true) ? $variant : 'primary';
    $isSubnav = $tabVariant === 'subnav';
    $stripClass = $isSubnav
        ? 'border-y border-slate-200 bg-slate-50/95 px-3 py-2'
        : 'border-b border-slate-200 bg-slate-100/80 px-2 pt-2';
    $listClass = $isSubnav
        ? 'flex w-full max-w-full justify-start gap-1.5 overflow-x-auto overflow-y-hidden'
        : 'flex w-full max-w-full justify-start gap-1 overflow-x-auto overflow-y-hidden';
    $itemClass = $isSubnav ? 'relative flex-none' : 'relative -mb-px flex-none';
    $buttonClass = $isSubnav
        ? 'group/tab relative block h-full overflow-hidden rounded-md border text-xs font-semibold shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-200'
        : 'group/tab relative block h-full overflow-hidden rounded-t-lg border border-b-0 text-sm font-semibold shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-200';
    $buttonActiveClass = $isSubnav
        ? 'border-blue-200 bg-white text-blue-800 shadow-sm ring-1 ring-blue-100'
        : 'border-slate-200 bg-white text-blue-950 shadow-sm';
    $buttonInactiveClass = $isSubnav
        ? 'border-transparent bg-slate-100 text-slate-600 shadow-none hover:border-slate-200 hover:bg-white hover:text-slate-800'
        : 'border-slate-200 bg-slate-200/80 text-slate-600 shadow-none hover:bg-slate-50 hover:text-blue-900';
    $labelClass = $isSubnav
        ? 'flex h-full items-center justify-center overflow-hidden px-3 py-2 text-ellipsis whitespace-nowrap'
        : 'flex h-full items-center justify-center overflow-hidden px-4 py-2.5 text-ellipsis whitespace-nowrap';
    $countClass = $isSubnav
        ? 'ml-1.5 inline-flex min-w-5 items-center justify-center rounded-full bg-blue-50 px-1.5 py-0.5 text-[11px] font-bold text-blue-700 ring-1 ring-blue-100'
        : 'ml-2 inline-flex min-w-5 items-center justify-center rounded-full bg-slate-100 px-1.5 py-0.5 text-[11px] font-bold text-slate-600 ring-1 ring-slate-200';
    $defaultIcons = [
        'quelle-suche' => 'fad fa-database',
        'filter' => 'fad fa-filter',
        'datum-warten' => 'fad fa-clock',
        'oeffnen' => 'fad fa-envelope-open',
        'wert-ermitteln' => 'fad fa-magnifying-glass',
        'ergebnis' => 'fad fa-check',
        'ausfuehrung' => 'fad fa-play',
        'eingabe' => 'fad fa-keyboard',
        'daten' => 'fad fa-code',
        'session' => 'fad fa-cookie',
        'loeschen' => 'fad fa-trash-can',
    ];
@endphp

<section
    id="{{ $htmlIdPrefix }}"
    {{ $attributes->merge(['class' => 'w-full']) }}
    x-data="{
        openTab: @if($persist) $persist(@js($initial)).as(@js($key)) @else @js($initial) @endif,
        tabIcons: {},
        iconClass(id, fallback) {
            return `${this.tabIcons[id] || fallback} fa-fw shrink-0 text-center leading-none`;
        },
        registerTabIcon(event) {
            if (event.detail.group !== @js($groupKey) || !event.detail.tab || !event.detail.icon) {
                return;
            }

            this.tabIcons[event.detail.tab] = event.detail.icon;
        },
        selectTab(id) {
            this.openTab = id;
            this.$dispatch('ui-tab-selected', { group: @js($groupKey), tab: id });
        },
        initTabs() {
            if (!@js(array_map('strval', array_keys($tabs))).includes(this.openTab)) {
                this.openTab = @js((string) $initial);
            }

            if (@js($syncOnInit) && this.openTab !== @js((string) $initial)) {
                this.$nextTick(() => this.$dispatch('ui-tab-selected', { group: @js($groupKey), tab: this.openTab }));
            }
        }
    }"
    x-init="initTabs()"
    x-on:ui-tab-icon="registerTabIcon($event)"
>
    <div class="w-full max-w-full overflow-hidden {{ $stripClass }}">
        <nav class="w-full max-w-full overflow-hidden" aria-label="Tabs" role="tablist" aria-orientation="horizontal">
            <ul x-ref="tabRow" class="{{ $listClass }} {{ $tabListClass }}">
                @foreach($tabs as $tabKey => $tab)
                    @php
                        $tabId = (string) $tabKey;
                        $isArray = is_array($tab);
                        $label = $isArray ? ($tab['label'] ?? Str::title($tabId)) : $tab;
                        $iconClass = $isArray ? ($tab['icon'] ?? null) : null;
                        $iconClass = $iconClass === 'instagram-grid' ? 'fad fa-table-cells' : $iconClass;
                        $iconClass = $iconClass ?: ($defaultIcons[$tabId] ?? 'fad fa-sliders');
                        $count = $isArray && array_key_exists('count', $tab) ? $tab['count'] : null;
                        $countLabel = $count !== null ? number_format((int) $count, 0, ',', '.') : null;
                    @endphp
                    <li
                        class="{{ $itemClass }}"
                        :style="{
                            zIndex: @js($tabCount - $loop->index),
                        }"
                    >
                        <button
                            type="button"
                            id="{{ $htmlIdPrefix }}-item-{{ $tabId }}"
                            aria-controls="{{ $htmlIdPrefix }}-panel-{{ $tabId }}"
                            aria-label="{{ $label }}@if($countLabel) {{ $countLabel }}@endif"
                            @click.prevent="selectTab(@js($tabId))"
                            class="{{ $buttonClass }}"
                            role="tab"
                            :aria-selected="(openTab === @js($tabId)).toString()"
                            :tabindex="openTab === @js($tabId) ? 0 : -1"
                            :class="openTab === @js($tabId) ? @js($buttonActiveClass) : @js($buttonInactiveClass)"
                        >
                            @if($isSubnav)
                                <span
                                    aria-hidden="true"
                                    class="absolute left-2 top-1/2 h-1.5 w-1.5 -translate-y-1/2 rounded-full bg-blue-600 transition-opacity"
                                    :class="openTab === @js($tabId) ? 'opacity-100' : 'opacity-0'"
                                ></span>
                            @else
                                <span
                                    aria-hidden="true"
                                    class="absolute inset-x-2 top-0 h-0.5 rounded-full bg-blue-600 transition-opacity"
                                    :class="openTab === @js($tabId) ? 'opacity-100' : 'opacity-0'"
                                ></span>
                            @endif
                            <span
                                class="{{ $labelClass }} transition-[background-color,color] duration-200 ease-out"
                                :class="[
                                    @js($isSubnav) ? 'pl-5' : '',
                                    @js($loop->first) ? 'pl-5' : '',
                                    @js($loop->last) ? 'pr-5' : ''
                                ]"
                            >
                                <span class="inline-flex h-5 min-w-0 items-center justify-center gap-2 align-middle leading-none">
                                    <i :class="iconClass(@js($tabId), @js($iconClass))" aria-hidden="true"></i>
                                    <span class="flex h-5 min-w-0 items-center truncate text-center leading-none">
                                        {{ $label }}
                                    </span>
                                    @if($countLabel)
                                        <span class="{{ $countClass }}">{{ $countLabel }}</span>
                                    @endif
                                </span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </nav>
    </div>

    @if(! $slot->isEmpty())
        <div @class([$contentClass => $contentClass !== ''])>
            {{ $slot }}
        </div>
    @endif
</section>
