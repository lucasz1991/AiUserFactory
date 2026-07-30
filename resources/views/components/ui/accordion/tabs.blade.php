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
        selectTab(id, button = null) {
            this.openTab = id;
            this.$dispatch('ui-tab-selected', { group: @js($groupKey), tab: id });
            this.$nextTick(() => button?.scrollIntoView({
                block: 'nearest',
                inline: 'nearest',
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            }));
        },
        tabButtons() {
            return Array.from(this.$refs.tabRow?.querySelectorAll('[role=tab]') || []);
        },
        focusRelativeTab(offset) {
            const buttons = this.tabButtons();
            const currentIndex = Math.max(0, buttons.indexOf(document.activeElement));
            const nextButton = buttons[(currentIndex + offset + buttons.length) % buttons.length];

            if (nextButton) {
                nextButton.focus();
                this.selectTab(nextButton.dataset.uiTabId, nextButton);
            }
        },
        focusBoundaryTab(position) {
            const buttons = this.tabButtons();
            const nextButton = position === 'end' ? buttons[buttons.length - 1] : buttons[0];

            if (nextButton) {
                nextButton.focus();
                this.selectTab(nextButton.dataset.uiTabId, nextButton);
            }
        },
        initTabs() {
            if (!@js(array_map('strval', array_keys($tabs))).includes(this.openTab)) {
                this.openTab = @js((string) $initial);
            }
        }
    }"
    x-init="initTabs()"
    x-on:ui-tab-icon="registerTabIcon($event)"
>
    <div class="w-full max-w-full overflow-x-auto overscroll-x-contain [scrollbar-width:thin]">
        <nav class="w-max min-w-full" aria-label="Task-Einstellungen" role="tablist" aria-orientation="horizontal">
            <ul x-ref="tabRow" class="flex min-w-max items-end gap-1 p-1 pt-2">
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
                    <li class="relative flex-none">
                        <button
                            type="button"
                            id="{{ $htmlIdPrefix }}-item-{{ $tabId }}"
                            data-ui-tab-id="{{ $tabId }}"
                            aria-controls="{{ $htmlIdPrefix }}-panel-{{ $tabId }}"
                            aria-label="{{ $label }}@if($countLabel) {{ $countLabel }}@endif"
                            @click.prevent="selectTab(@js($tabId), $el)"
                            @keydown.arrow-right.stop.prevent="focusRelativeTab(1)"
                            @keydown.arrow-left.stop.prevent="focusRelativeTab(-1)"
                            @keydown.home.stop.prevent="focusBoundaryTab('start')"
                            @keydown.end.stop.prevent="focusBoundaryTab('end')"
                            class="group/tab relative inline-flex min-h-11 items-center justify-center rounded-xl border px-3.5 py-2.5 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300"
                            role="tab"
                            :aria-selected="(openTab === @js($tabId)).toString()"
                            :tabindex="openTab === @js($tabId) ? 0 : -1"
                            :class="openTab === @js($tabId)
                                ? 'border-blue-200 bg-blue-50 text-blue-950 shadow-sm'
                                : 'border-transparent bg-slate-100 text-slate-600 hover:border-slate-200 hover:bg-white hover:text-slate-950'"
                        >
                            <span class="inline-flex min-w-0 items-center justify-center gap-2 whitespace-nowrap leading-none">
                                <i :class="iconClass(@js($tabId), @js($iconClass))" aria-hidden="true"></i>
                                <span>{{ $label }}@if($countLabel)&nbsp;{{ $countLabel }}@endif</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </nav>
    </div>

    <div class="content-wrap mt-1 bg-white">
        {{ $slot }}
    </div>
</section>
