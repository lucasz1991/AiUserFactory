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
@endphp

<div
    {{ $attributes->merge(['class' => 'w-full']) }}
    x-data="{
        openTab: @if($persist) $persist(@js($initial)).as(@js($key)) @else @js($initial) @endif,
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
        selectTab(id) {
            this.openTab = id;
            this.$dispatch('ui-tab-selected', { group: @js($groupKey), tab: id });
        }
    }"
    x-init="if (!items.some(t => t.id === openTab)) openTab = items[0]?.id ?? openTab"
>
    <div class="overflow-x-auto border-b border-slate-200 px-2">
        <nav
            class="tabs tabs-lifted flex w-max min-w-full justify-start overflow-visible px-1"
            aria-label="Tabs"
            role="tablist"
            aria-orientation="horizontal"
        >
            <template x-for="(t, index) in items" :key="t.id">
                <button
                    type="button"
                    @click.prevent="selectTab(t.id)"
                    :id="@js($htmlIdPrefix) + '-item-' + t.id"
                    :aria-controls="@js($htmlIdPrefix) + '-panel-' + t.id"
                    :style="{
                        marginLeft: index > 0 ? '-0.625rem' : '0',
                        zIndex: items.length - index,
                    }"
                    :class="openTab === t.id
                        ? 'active tab-active border-slate-300 border-b-white bg-white text-slate-950 shadow-md'
                        : 'border-transparent border-b-slate-200 bg-slate-50/80 text-slate-500 hover:border-slate-300 hover:border-b-white hover:bg-white hover:text-slate-900 hover:shadow-md'"
                    class="tab active-tab:tab-active relative -mb-px inline-flex min-h-[2.75rem] shrink-0 items-center gap-2 rounded-t-md border px-4 py-2 text-xs font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-300"
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
        </nav>
    </div>

    <div>
        {{ $slot }}
    </div>
</div>
