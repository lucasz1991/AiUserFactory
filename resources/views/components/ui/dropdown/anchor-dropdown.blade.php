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
  x-init="
    $watch('open', (v) => {
      if (v) {
        $nextTick(() => {
          setPanelWidth();
          if (scrollOnOpen) {
            // Wenn explizit Trigger-Scroll gewünscht -> Trigger,
            // sonst Panel schön mittig.
            if (scrollOnTrigger) { scrollToTrigger(); }
            else { scrollPanelCentered(); }
          }
          // Panel-Scroll (Inhalt) nach oben
          if ($refs.panelScroll) { $refs.panelScroll.scrollTo({ top: 0, behavior: 'auto' }); }
        });
      }
    });

    // Bei Resize Breite nachziehen, solange offen
    window.addEventListener('resize', () => { if (open) setPanelWidth() }, { passive:true });
  "
  x-cloak
  @keydown.escape.window="open=false"
  @close.window.stop="open=false"
  @dropdown-open.window="if ($event.detail?.id !== dropdownId) open=false"
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
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="transform opacity-0 scale-95"
    x-transition:enter-end="transform opacity-100 scale-100"
    x-transition:leave="transition ease-in duration-75"
    x-transition:leave-start="transform opacity-100 scale-100"
    x-transition:leave-end="transform opacity-0 scale-95"
    x-anchor.{{ $anchorPos }}.offset.{{ $offset }}.flip.shift="$refs.trigger"
    class="z-40 {{ $widthClass }} rounded-md shadow-lg {{ $dropdownClasses }}"
    style="display:none; max-width:calc(100vw - 16px); max-height:calc(100vh - 16px);"
    @click.outside="open=false"
    @if($trap) x-trap.inert.noscroll="open" @endif
    x-ref="panel"
  >
    <div x-ref="panelScroll" class="rounded-md ring-1 ring-black ring-opacity-5 overflow-hidden {{ $contentClasses }}">
      {{ $content }}
    </div>
  </div>
</div>
