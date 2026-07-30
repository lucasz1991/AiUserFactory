@props([
  'align'             => 'right',
  'width'             => '48',
  'contentClasses'    => 'py-1 bg-white',
  'dropdownClasses'   => 'mx-4',
  'offset'            => 0,
  'overlay'           => false,
  'overlayClasses'    => 'fixed inset-0 z-40 bg-black/40',
  'trap'              => false,
  'scrollOnOpen'      => false,
  'scrollOnTrigger'   => false,  
  'headerOffset'      => 0,
  'matchTriggerWidth' => false,
  'panelRole'         => null,
  'panelLabel'        => null,
])

@php
  $widthClass = match($width){ 'auto'=>'w-auto','min'=>'w-min','max'=>'w-max', default=>'w-48' };
  $anchorPos  = match($align){ 'left'=>'bottom-start','top'=>'top-end','none','false'=>'bottom-end', default=>'bottom-end' };
@endphp

<div
  class="relative"
  x-data="{
    open: false,
    scrollOnOpen: @js((bool)$scrollOnOpen),
    scrollOnTrigger: @js((bool)$scrollOnTrigger),
    headerOffset: @js((int)$headerOffset),
    matchTriggerWidth: @js((bool)$matchTriggerWidth),
    dropdownId: 'anchor-' + Math.random().toString(36).slice(2),
    resizeHandler: null,

    init(){
      this.$watch('open', (value) => {
        if (!value) return;

        this.$nextTick(() => {
          this.setPanelWidth();
          if (this.scrollOnOpen) {
            if (this.scrollOnTrigger) this.scrollToTrigger();
            else this.scrollPanelCentered();
          }
          this.$refs.panelScroll?.scrollTo({ top: 0, behavior: 'auto' });
        });
      });

      this.resizeHandler = () => {
        if (this.open) this.setPanelWidth();
      };
      window.addEventListener('resize', this.resizeHandler, { passive: true });
    },

    destroy(){
      if (!this.resizeHandler) return;
      window.removeEventListener('resize', this.resizeHandler);
      this.resizeHandler = null;
    },

    closeDropdown(restoreFocus = false){
      this.open = false;
      if (restoreFocus) {
        this.$nextTick(() => this.$refs.trigger?.querySelector('button, a, [tabindex]')?.focus({ preventScroll: true }));
      }
    },

    setPanelWidth(){
      if (!this.matchTriggerWidth) return;
      const t = $refs.trigger, p = $refs.panel;
      if (!t || !p) return;
      const tw = t.getBoundingClientRect().width;
      p.style.width = tw + 'px';
      p.style.maxWidth = 'calc(100vw - 16px)';
    },

    scrollToTrigger(){
      const t = $refs.trigger;
      if(!t) return;
      const y = t.getBoundingClientRect().top + window.scrollY - this.headerOffset;
      window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
    },

    scrollPanelCentered(){
      const p = $refs.panel;
      if(!p) return;
      // Panel-Position nach Anchoring abwarten
      requestAnimationFrame(() => {
        const r = p.getBoundingClientRect();
        const centerOffset = (window.innerHeight - r.height) / 2;
        // headerOffset oberhalb trotzdem berücksichtigen
        const target = r.top + window.scrollY - Math.max(0, (this.headerOffset - centerOffset));
        window.scrollTo({ top: Math.max(0, target), behavior: 'smooth' });
      });
    },
  }"
  x-cloak
  @keydown.escape="if (open) { $event.preventDefault(); $event.stopPropagation(); closeDropdown(true); }"
  @focusin.window="if (open && !$refs.trigger?.contains($event.target) && !$refs.panel?.contains($event.target)) closeDropdown()"
  @close.window.stop="closeDropdown()"
  @dropdown-open.window="if ($event.detail?.id !== dropdownId) closeDropdown()"
>


  {{-- Trigger --}}
<div x-ref="trigger" @click="
    open = !open;
    if (open) {
      $nextTick(() => {
        setPanelWidth();
        $dispatch('dropdown-open', { id: dropdownId });
      });
    }
  "
>
    {{ $trigger }}
  </div>

  {{-- Overlay --}}
  @if($overlay)
    <div x-show="open" x-transition.opacity class="{{ $overlayClasses }}" @click="open=false" style="display:none;"></div>
  @endif

  {{-- Panel --}}
  <div
    x-show="open"
    x-bind:id="dropdownId"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="transform opacity-0 scale-95"
    x-transition:enter-end="transform opacity-100 scale-100"
    x-transition:leave="transition ease-in duration-75"
    x-transition:leave-start="transform opacity-100 scale-100"
    x-transition:leave-end="transform opacity-0 scale-95"
    x-anchor.{{ $anchorPos }}.offset.{{ $offset }}.flip.shift="$refs.trigger"
    class="z-40 {{ $widthClass }} rounded-md shadow-lg {{ $dropdownClasses }}"
    style="display:none; max-width:calc(100vw - 16px); max-height:calc(100vh - 16px);"
    @click.outside="closeDropdown()"
    @if($trap) x-trap.inert.noscroll="open" @endif
    @if(filled($panelRole)) role="{{ $panelRole }}" @endif
    @if(filled($panelLabel)) aria-label="{{ $panelLabel }}" @endif
    x-ref="panel"
  >
    <div
      x-ref="panelScroll"
      class="overscroll-contain overflow-y-auto rounded-md ring-1 ring-black ring-opacity-5 {{ $contentClasses }}"
      style="max-height:calc(100dvh - 16px);"
    >
      {{ $content }}
    </div>
  </div>
</div>
