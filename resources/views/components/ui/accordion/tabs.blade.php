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
    $normalizedTabs = [];

    foreach ($tabs as $tabKey => $tab) {
        $tabId = (string) $tabKey;
        $isArray = is_array($tab);
        $iconClass = $isArray ? ($tab['icon'] ?? null) : null;
        $iconClass = $iconClass === 'instagram-grid' ? 'fad fa-table-cells' : $iconClass;
        $count = $isArray && array_key_exists('count', $tab) ? $tab['count'] : null;

        $normalizedTabs[] = [
            'id' => $tabId,
            'label' => $isArray ? ($tab['label'] ?? Str::title($tabId)) : $tab,
            'icon' => $iconClass ?: ($defaultIcons[$tabId] ?? 'fad fa-sliders'),
            'count_label' => $count !== null ? number_format((int) $count, 0, ',', '.') : null,
        ];
    }

    $tabCount = count($normalizedTabs);
@endphp

<section
    id="{{ $htmlIdPrefix }}"
    {{ $attributes->merge(['class' => 'w-full']) }}
    x-data="{
        openTab: @if($persist) $persist(@js($initial)).as(@js($key)) @else @js($initial) @endif,
        hoverTab: null,
        tabIcons: {},
        compact: false,
        compactFrame: null,
        compactObserver: null,
        isExpanded(id) { return !this.compact || this.openTab === id || this.hoverTab === id; },
        iconClass(id, fallback) {
            return `${this.tabIcons[id] || fallback} fa-fw shrink-0 text-center leading-none`;
        },
        registerTabIcon(event) {
            if (event.detail.group !== @js($groupKey) || !event.detail.tab || !event.detail.icon) {
                return;
            }

            this.tabIcons[event.detail.tab] = event.detail.icon;
            this.scheduleCompactUpdate();
        },
        selectTab(id) {
            this.openTab = id;
            this.$dispatch('ui-tab-selected', { group: @js($groupKey), tab: id });
        },
        initTabs() {
            if (!@js(array_map('strval', array_keys($tabs))).includes(this.openTab)) {
                this.openTab = @js((string) $initial);
            }

            this.scheduleCompactUpdate();
            this._updateTabCompact = () => this.scheduleCompactUpdate();
            window.addEventListener('resize', this._updateTabCompact);

            if ('ResizeObserver' in window) {
                this.compactObserver = new ResizeObserver(() => this.scheduleCompactUpdate());

                if (this.$refs.tabWrap) {
                    this.compactObserver.observe(this.$refs.tabWrap);
                }

                if (this.$refs.tabRow) {
                    this.compactObserver.observe(this.$refs.tabRow);
                }

                if (this.$refs.measureRow) {
                    this.compactObserver.observe(this.$refs.measureRow);
                }
            }

            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(() => this.scheduleCompactUpdate());
            }
        },
        destroy() {
            window.removeEventListener('resize', this._updateTabCompact);
            this.compactObserver?.disconnect();

            if (this.compactFrame) {
                cancelAnimationFrame(this.compactFrame);
            }
        },
        scheduleCompactUpdate() {
            if (this.compactFrame) {
                cancelAnimationFrame(this.compactFrame);
            }

            this.compactFrame = requestAnimationFrame(() => {
                this.compactFrame = null;
                this.updateCompact();
            });
        },
        updateCompact() {
            this.$nextTick(() => {
                const wrap = this.$refs.tabWrap;
                const row = this.$refs.tabRow;
                const measure = this.$refs.measureRow;

                if (!wrap || !row || !measure) return;

                const availableWidth = Math.floor(wrap.clientWidth || row.clientWidth || 0);

                if (availableWidth <= 0) return;

                const requiredWidth = Math.ceil(measure.scrollWidth || measure.getBoundingClientRect().width || 0);

                this.compact = requiredWidth > availableWidth + 1;
            });
        }
    }"
    x-init="initTabs()"
    x-on:ui-tab-icon="registerTabIcon($event)"
>
    <div x-ref="tabWrap" class="relative w-full max-w-full overflow-hidden">
        <nav class="w-full max-w-full overflow-hidden" aria-label="Tabs" role="tablist" aria-orientation="horizontal">
            <ul x-ref="tabRow" class="flex w-full max-w-full justify-start overflow-hidden pt-2">
                @foreach($normalizedTabs as $tab)
                    @php
                        $tabId = $tab['id'];
                        $label = $tab['label'];
                        $iconClass = $tab['icon'];
                        $countLabel = $tab['count_label'];
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
                                class="flex h-full items-center justify-center overflow-hidden py-[0.7em] text-ellipsis whitespace-nowrap transition-[background-color,color] duration-200 ease-out"
                                :class="[
                                    openTab === @js($tabId) ? 'bg-blue-50 text-blue-950' : 'bg-slate-300 text-slate-700 group-hover/tab:bg-blue-100 group-hover/tab:text-blue-900'
                                ]"
                            >
                                <span
                                    class="inline-flex h-5 min-w-0 items-center justify-center overflow-hidden align-middle leading-none transition-[gap,padding] duration-200 ease-out"
                                    :class="[
                                        isExpanded(@js($tabId)) ? 'gap-2 px-4' : 'gap-0 px-3',
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

        <ul x-ref="measureRow" class="pointer-events-none invisible absolute left-0 top-0 flex w-max max-w-none justify-start overflow-visible pt-2" aria-hidden="true">
            @foreach($normalizedTabs as $tab)
                <li class="relative flex-none">
                    <span class="relative block h-full overflow-hidden rounded-t-md border border-b-0 border-gray-400 text-sm font-semibold">
                        <span class="flex h-full items-center justify-center overflow-hidden py-[0.7em] text-slate-700">
                            <span class="inline-flex h-5 min-w-0 items-center justify-center gap-2 overflow-hidden px-4 align-middle leading-none">
                                <i class="{{ $tab['icon'] }} fa-fw shrink-0 text-center leading-none" aria-hidden="true"></i>
                                <span class="flex h-5 min-w-0 items-center whitespace-nowrap text-center leading-none">
                                    {{ $tab['label'] }}@if($tab['count_label'])&nbsp;{{ $tab['count_label'] }}@endif
                                </span>
                            </span>
                        </span>
                    </span>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="content-wrap bg-white">
        {{ $slot }}
    </div>
</section>
