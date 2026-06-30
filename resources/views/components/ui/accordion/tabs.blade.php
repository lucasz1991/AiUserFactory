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
    $shapeId = $htmlIdPrefix.'-shape';
@endphp

<section
    id="{{ $htmlIdPrefix }}"
    {{ $attributes->merge(['class' => 'w-full']) }}
    x-data="{
        openTab: @if($persist) $persist(@js($initial)).as(@js($key)) @else @js($initial) @endif,
        hoverTab: null,
        tabWidths: {},
        compactWidth: 144,
        overlapOffset: 18,
        items: (function() {
            const out = [];
            @foreach($tabs as $k => $tab)
                @php
                    $isArray   = is_array($tab);
                    $label     = $isArray ? ($tab['label'] ?? Str::title($k)) : $tab;
                    $iconClass = $isArray ? ($tab['icon']  ?? null) : null;
                    $count     = $isArray && array_key_exists('count', $tab) ? $tab['count'] : null;
                    $countLabel = $count !== null ? number_format((int) $count, 0, ',', '.') : null;
                @endphp
                out.push({ id: @js((string) $k), label: @js($label), icon: @js($iconClass), countLabel: @js($countLabel) });
            @endforeach
            return out;
        })(),
        get active() { return this.items.find(t => t.id === this.openTab) ?? this.items[0]; },
        get expandedTab() { return this.hoverTab || this.openTab; },
        isExpanded(id) {
            return this.expandedTab === id;
        },
        tabDisplayWidth(id) {
            const fullWidth = this.tabWidths[id] || 160;

            return (this.isExpanded(id) ? fullWidth : Math.min(fullWidth, this.compactWidth)) + 'px';
        },
        selectTab(id) {
            this.openTab = id;
            this.$dispatch('ui-tab-selected', { group: @js($groupKey), tab: id });
            this.$nextTick(() => this.updateOverlap());
        },
        initTabs() {
            if (!this.items.some(t => t.id === this.openTab)) {
                this.openTab = this.items[0]?.id ?? this.openTab;
            }

            this.measureTabs();
            window.addEventListener('resize', () => this.measureTabs());
        },
        measureTabs() {
            this.$nextTick(() => {
                const widths = {};

                this.$root.querySelectorAll('[data-shape-tab-measure]').forEach((node) => {
                    widths[node.dataset.shapeTabId] = Math.ceil(node.scrollWidth + 56);
                });

                this.tabWidths = widths;
                this.updateOverlap();
            });
        },
        updateOverlap() {
            const row = this.$refs.shapeTabRow;
            const count = this.items.length;

            if (!row || count <= 1) {
                this.overlapOffset = 0;
                return;
            }

            const minOverlap = 18;
            const minCompactWidth = 76;
            const maxCompactWidth = 144;
            const expandedWidth = this.tabWidths[this.expandedTab] || 160;
            const remainingCount = count - 1;
            const availableWidth = Math.max(240, row.clientWidth);
            const fittedCompactWidth = Math.floor((availableWidth - expandedWidth + (minOverlap * remainingCount)) / remainingCount);

            this.compactWidth = Math.min(maxCompactWidth, Math.max(minCompactWidth, fittedCompactWidth));

            const totalWidth = expandedWidth + (this.compactWidth * remainingCount);
            const neededOverlap = Math.ceil(Math.max(0, totalWidth - availableWidth) / remainingCount) + minOverlap;
            const maxOverlap = Math.max(minOverlap, this.compactWidth - 28);

            this.overlapOffset = Math.min(maxOverlap, Math.max(minOverlap, neededOverlap));
        }
    }"
    x-init="initTabs()"
