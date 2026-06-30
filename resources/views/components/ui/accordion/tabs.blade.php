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
@endphp

<section
    id="{{ $htmlIdPrefix }}"
    {{ $attributes->merge(['class' => 'w-full']) }}
    x-data="{
        openTab: @if($persist) $persist(@js($initial)).as(@js($key)) @else @js($initial) @endif,
        hoverTab: null,
        overlapFor(id) {
            return (this.openTab === id || this.hoverTab === id) ? 40 : 92;
        },
        selectTab(id) {
            this.openTab = id;
            this.$dispatch('ui-tab-selected', { group: @js($groupKey), tab: id });
        },
        initTabs() {
            if (!@js(array_map('strval', array_keys($tabs))).includes(this.openTab)) {
                this.openTab = @js((string) $initial);
            }
        }
    }"
    x-init="initTabs()"
>
    <div class="w-full max-w-full overflow-hidden">
        <nav class="w-full max-w-full overflow-hidden" aria-label="Tabs" role="tablist" aria-orientation="horizontal">
            <ul class="flex w-full max-w-full justify-start overflow-hidden pt-2">
                @foreach($tabs as $tabKey => $tab)
                    @php
                        $tabId = (string) $tabKey;
                        $isArray = is_array($tab);
                        $label = $isArray ? ($tab['label'] ?? Str::title($tabId)) : $tab;
                        $iconClass = $isArray ? ($tab['icon'] ?? null) : null;
                        $count = $isArray && array_key_exists('count', $tab) ? $tab['count'] : null;
                        $countLabel = $count !== null ? number_format((int) $count, 0, ',', '.') : null;
                    @endphp
                    <li
                        class="relative flex-none transition-[margin] duration-200 ease-out"
                        :style="{
                            marginLeft: @js($loop->first) ? '0' : `-${overlapFor(@js($tabId))}px`,
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
                            class="group/tab relative block overflow-hidden rounded-t-md border border-b-0 border-gray-400 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-200"
                            role="tab"
                            :aria-selected="(openTab === @js($tabId)).toString()"
                            :tabindex="openTab === @js($tabId) ? 0 : -1"
                        >
                            <span
                                class="block w-40 overflow-hidden px-4 py-[0.7em] text-ellipsis whitespace-nowrap sm:w-56"
                                :class="[
                                    openTab === @js($tabId) ? 'bg-blue-50 text-blue-950' : 'bg-slate-300 text-slate-700 group-hover/tab:bg-blue-100 group-hover/tab:text-blue-900',
                                    @js($loop->first) ? 'pl-5' : '',
                                    @js($loop->last) ? 'pr-5' : ''
                                ]"
                            >
                                <span class="inline-flex min-w-0 items-center gap-2">
                                    @if($iconClass)
                                        <i class="{{ $iconClass }} fa-lg" aria-hidden="true"></i>
                                    @endif
                                    <span class="truncate">
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
