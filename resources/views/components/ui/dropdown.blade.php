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
    class="relative"
    x-data="{
        open: false,

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

        show(edge = 'first') {
            this.open = true;
            this.$nextTick(() => {
                const items = this.menuItems();
                const item = edge === 'last' ? items[items.length - 1] : items[0];
                item?.focus({ preventScroll: true });
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
            items[nextIndex]?.focus({ preventScroll: true });
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
            x-cloak
            x-show="open"
            x-ref="panel"
            x-bind:id="$id('ff-dropdown-menu')"
            x-bind:aria-labelledby="triggerElement()?.id"
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
            @keydown.home.prevent.stop="menuItems()[0]?.focus({ preventScroll: true })"
            @keydown.end.prevent.stop="menuItems()[menuItems().length - 1]?.focus({ preventScroll: true })"
        >
            <div class="rounded-md ring-1 ring-black ring-opacity-5 {{ $contentClasses }}">
                {{ $content }}
            </div>
        </div>
    </template>
</div>