>
    <svg class="hidden" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <path id="{{ $shapeId }}" d="M80,60C34,53.5,64.5,0,0,0v60H80z"></path>
        </defs>
    </svg>

    <div class="tabs tabs-style-shape w-full max-w-[1200px] overflow-visible">
        <nav aria-label="Tabs" role="tablist" aria-orientation="horizontal">
            <ul x-ref="shapeTabRow" class="shape-tabs-list flex w-full justify-start overflow-visible max-[58em]:pt-3">
                <template x-for="(t, index) in items" :key="t.id">
                    <li
                        class="shape-tabs-item relative flex-none"
                        :class="{ 'tab-current': openTab === t.id }"
                        :style="{
                            marginLeft: index > 0 ? `-${overlapOffset}px` : '0',
                            zIndex: items.length - index,
                        }"
                    >
                        <button
                            type="button"
                            @click.prevent="selectTab(t.id)"
                            @mouseenter="hoverTab = t.id; updateOverlap()"
                            @mouseleave="hoverTab = null; updateOverlap()"
                            :id="@js($htmlIdPrefix) + '-item-' + t.id"
                            :aria-controls="@js($htmlIdPrefix) + '-panel-' + t.id"
                            class="shape-tabs-trigger group relative block overflow-visible p-0 text-sm font-medium text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-200"
                            role="tab"
                            :aria-selected="openTab === t.id"
                            :tabindex="openTab === t.id ? 0 : -1"
                        >
                            <svg
                                x-show="index < items.length - 1"
                                viewBox="0 0 80 60"
                                preserveAspectRatio="none"
                                class="shape-tab-svg pointer-events-none absolute left-full top-0 m-0 h-full w-12"
                                :class="openTab === t.id ? 'fill-blue-50' : 'fill-slate-300 group-hover:fill-blue-100'"
                                aria-hidden="true"
                                focusable="false"
                            >
                                <use class="pointer-events-auto" href="#{{ $shapeId }}" xlink:href="#{{ $shapeId }}"></use>
                            </svg>
                            <svg
                                x-show="index > 0"
                                viewBox="0 0 80 60"
                                preserveAspectRatio="none"
                                class="shape-tab-svg pointer-events-none absolute right-full top-0 m-0 h-full w-12 -scale-x-100"
                                :class="openTab === t.id ? 'fill-blue-50' : 'fill-slate-300 group-hover:fill-blue-100'"
                                aria-hidden="true"
                                focusable="false"
                            >
                                <use class="pointer-events-auto" href="#{{ $shapeId }}" xlink:href="#{{ $shapeId }}"></use>
                            </svg>
                            <span
                                class="shape-tab-label block overflow-hidden px-4 py-[0.65em] text-ellipsis whitespace-nowrap"
                                :style="{ width: tabDisplayWidth(t.id) }"
                                :class="[
                                    openTab === t.id ? 'bg-blue-50 text-blue-950' : 'bg-slate-300 text-slate-700 group-hover:bg-blue-100 group-hover:text-blue-900',
                                    index === 0 ? 'rounded-tl-[30px] pl-8' : '',
                                    index === items.length - 1 ? 'rounded-tr-[30px] pr-8' : ''
                                ]"
                            >
                                <span class="inline-flex items-center gap-2" :data-shape-tab-id="t.id" data-shape-tab-measure>
                                    <template x-if="t.icon === 'instagram-grid'">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <rect x="4" y="4" width="6" height="6" stroke="currentColor" stroke-width="2"></rect>
                                            <rect x="14" y="4" width="6" height="6" stroke="currentColor" stroke-width="2"></rect>
                                            <rect x="4" y="14" width="6" height="6" stroke="currentColor" stroke-width="2"></rect>
                                            <rect x="14" y="14" width="6" height="6" stroke="currentColor" stroke-width="2"></rect>
                                        </svg>
                                    </template>
                                    <template x-if="t.icon && t.icon !== 'instagram-grid'">
                                        <i :class="t.icon + ' fa-lg'" aria-hidden="true"></i>
                                    </template>
                                    <span>
                                        <span x-text="t.label"></span><template x-if="t.countLabel"><span>&nbsp;<span x-text="t.countLabel"></span></span></template>
                                    </span>
                                </span>
                            </span>
                        </button>
                    </li>
                </template>
            </ul>
        </nav>
    </div>

    <div class="content-wrap bg-white">
        {{ $slot }}
    </div>
</section>
