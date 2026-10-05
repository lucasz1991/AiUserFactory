@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1 bg-white', 'dropdownClasses' => ''])

@php
    $anchorPlacement = match ($align) {
        'left' => 'bottom-start',
        'top' => 'top-end',
        default => 'bottom-end',
    };

    $originClasses = match ($align) {
        'left' => 'ltr:origin-top-left rtl:origin-top-right',
        'top' => 'origin-bottom-right',
        default => 'ltr:origin-top-right rtl:origin-top-left',
    };

    $widthClass = match ($width) {
        'auto' => 'w-auto',
        'min' => 'w-min',
        'max' => 'w-max',
        default => 'w-48',
    };
@endphp

<div
    {{ $attributes->merge(['class' => 'relative']) }}
    x-data="{
        open: false,
        viewportHeight: window.innerHeight,

        panelMaxHeight() {
            const trigger = this.$refs.trigger?.getBoundingClientRect();
            if (!this.open || !trigger) return Math.max(48, this.viewportHeight - 16);

            // Anchor offset plus an 8px viewport gutter; allow flipping above.
            const below = this.viewportHeight - trigger.bottom - 16;
            const above = trigger.top - 16;
            return Math.max(48, Math.min(this.viewportHeight - 16, Math.max(below, above)));
        },

        triggerElement() {
            return this.$refs.trigger?.querySelector('button, a, [tabindex]') ?? this.$refs.trigger;
        },

        menuItems() {
            if (!this.$refs.panel) {
                return [];
            }

            return Array.from(
                this.$refs.panel.querySelectorAll('[role=menuitem]:not([disabled]):not([aria-disabled=true])')
            );
        },

        focusItem(item) {
            if (!item || !this.$refs.panel) return;

            item.focus({ preventScroll: true });
            const panel = this.$refs.panel;
            const panelBounds = panel.getBoundingClientRect();
            const itemBounds = item.getBoundingClientRect();

            if (itemBounds.top < panelBounds.top) {
                panel.scrollTop += itemBounds.top - panelBounds.top;
            } else if (itemBounds.bottom > panelBounds.bottom) {
                panel.scrollTop += itemBounds.bottom - panelBounds.bottom;
            }
        },

        show(edge = 'first') {
            this.open = true;
            this.$nextTick(() => {
                const items = this.menuItems();
                const item = edge === 'last' ? items[items.length - 1] : items[0];
                this.focusItem(item);
            });
        },

        hide(restoreFocus = false) {
            this.open = false;

            if (restoreFocus) {
                this.$nextTick(() => this.triggerElement()?.focus({ preventScroll: true }));
            }
        },

        toggle() {
            if (this.open) {
                this.hide(true);
                return;
            }

            this.show('first');
        },

        moveFocus(offset) {
            const items = this.menuItems();
            if (items.length === 0) {
                return;
            }

            const activeIndex = items.indexOf(document.activeElement);
            const startIndex = activeIndex < 0 ? (offset > 0 ? -1 : 0) : activeIndex;
            const nextIndex = (startIndex + offset + items.length) % items.length;
            this.focusItem(items[nextIndex]);
        },
    }"
    x-id="['ff-dropdown-menu']"
    x-bind:data-open="open ? 'true' : 'false'"
    data-ff-dropdown-root
    x-effect="
        const trigger = triggerElement();
        const menuId = $id('ff-dropdown-menu');

        if (trigger) {
            trigger.id ||= menuId + '-trigger';
            trigger.setAttribute('aria-haspopup', 'menu');
            trigger.setAttribute('aria-controls', menuId);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    "
    @keydown.escape="
        if (open) {
            $event.preventDefault();
            $event.stopPropagation();
            hide(true);
        }
    "
    @keydown.escape.window="
        if (open) {
            $event.preventDefault();
            $event.stopPropagation();
            hide(true);
        }
    "
    @close.stop="hide(false)"
    @resize.window="viewportHeight = window.innerHeight"
>
    <div
        x-ref="trigger"
        @click="toggle()"
        @keydown.arrow-down.prevent.stop="show('first')"
        @keydown.arrow-up.prevent.stop="show('last')"
    >
        {{ $trigger }}
    </div>

    <template x-teleport="body">
        <div
            {{-- Keep morph identity independent of Alpine's generated accessibility ID. --}}
            @if($attributes->has('wire:key')) wire:key="{{ $attributes->get('wire:key') }}-panel" @endif
            x-cloak
            x-show="open"
            x-ref="panel"
            x-bind:id="$id('ff-dropdown-menu')"
            x-bind:aria-labelledby="triggerElement()?.id"
            x-bind:style="{ maxHeight: panelMaxHeight() + 'px' }"
            x-anchor.{{ $anchorPlacement }}.offset.8.flip.shift="$refs.trigger"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="transform opacity-0 scale-95"
            x-transition:enter-end="transform opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="transform opacity-100 scale-100"
            x-transition:leave-end="transform opacity-0 scale-95"
            class="absolute z-[60] {{ $widthClass }} rounded-md shadow-lg {{ $originClasses }} {{ $dropdownClasses }}"
            style="display: none; max-width: calc(100vw - 16px); max-height: calc(100dvh - 16px); overflow-y: auto;"
            role="menu"
            data-ff-dropdown-panel
            @click.outside="hide(false)"
            @click="
                if ($event.target.closest('[role=menuitem]')) {
                    hide(false);
                }
            "
            @focusout="open
                && !$el.contains($event.relatedTarget)
                && !$refs.trigger.contains($event.relatedTarget)
                && hide(false)"
            @keydown.escape.prevent.stop="hide(true)"
            @keydown.arrow-down.prevent.stop="moveFocus(1)"
            @keydown.arrow-up.prevent.stop="moveFocus(-1)"
            @keydown.home.prevent.stop="focusItem(menuItems()[0])"
            @keydown.end.prevent.stop="focusItem(menuItems()[menuItems().length - 1])"
        >
            <div class="rounded-md ring-1 ring-black ring-opacity-5 {{ $contentClasses }}">
                {{ $content }}
            </div>
        </div>
    </template>
</div>
