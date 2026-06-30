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
    <style>
        #{{ $htmlIdPrefix }} .shape-tab-svg {
            pointer-events: none;
        }

        #{{ $htmlIdPrefix }} .shape-tab-svg use {
            pointer-events: auto;
        }

        @media screen and (max-width: 58em) {
            #{{ $htmlIdPrefix }} .shape-tabs-list {
                display: block;
                padding-top: 1.5em;
            }

            #{{ $htmlIdPrefix }} .shape-tabs-item {
                display: block;
                margin: -1.25em 0 0 !important;
                flex: none;
            }

            #{{ $htmlIdPrefix }} .shape-tabs-trigger {
                margin: 0 !important;
                width: 100%;
            }

            #{{ $htmlIdPrefix }} .shape-tab-svg {
                display: none;
            }

            #{{ $htmlIdPrefix }} .shape-tab-label {
                padding: 1.25em 1rem 2em !important;
                border-radius: 30px 30px 0 0 !important;
                box-shadow: 0 -1px 2px rgba(0, 0, 0, 0.1);
                line-height: 1;
            }

            #{{ $htmlIdPrefix }} .shape-tabs-item:last-child .shape-tab-label {
                padding-bottom: 1.25em !important;
            }
        }
    </style>

    <svg class="hidden" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <path id="{{ $shapeId }}" d="M80,60C34,53.5,64.5,0,0,0v60H80z"></path>
        </defs>
    </svg>

    <div class="tabs tabs-style-shape w-full max-w-[1200px] overflow-x-auto">
        <nav aria-label="Tabs" role="tablist" aria-orientation="horizontal">
            <ul class="shape-tabs-list flex min-w-max justify-start overflow-visible">
                <template x-for="(t, index) in items" :key="t.id">
                    <li
                        class="shape-tabs-item relative mx-12 flex-none first:ml-0"
                        :class="{ 'tab-current': openTab === t.id }"
                        :style="{ zIndex: items.length - index }"
                    >
                        <button
                            type="button"
                            @click.prevent="selectTab(t.id)"
                            :id="@js($htmlIdPrefix) + '-item-' + t.id"
                            :aria-controls="@js($htmlIdPrefix) + '-panel-' + t.id"
                            class="shape-tabs-trigger group relative -mr-12 block overflow-visible p-0 text-sm font-medium text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#2CC185]"
                            role="tab"
                            :aria-selected="openTab === t.id"
                            :tabindex="openTab === t.id ? 0 : -1"
                        >
                            <svg
                                x-show="index < items.length - 1"
                                viewBox="0 0 80 60"
                                preserveAspectRatio="none"
                                class="shape-tab-svg absolute left-full top-0 m-0 h-full w-12 group-hover:fill-[#2CC185]"
                                :class="openTab === t.id ? 'fill-white' : 'fill-[#bdc2c9]'"
                                aria-hidden="true"
                                focusable="false"
                            >
                                <use href="#{{ $shapeId }}" xlink:href="#{{ $shapeId }}"></use>
                            </svg>
                            <svg
                                x-show="index > 0"
                                viewBox="0 0 80 60"
                                preserveAspectRatio="none"
                                class="shape-tab-svg absolute right-full top-0 m-0 h-full w-12 -scale-x-100 group-hover:fill-[#2CC185]"
                                :class="openTab === t.id ? 'fill-white' : 'fill-[#bdc2c9]'"
                                aria-hidden="true"
                                focusable="false"
                            >
                                <use href="#{{ $shapeId }}" xlink:href="#{{ $shapeId }}"></use>
                            </svg>
                            <span
                                class="shape-tab-label block overflow-hidden px-4 py-[0.65em] text-ellipsis whitespace-nowrap"
                                :class="[
                                    openTab === t.id ? 'bg-white text-slate-950' : 'bg-[#bdc2c9] text-white group-hover:bg-[#2CC185]',
                                    index === 0 ? 'rounded-tl-[30px] pl-8' : '',
                                    index === items.length - 1 ? 'rounded-tr-[30px] pr-8' : ''
                                ]"
                            >
                                <span class="inline-flex items-center gap-2">
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
