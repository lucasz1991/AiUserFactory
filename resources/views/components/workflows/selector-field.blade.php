@props([
    'model',
    'label' => 'Selector',
    'placeholder' => '',
    'mode' => 'css_selector',
    'required' => false,
    'type' => 'text',
    'rows' => 3,
    'help' => '',
    'wireKey' => null,
])

@php
    $fieldId = 'workflow-selector-'.\Illuminate\Support\Str::slug((string) $model).'-'.substr(md5((string) $model), 0, 8);
    $helpId = $fieldId.'-help';
    $statusId = $fieldId.'-status';
    $isTextarea = $type === 'textarea';
    $serverError = $errors->first((string) $model);

    $syntaxHelp = match ($mode) {
        'element_candidates' => [
            'title' => 'Element-Ziel',
            'summary' => 'CSS oder sichtbarer Text. Ein Komma trennt priorisierte Alternativen.',
            'examples' => [
                'css=button[type=submit]',
                'text=Weiter',
                'text-is=Jetzt anmelden',
                'button:has-text("Weiter")',
            ],
            'note' => 'Die Suche umfasst alle iFrames und offene Shadow DOMs. role= waere nur normaler Suchtext; fuer Rollen verwende z. B. [role=button].',
        ],
        'selector_field_definitions' => [
            'title' => 'Felddefinitionen',
            'summary' => 'JSON-Array mit name, selector und type. Selector und fallback_selectors enthalten reines CSS.',
            'examples' => [
                '[{"name":"title","selector":"h3","type":"text"}]',
                '"fallback_selectors":[".title","[role=heading]"]',
            ],
            'note' => 'Erlaubte Typen: text, inner_text, href, html, attribute und exists.',
        ],
        'selector_fallback_map' => [
            'title' => 'Selector-Fallbacks',
            'summary' => 'JSON-Objekt mit CSS-Listen fuer title, link, description, site_name oder breadcrumb.',
            'examples' => [
                '{"title":["h3","[role=heading]"]}',
                '{"link":["a[href]"]}',
            ],
            'note' => 'Jeder Eintrag wird einzeln als Chromium-CSS geprueft.',
        ],
        'selector_action_steps' => [
            'title' => 'Aktionsschritte',
            'summary' => 'JSON-Array oder ein Element-Ziel pro Zeile. Jeder Schritt nutzt die erweiterte CSS-/Text-Syntax.',
            'examples' => [
                '[{"selector":"text=Loeschen","wait_ms":500}]',
                '{"selector":"button:has-text(\'Bestaetigen\')","required":false}',
            ],
            'note' => 'required muss true/false sein; wait_ms und timeout_ms muessen nichtnegative Zahlen sein.',
        ],
        'variable_path' => [
            'title' => 'Workflow-Variable',
            'summary' => 'Dieses Feld ist kein DOM-Selector, sondern der Name der Rueckgabevariable.',
            'examples' => [
                'workflow_return',
                'mailbox.ready',
            ],
            'note' => 'Erlaubt sind Buchstaben, Zahlen, Punkt, Unterstrich und Bindestrich; maximal 120 Zeichen.',
        ],
        default => [
            'title' => 'CSS-Selector',
            'summary' => 'Nur gueltiges Chromium-CSS. Ein Komma bildet eine native CSS-Gruppe.',
            'examples' => [
                '#login',
                'input[name=email]',
                'article:has(h3)',
            ],
            'note' => 'text=, css=, :has-text() und :text-is() sind in diesem Feld nicht erlaubt.',
        ],
    };
@endphp

<div
    x-data="workflowSelectorField({ mode: @js($mode), required: @js((bool) $required), serverError: @js($serverError) })"
    x-init="$nextTick(() => check($refs.field.value))"
    x-on:keydown.escape.stop="closeHelp()"
    data-workflow-selector-field
    data-selector-mode="{{ $mode }}"
