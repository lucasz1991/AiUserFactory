@props([
    // ['anwesenheit' => 'Anwesenheit'] ODER ['anwesenheit' => ['label'=>'…','icon'=>'…']]
    'tabs' => [],
    'default' => null,
    'persistKey' => null,
    'persist' => true,
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
@endphp

<div
    x-data="{
        openTab: @if($persist) $persist(@js($initial)).as(@js($key)) @else @js($initial) @endif,
        collapsed: false,
        forceCollapsed: false,
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
                out.push({ id: '{{ $k }}', label: @js($label), icon: @js($iconClass), countLabel: @js($countLabel) });
            @endforeach
            return out;
        })(),
        get active() { return this.items.find(t => t.id === this.openTab) ?? this.items[0]; },
        get others() { return this.items.filter(t => t.id !== this.openTab); },
        selectTab(id) {
            this.openTab = id;
            this.$dispatch('ui-tab-selected', { tab: id });
        },
        mq: null,
        setupMQ(bp) {
            if (!bp) return;
            const map = { sm:640, md:768, lg:1024, xl:1280, '2xl':1536 };
            const px  = map[bp];
            if (!px) return;
            this.mq = window.matchMedia(`(min-width: ${px}px)`);
            const update = () => { this.forceCollapsed = !this.mq.matches; };
            this.mq.addEventListener?.('change', update);
            update();
        },
        onResize() {
            if (this.forceCollapsed) { this.collapsed = true; return; }
            // Falls du zusätzlich overflow-basiert kollabieren willst, ent-kommentieren:
            // const row = this.$refs.row; this.collapsed = row ? (row.scrollWidth > row.clientWidth) : false;
            this.collapsed = false;
        }
    }"
    x-init="setupMQ(@js($collapseAt)); onResize(); $watch('openTab', () => onResize())"
    class="w-full"
    role="tablist"
>
    <div class="border-b border-slate-200" x-ref="row" x-resize.debounce.150ms="onResize()">
        <!-- Normalmodus: alle Tabs (Layout unverändert) -->
        <template x-if="!collapsed">
            <div class="flex justify-center overflow-x-auto">
                <template x-for="t in items" :key="t.id">
                    <button
                        type="button"
                        @click.prevent="selectTab(t.id)"
                        :class="openTab === t.id
                            ? '-mb-px border-t-slate-950 text-slate-950'
                            : 'border-t-transparent text-slate-500 hover:text-slate-900'"
                        class="inline-flex shrink-0 items-center gap-2 border-t px-5 py-3 text-[11px] font-bold uppercase tracking-[0.18em] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-300 sm:px-6"
                        role="tab"
                        :aria-selected="openTab === t.id"
                        :tabindex="openTab === t.id ? 0 : -1"
                    >
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
                        <span class="whitespace-nowrap">
                            <span x-text="t.label"></span><template x-if="t.countLabel"><span>&nbsp;<span x-text="t.countLabel"></span></span></template>
                        </span>
                    </button>
                </template>
            </div>
        </template>

        <!-- Collapsed: aktiver Tab + Menü (Buttons behalten deine Klassen) -->
        <template x-if="collapsed">
            <div class="flex w-full justify-center">
                <button
                    type="button"
                    class="-mb-px inline-flex shrink-0 items-center gap-2 border-t border-t-slate-950 px-5 py-3 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-300"
                    role="tab" aria-selected="true" tabindex="0"
                >
                    <template x-if="active?.icon === 'instagram-grid'">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <rect x="4" y="4" width="6" height="6" stroke="currentColor" stroke-width="2"></rect>
                            <rect x="14" y="4" width="6" height="6" stroke="currentColor" stroke-width="2"></rect>
                            <rect x="4" y="14" width="6" height="6" stroke="currentColor" stroke-width="2"></rect>
                            <rect x="14" y="14" width="6" height="6" stroke="currentColor" stroke-width="2"></rect>
                        </svg>
                    </template>
                    <template x-if="active?.icon && active.icon !== 'instagram-grid'">
                        <i :class="active.icon + ' fa-lg'" aria-hidden="true"></i>
                    </template>
                    <span class="whitespace-nowrap">
                        <span x-text="active?.label ?? ''"></span><template x-if="active?.countLabel"><span>&nbsp;<span x-text="active.countLabel"></span></span></template>
                    </span>
                </button>

                <div class="relative" x-data="{ open:false }">
                    <button
                        type="button"
                        @click="open=!open"
                        @keydown.escape.window="open=false"
                        class="inline-flex shrink-0 items-center gap-2 border-t border-t-transparent px-5 py-3 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500 transition-colors hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-300"
                        :aria-expanded="open" aria-haspopup="menu" title="Weitere Tabs"
                    >
                        <i class="fad fa-bars fa-lg" aria-hidden="true"></i>
                        <span class="whitespace-nowrap">Mehr</span>
                    </button>

                    <div
                        x-cloak
                        x-show="open"
                        @click.outside="open=false"
                        class="absolute right-0 z-20 mt-1 w-56 rounded-xl border border-slate-200 bg-white shadow"
                        role="menu"
                    >
                        <ul class="py-1 max-h-[60vh] overflow-auto">
                            <template x-for="t in others" :key="t.id">
                                <li>
                                    <button
                                        type="button"
                                        class="inline-flex w-full items-center gap-2 px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                        role="menuitem"
                                        @click="open=false; selectTab(t.id)"
                                    >
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
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div>
        {{ $slot }}
    </div>
</div>
