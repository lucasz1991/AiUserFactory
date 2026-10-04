@php
    $recordingConfig = [
        'recordingId' => $recordingId,
        'commandUrl' => $commandUrl,
        'stateUrl' => $stateUrl,
        'frameUrl' => $frameUrl,
        'csrfToken' => csrf_token(),
        'initialState' => $state,
        'initialEvents' => $events,
        'initialBrowserState' => $initialBrowserState ?? [],
        'selectedEventId' => $selectedEventId,
        'valueOptions' => $valueOptions,
    ];
    $selectedDraftEvent = collect($events)->firstWhere('id', $selectedEventId);
    $selectedDraftSecret = (bool) data_get($selectedDraftEvent, 'secret', data_get($selectedDraftEvent, 'sensitive', false));
    $selectedDraftIsFill = data_get($selectedDraftEvent, 'type') === 'fill';
@endphp

<div class="workflow-experience ff-live-recording" data-workflow-live-recording wire:key="workflow-live-recording-shell"
    x-data="workflowLiveRecording()"
    x-on:keydown.escape.window="if (menuOpen) { $event.preventDefault(); closeMenu(); }">
    <script type="application/json" data-live-recording-config>@json($recordingConfig)</script>

    <header class="ff-live-recording__heading">
        <div class="ff-live-recording__heading-copy">
            <a href="{{ route('network.workflows') }}" class="ff-live-recording__back" aria-label="Zurück zur Workflow-Übersicht">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.35" aria-hidden="true"><path d="m14 6-6 6 6 6" stroke-linecap="round" stroke-linejoin="round" /></svg>
            </a>
            <div><h1>Live Aufnahme</h1><p>Im Browser vorführen. Als Workflow weiterbearbeiten.</p></div>
        </div>
        <span class="ff-live-recording__state" x-bind:data-state="state" role="status"><i aria-hidden="true"></i><span x-text="stateLabel()">Bereit</span></span>
    </header>

    <section class="ff-live-recording__toolbar-tray" aria-label="Aufnahmesteuerung">
        <div class="ff-live-recording__toolbar">
            <label class="ff-live-recording__url"><span class="sr-only">Startadresse</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.35" aria-hidden="true"><circle cx="12" cy="12" r="8" /><path d="M4 12h16M12 4c4 4 4 12 0 16-4-4-4-12 0-16Z" /></svg>
                <input type="url" wire:model="url" placeholder="https://…" autocomplete="off" spellcheck="false" aria-label="Startadresse" @disabled(in_array($state, ['recording', 'paused', 'starting'], true))>
            </label>
            <div class="ff-live-recording__transport">
                @if (! $recordingId || in_array($state, ['draft', 'idle', 'failed', 'error'], true))
                    <button type="button" wire:key="live-recording-start-button" x-on:click="transition('startRecording')" x-bind:disabled="serverBusy" wire:loading.attr="disabled" wire:target="startRecording" class="ff-live-recording__button ff-live-recording__button--primary">
                        <span wire:loading.remove wire:target="startRecording">Aufnahme starten</span><span wire:loading wire:target="startRecording">Browser startet…</span>
                        <span class="ff-live-recording__button-icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.35"><path d="m7 4 8 6-8 6V4Z" stroke-linejoin="round" /></svg></span>
                    </button>
                @elseif ($state === 'recording')
                    <button type="button" wire:key="live-recording-pause-button" x-on:click="transition('pauseRecording')" x-bind:disabled="serverBusy" wire:loading.attr="disabled" wire:target="pauseRecording,stopRecording" class="ff-live-recording__button"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.35" aria-hidden="true"><path d="M7 5v10M13 5v10" stroke-linecap="round" /></svg>Pause</button>
                    <button type="button" wire:key="live-recording-stop-button" x-on:click="transition('stopRecording')" x-bind:disabled="serverBusy" wire:loading.attr="disabled" wire:target="pauseRecording,stopRecording" class="ff-live-recording__button">Beenden</button>
                @elseif ($state === 'paused')
                    <button type="button" wire:key="live-recording-resume-button" x-on:click="transition('resumeRecording')" x-bind:disabled="serverBusy" wire:loading.attr="disabled" wire:target="resumeRecording,stopRecording" class="ff-live-recording__button ff-live-recording__button--primary">Fortsetzen</button>
                    <button type="button" wire:key="live-recording-stop-button" x-on:click="transition('stopRecording')" x-bind:disabled="serverBusy" wire:loading.attr="disabled" wire:target="resumeRecording,stopRecording" class="ff-live-recording__button">Beenden</button>
                @else
                    <span class="ff-live-recording__toolbar-note">Aufnahme beendet</span>
                @endif
            </div>
            <span class="ff-live-recording__toolbar-divider" aria-hidden="true"></span>
            <label class="ff-live-recording__name"><span class="sr-only">Workflow-Name</span><input type="text" wire:model="name" placeholder="Workflow-Name" maxlength="160" aria-label="Workflow-Name"></label>
            <button type="button" x-on:click="transition('saveWorkflow')" wire:loading.attr="disabled" wire:target="saveWorkflow" x-bind:disabled="serverBusy || !events.length || state !== 'stopped'" class="ff-live-recording__button ff-live-recording__button--save">Als Workflow speichern</button>
        </div>
    </section>
    @error('url') <p class="ff-live-recording__validation" role="alert">{{ $message }}</p> @enderror
    @error('startUrl') <p class="ff-live-recording__validation" role="alert">{{ $message }}</p> @enderror
    @error('name') <p class="ff-live-recording__validation" role="alert">{{ $message }}</p> @enderror
    @error('recording') <p class="ff-live-recording__validation" role="alert">{{ $message }}</p> @enderror
    @error('event') <p class="ff-live-recording__validation" role="alert">{{ $message }}</p> @enderror
    @if ($error)
        <div class="ff-live-recording__alert" role="alert">{{ $error }}</div>
    @endif
    <div x-cloak x-show="transportError || interactionError" class="ff-live-recording__alert" role="alert">
        <span x-text="transportError || interactionError"></span>
        <button type="button" x-on:click="interactionError = ''; refresh()" class="ff-live-recording__text-button">Erneut verbinden</button>
    </div>

    <div class="ff-live-recording__workspace">
        <section class="ff-live-recording__browser-tray" aria-label="Aufnahmebrowser">
            <div class="ff-live-recording__browser-core">
                <div class="ff-live-recording__browser-bar">
                    <div class="ff-live-recording__page"><span class="ff-live-recording__page-title" x-text="pageTitle || 'Aufnahmebrowser'"></span><span class="ff-live-recording__page-url" x-text="pageUrl || 'Noch keine Seite geöffnet'"></span></div>
                    <button type="button" x-on:click="openInputMenu()" x-bind:disabled="!canControl()" class="ff-live-recording__text-button" aria-label="Eingabe am letzten Mauspunkt zuordnen">Eingabe zuordnen</button>
                    <button type="button" x-on:click="refresh()" x-bind:disabled="!recordingId || !['recording', 'paused'].includes(state)" class="ff-live-recording__icon-button" aria-label="Browser-Vorschau aktualisieren"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.35" aria-hidden="true"><path d="M19 10a7 7 0 1 0-1 8M19 5v5h-5" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
                </div>
                <div class="ff-live-recording__preview" x-ref="preview" wire:ignore tabindex="0" role="application" aria-label="Remote-Browser. Klicken und Scrollen steuert die Seite. Rechte Maustaste ordnet eine Eingabe zu. Enter und Tab werden übertragen. Escape verlässt den Browser."
                    x-on:mousemove="movePointer($event)" x-on:click="clickPointer($event)" x-on:wheel="wheelPointer($event)"
                    x-on:keydown="keyPointer($event)" x-on:contextmenu.prevent="openInputMenu($event)" x-bind:data-controllable="canControl()">
                    <img x-ref="frame" x-bind:src="frameSource || undefined" x-show="frameSource && !frameFailed" x-on:load="onFrameLoaded()" x-on:error="onFrameError()" class="ff-live-recording__frame" alt="Aktuelle Seite des eigenen Aufnahmebrowsers" draggable="false" decoding="async">
                    <div class="ff-live-recording__empty" x-show="!frameReady || frameFailed">
                        <svg viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true"><rect x="5" y="8" width="30" height="24" rx="5" /><path d="M5 15h30M10 12h.1M14 12h.1M18 12h.1m-3 8 5 3-5 3v-6Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        <strong x-text="['stopped', 'saved'].includes(state) ? 'Aufnahmebrowser geschlossen' : frameFailed ? 'Vorschau momentan nicht verfügbar' : recordingId ? 'Browser-Vorschau wird geladen' : 'Bereit für Ihre erste Aufnahme'"></strong>
                        <p x-text="['stopped', 'saved'].includes(state) ? 'Die Aufnahme bleibt erhalten. Tasks prüfen und als Workflow speichern.' : frameFailed ? 'Die Aufnahme bleibt erhalten. Über Aktualisieren erneut verbinden.' : recordingId ? 'Ihre eigenen Browseraktionen erscheinen hier.' : 'Startadresse eingeben und die Aufnahme starten.'"></p>
                    </div>
                    <div x-cloak x-show="menuOpen" class="ff-live-recording__input-menu" x-bind:style="{ left: `${menuX}px`, top: `${menuY}px` }" x-on:click.stop x-on:mousemove.stop x-on:wheel.stop x-on:keydown.stop x-on:keydown.escape.prevent.stop="closeMenu()" x-on:contextmenu.prevent.stop x-on:click.outside="closeMenu(false)" role="dialog" aria-label="Eingabe zuordnen">
                        <div class="ff-live-recording__menu-heading"><div><span>Eingabe zuordnen</span><strong x-text="selectedTarget?.label || 'Eingabefeld'"></strong></div><button type="button" class="ff-live-recording__icon-button" x-on:click="closeMenu()" aria-label="Eingabe-Menü schließen"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.35" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17" stroke-linecap="round" /></svg></button></div>
                        <p class="ff-live-recording__secret-note" x-show="selectedTarget?.sensitive">Sensible Eingabe: Nur die Zuordnung wird gespeichert, kein Testwert.</p>
                        <label class="ff-live-recording__field"><span>Wertquelle</span><select x-ref="valueSource" x-model="valueSource" x-on:change="clearFillDraft(); interactionError = ''"><option value="fixed">Datenwert</option><option value="workflow_variable">Workflow-Variable</option><option value="literal" x-bind:disabled="selectedTarget?.sensitive">Fester Text</option></select></label>
                        <label class="ff-live-recording__field" x-show="valueSource === 'fixed'"><span>Datenwert</span><select x-model="fillValue"><option value="">Bitte auswählen</option><template x-for="group in valueGroups" x-bind:key="group.key"><optgroup x-bind:label="group.label"><template x-for="option in group.options" x-bind:key="option.value"><option x-bind:value="option.value" x-text="option.label"></option></template></optgroup></template></select></label>
                        <label class="ff-live-recording__field" x-show="valueSource === 'workflow_variable'"><span>Variablenname</span><input x-model="variableName" type="text" maxlength="120" placeholder="z. B. kontakt.email" autocomplete="off" spellcheck="false"></label>
                        <label class="ff-live-recording__field" x-show="valueSource === 'literal' && !selectedTarget?.sensitive"><span>Text</span><input x-model="fillValue" type="text" maxlength="4000" autocomplete="off"></label>
                        <label class="ff-live-recording__field" x-show="valueSource !== 'literal'"><span>Testwert <small>nur für diese Aufnahme</small></span><input x-model="previewValue" x-bind:type="selectedTarget?.sensitive ? 'password' : 'text'" maxlength="4000" autocomplete="new-password"></label>
                        <p class="ff-live-recording__selector-note"><span x-text="selectedTarget?.selectors?.length || 0"></span> passende Selektoren · gemeinsame Eingabe-Zuordnung</p>
                        <button type="button" x-on:click="fillTarget()" x-bind:disabled="commandBusy" class="ff-live-recording__button ff-live-recording__button--primary ff-live-recording__button--full">Ausfüllen &amp; aufnehmen<span class="ff-live-recording__button-icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.35"><path d="m5 10 3 3 7-7" stroke-linecap="round" stroke-linejoin="round" /></svg></span></button>
                    </div>
                </div>
                <footer class="ff-live-recording__browser-footer"><span>Rechtsklick: Eingabe auswählen · Enter / Tab: Taste senden · Escape: Browser verlassen</span><span>Mausbewegungen werden mit aufgenommen</span></footer>
            </div>
        </section>

        <aside class="ff-live-recording__draft-tray" aria-label="Aufgenommene Tasks">
            <div class="ff-live-recording__draft-core">
                <div class="ff-live-recording__draft-heading"><h2>Ablauf</h2><span x-text="`${events.length} ${events.length === 1 ? 'Task' : 'Tasks'}`">{{ count($events) }} Tasks</span></div>
                <div class="ff-live-recording__task-empty" x-show="!events.length"><strong>Noch keine Aktionen</strong><p>Navigation, Klicks, Mausbewegungen und zugeordnete Eingaben werden hier zu Tasks.</p></div>
                <ol class="ff-live-recording__events" aria-label="Aufgezeichneter Ablauf">
                    <template x-for="(event, index) in events" x-bind:key="event.id">
                        <li><button type="button" class="ff-live-recording__event" x-bind:aria-pressed="selectedEventId === event.id" x-on:click="selectedEventId = event.id; $wire.selectEvent(event.id)"><span class="ff-live-recording__event-index" x-text="String(index + 1).padStart(2, '0')"></span><span class="ff-live-recording__event-copy"><strong x-text="eventLabel(event)"></strong><small x-text="event.type === 'fill' ? (event.value_source === 'workflow_variable' ? `Variable · ${event.workflow_variable || ''}` : event.value_source === 'fixed' ? `Datenwert · ${event.value || ''}` : event.sensitive || event.secret ? 'Sensible Zuordnung' : 'Fester Text') : event.type === 'navigate' ? event.url : event.type === 'key' ? event.key : event.selector || (event.type === 'scroll' ? 'Seite scrollen' : 'Browseraktion')"></small></span></button></li>
                    </template>
                </ol>
                @if ($selectedEventId && $selectedDraftEvent)
                    <section class="ff-live-recording__editor" aria-label="Ausgewählten Task bearbeiten" wire:key="live-recording-editor-{{ $selectedEventId }}">
                        <p class="ff-live-recording__selector-note" x-show="state === 'recording'">Zum Bearbeiten die Aufnahme pausieren oder beenden.</p>
                        <fieldset class="ff-live-recording__editor-fields" x-bind:disabled="serverBusy || !['paused', 'stopped'].includes(state)">
                        <div class="ff-live-recording__editor-heading"><h3>Task bearbeiten</h3><div class="ff-live-recording__order"><button type="button" wire:click="moveEvent(@js($selectedEventId), -1)" wire:loading.attr="disabled" class="ff-live-recording__icon-button" aria-label="Task nach oben verschieben">↑</button><button type="button" wire:click="moveEvent(@js($selectedEventId), 1)" wire:loading.attr="disabled" class="ff-live-recording__icon-button" aria-label="Task nach unten verschieben">↓</button></div></div>
                        <label class="ff-live-recording__field"><span>Bezeichnung</span><input type="text" wire:model="eventTitle" maxlength="160"></label>
                        @if (in_array(data_get($selectedDraftEvent, 'type'), ['click', 'hover', 'fill'], true))
                            <label class="ff-live-recording__field"><span>Selektoren</span><textarea wire:model="eventSelector" rows="3" spellcheck="false" aria-describedby="live-recording-selector-help"></textarea></label>
                            <p id="live-recording-selector-help" class="ff-live-recording__selector-note">Mehrere passende Selektoren, durch Komma getrennt. Im Zielbrowser prüfen.</p>
                        @endif
                        @if ($selectedDraftIsFill)
                            <label class="ff-live-recording__field"><span>Wertquelle</span><select wire:model.live="eventValueSource"><option value="fixed">Datenwert</option><option value="workflow_variable">Workflow-Variable</option>@if (! $selectedDraftSecret)<option value="literal">Fester Text</option>@endif</select></label>
                            @if ($eventValueSource === 'fixed')
                                <label class="ff-live-recording__field"><span>Datenwert</span><select wire:model="eventValue"><option value="">Bitte auswählen</option>@foreach ($valueOptions as $group)<optgroup label="{{ $group['label'] }}">@foreach ($group['options'] as $optionValue => $optionLabel)<option value="{{ $optionValue }}">{{ $optionLabel }}</option>@endforeach</optgroup>@endforeach</select></label>
                            @elseif ($eventValueSource === 'workflow_variable')
                                <label class="ff-live-recording__field"><span>Variablenname</span><input type="text" wire:model="eventWorkflowVariable" maxlength="120" autocomplete="off" spellcheck="false"></label>
                            @elseif (! $selectedDraftSecret)
                                <label class="ff-live-recording__field"><span>Text</span><input type="text" wire:model="eventValue" maxlength="4000" autocomplete="off"></label>
                            @endif
                            @if ($selectedDraftSecret)<p class="ff-live-recording__secret-note">Testwerte sensibler Eingaben werden nicht gespeichert.</p>@endif
                        @endif
                        @error('eventSelector') <p class="ff-live-recording__validation" role="alert">{{ $message }}</p> @enderror
                        @error('eventTitle') <p class="ff-live-recording__validation" role="alert">{{ $message }}</p> @enderror
                        @error('eventValueSource') <p class="ff-live-recording__validation" role="alert">{{ $message }}</p> @enderror
                        @error('eventValue') <p class="ff-live-recording__validation" role="alert">{{ $message }}</p> @enderror
                        @error('eventWorkflowVariable') <p class="ff-live-recording__validation" role="alert">{{ $message }}</p> @enderror
                        <div class="ff-live-recording__editor-actions"><button type="button" wire:click="removeEvent(@js($selectedEventId))" wire:loading.attr="disabled" class="ff-live-recording__text-button ff-live-recording__text-button--danger">Entfernen</button><button type="button" wire:click="updateEvent" wire:loading.attr="disabled" class="ff-live-recording__button">Änderung übernehmen</button></div>
                        </fieldset>
                    </section>
                @endif
                <footer class="ff-live-recording__draft-footer">Vor dem Speichern die Aufnahme beenden. Der neue Workflow bleibt zunächst inaktiv.</footer>
            </div>
        </aside>
    </div>
</div>
