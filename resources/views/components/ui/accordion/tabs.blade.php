@props([
    // ['anwesenheit' => 'Anwesenheit'] ODER ['anwesenheit' => ['label'=>'…','icon'=>'…']]
    'tabs' => [],
    'default' => null,
    'persistKey' => null,
    'persist' => true,
    'group' => null,
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
    ];
@endphp

<section
    id="{{ $htmlIdPrefix }}"
    {{ $attributes->merge(['class' => 'w-full']) }}
    x-data="{
        openTab: @if($persist) $persist(@js($initial)).as(@js($key)) @else @js($initial) @endif,
        hoverTab: null,
        tabIcons: {},
        compact: false,
        get expandedTab() { return this.hoverTab || this.openTab; },
        isExpanded(id) { return !this.compact || this.expandedTab === id; },
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

            this.updateCompact();
            this._updateTabCompact = () => this.updateCompact();
            window.addEventListener('resize', this._updateTabCompact);
        },
        destroy() {
            window.removeEventListener('resize', this._updateTabCompact);
        },
        updateCompact() {
            this.$nextTick(() => {
                const row = this.$refs.tabRow;

                if (!row) return;

                this.compact = false;

                this.$nextTick(() => {
                    this.compact = row.scrollWidth > row.clientWidth + 1;
                });
            });
        }
    }"
    x-init="initTabs()"
    x-on:ui-tab-icon="registerTabIcon($event)"
>
    <div class="w-full max-w-full overflow-hidden">
        <nav class="w-full max-w-full overflow-hidden" aria-label="Tabs" role="tablist" aria-orientation="horizontal">
            <ul x-ref="tabRow" class="flex w-full max-w-full justify-start overflow-hidden pt-2">
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
                        class="relative flex-none"
                        :style="{
                            zIndex: @js($tabCount - $loop->index),
                        }"
                    >
                        <button
                            type="button"
                            id="{{ $htmlIdPrefix }}-item-{{ $tabId }}"
                            aria-controls="{{ $htmlIdPrefix }}-panel-{{ $tabId }}"
                            @click.prevent="selectTab(@js($tabId))"
                            @mouseenter="hoverTab = @js($tabId)"
                            @mouseleave="hoverTab = null"
                            class="group/tab relative block overflow-hidden h-full rounded-t-md border border-b-0 border-gray-400 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-200"
                            role="tab"
                            :aria-selected="(openTab === @js($tabId)).toString()"
                            :tabindex="openTab === @js($tabId) ? 0 : -1"
                        >
                            <span
                                class="flex h-full items-center justify-center overflow-hidden px-4 py-[0.7em] text-ellipsis whitespace-nowrap transition-[width,background-color,color] duration-200 ease-out"
                                :class="[
                                    openTab === @js($tabId) ? 'bg-blue-50 text-blue-950' : 'bg-slate-300 text-slate-700 group-hover/tab:bg-blue-100 group-hover/tab:text-blue-900',
                                    @js($loop->first) ? 'pl-5' : '',
                                    @js($loop->last) ? 'pr-5' : ''
                                ]"
                            >
                                <span class="inline-flex h-5 min-w-0 items-center justify-center gap-2 align-middle leading-none"
                                    :class="[
                                        isExpanded(@js($tabId)) ? 'px-3' : 'px-0',
                                    ]"
                                >
                                    <i :class="iconClass(@js($tabId), @js($iconClass))" aria-hidden="true"></i>
                                    <span class="flex h-5 min-w-0 items-center truncate text-center leading-none" x-show="isExpanded(@js($tabId))" x-transition.opacity.duration.150ms>
                                        {{ $label }}@if($countLabel)&nbsp;{{ $countLabel }}@endif
                                    </span>
                                </span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </nav>
    </div>

    <div class="content-wrap bg-white">
        {{ $slot }}
    </div>
</section>