>
    <div class="flex min-h-10 items-center justify-between gap-3">
        <label for="{{ $fieldId }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>

        <div class="relative shrink-0" x-on:click.outside="closeHelp()">
            <button
                type="button"
                class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white text-sm font-black text-slate-600 shadow-sm transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 sm:h-8 sm:w-8"
                x-on:click="toggleHelp()"
                x-bind:aria-expanded="helpOpen.toString()"
                aria-controls="{{ $helpId }}"
                aria-label="Syntaxhilfe fuer {{ $label }} oeffnen"
            >?</button>

            <div
                id="{{ $helpId }}"
                x-cloak
                x-show.important="helpOpen"
                x-transition.opacity.duration.150ms
                class="absolute right-0 top-11 z-50 w-[min(23rem,calc(100vw-3rem))] rounded-2xl border border-slate-200 bg-slate-950 p-4 text-left text-xs leading-5 text-slate-200 shadow-2xl sm:top-9"
                role="note"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="font-bold text-white">{{ $syntaxHelp['title'] }}</p>
                        <p class="mt-1 text-slate-300">{{ $syntaxHelp['summary'] }}</p>
                    </div>
                    <button type="button" x-on:click="closeHelp()" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-lg text-slate-400 hover:bg-white/10 hover:text-white" aria-label="Syntaxhilfe schliessen">&times;</button>
                </div>
                <div class="mt-3 space-y-1.5">
                    @foreach($syntaxHelp['examples'] as $example)
                        <code class="block overflow-x-auto rounded-lg bg-white/10 px-2.5 py-1.5 font-mono text-[11px] text-blue-100">{{ $example }}</code>
                    @endforeach
                </div>
                <p class="mt-3 border-t border-white/10 pt-3 text-[11px] text-slate-400">{{ $syntaxHelp['note'] }}</p>
            </div>
        </div>
    </div>

    <div class="relative mt-1">
        @if($isTextarea)
            <textarea
                id="{{ $fieldId }}"
                x-ref="field"
                rows="{{ max(2, (int) $rows) }}"
                wire:model.defer="{{ $model }}"
                @if($wireKey) wire:key="{{ $wireKey }}" @endif
                placeholder="{{ $placeholder }}"
                aria-describedby="{{ $statusId }}"
                x-on:input.debounce.120ms="check($event.target.value, true)"
                x-bind:aria-invalid="state === 'invalid' ? 'true' : 'false'"
                x-bind:class="state === 'invalid'
                    ? '!border-rose-500 !bg-rose-50/60 !pr-10 focus:!border-rose-500 focus:!ring-rose-200'
                    : state === 'valid'
                        ? '!border-emerald-500 !bg-emerald-50/40 !pr-10 focus:!border-emerald-500 focus:!ring-emerald-200'
                        : ''"
                class="block w-full rounded-lg border border-gray-300 p-2 text-sm shadow-sm transition focus:border-blue-500 focus:ring-blue-500"
            ></textarea>
        @else
            <input
                id="{{ $fieldId }}"
                x-ref="field"
                type="text"
                wire:model.defer="{{ $model }}"
                @if($wireKey) wire:key="{{ $wireKey }}" @endif
                placeholder="{{ $placeholder }}"
                aria-describedby="{{ $statusId }}"
                x-on:input.debounce.120ms="check($event.target.value, true)"
                x-bind:aria-invalid="state === 'invalid' ? 'true' : 'false'"
                x-bind:class="state === 'invalid'
                    ? '!border-rose-500 !bg-rose-50/60 !pr-10 focus:!border-rose-500 focus:!ring-rose-200'
                    : state === 'valid'
                        ? '!border-emerald-500 !bg-emerald-50/40 !pr-10 focus:!border-emerald-500 focus:!ring-emerald-200'
                        : ''"
                class="block w-full rounded-lg border border-gray-300 p-2 text-sm shadow-sm transition focus:border-blue-500 focus:ring-blue-500"
            >
        @endif

        <span class="pointer-events-none absolute right-3 top-3 inline-flex h-5 w-5 items-center justify-center" aria-hidden="true">
            <svg x-cloak x-show.important="state === 'valid'" class="h-5 w-5 text-emerald-600" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 5.293a1 1 0 0 1 .003 1.414l-7.25 7.28a1 1 0 0 1-1.42-.006L3.29 9.2a1 1 0 1 1 1.42-1.408l4.038 4.075 6.543-6.57a1 1 0 0 1 1.414-.004Z" clip-rule="evenodd"></path></svg>
            <svg x-cloak x-show.important="state === 'invalid'" class="h-5 w-5 text-rose-600" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm1-11a1 1 0 1 0-2 0v3a1 1 0 1 0 2 0V7Zm-1 7a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"></path></svg>
        </span>
    </div>

    <p
        id="{{ $statusId }}"
        class="mt-1.5 flex min-h-5 items-start gap-1.5 text-xs leading-5"
        x-bind:class="state === 'invalid' ? 'font-semibold text-rose-700' : state === 'valid' ? 'text-emerald-700' : 'text-slate-500'"
        x-text="message"
        role="status"
        aria-live="polite"
    ></p>

    @if($help !== '')
        <p class="mt-1 text-xs leading-5 text-slate-500">{{ $help }}</p>
    @endif
</div>
