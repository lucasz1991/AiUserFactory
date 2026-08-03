{{--
    Netzwerk -> Personen-Fabrik.

    Bauplaene erzeugen Personen ausschliesslich als Entwuerfe. Die Freigabe ist ein
    bewusster manueller Schritt: erst sie setzt die Person aktiv, legt ihre
    Zeitplaene an und startet den Onboarding-Workflow.
--}}
<div class="ff-person-profile" data-person-factory x-data="{ tab: @entangle('activeTab') }">
    <section class="ff-profile-hero">
        <div class="ff-profile-hero__glow" aria-hidden="true"></div>
        <div class="ff-profile-hero__inner">
            <div class="ff-profile-hero__text">
                <p class="ff-profile-hero__eyebrow">Netzwerk</p>
                <h1 class="ff-profile-hero__name">Personen-Fabrik</h1>
                <p class="ff-profile-hero__meta">
                    Bauplaene erzeugen Personas im Takt. Jede neue Person ist zuerst ein Entwurf und wird erst durch
                    deine Freigabe aktiv.
                </p>
                <div class="ff-profile-hero__chips">
                    <span class="ff-chip ff-chip--{{ $factoryEnabled ? 'ok' : 'muted' }}">
                        {{ $factoryEnabled ? 'Fabrik laeuft' : 'Fabrik gestoppt' }}
                    </span>
                    <span class="ff-chip">{{ $stats['active'] }} aktive Bauplaene</span>
                    <span class="ff-chip ff-chip--{{ $stats['drafts'] > 0 ? 'accent' : 'muted' }}">
                        {{ $stats['drafts'] }} warten auf Freigabe
                    </span>
                </div>
            </div>
            <div class="ff-profile-hero__actions">
                <button type="button" wire:click="newBlueprint" class="ff-btn ff-btn--primary" data-magnetic>Bauplan anlegen</button>
                <button type="button" @click="tab = 'queue'" class="ff-btn ff-btn--ghost">Freigaben oeffnen</button>
            </div>
        </div>
    </section>

    <section class="ff-profile-metrics" aria-label="Kennzahlen der Fabrik">
        <article class="ff-metric ff-metric--{{ $factoryEnabled ? 'ok' : 'muted' }}">
            <dl><dt>Zustand</dt><dd>{{ $factoryEnabled ? 'Aktiv' : 'Gestoppt' }}</dd></dl>
            <p class="ff-metric__detail">Hoechstens {{ $factoryMaxPerDay }} neue Personen pro Tag</p>
        </article>
        <article class="ff-metric ff-metric--ok">
            <dl><dt>Bauplaene</dt><dd>{{ $stats['blueprints'] }}</dd></dl>
            <p class="ff-metric__detail">{{ $stats['active'] }} davon aktiv</p>
        </article>
        <article class="ff-metric ff-metric--{{ $stats['drafts'] > 0 ? 'warn' : 'muted' }}">
            <dl><dt>Entwuerfe</dt><dd>{{ $stats['drafts'] }}</dd></dl>
            <p class="ff-metric__detail">Warten auf Freigabe</p>
        </article>
        <article class="ff-metric ff-metric--ok">
            <dl><dt>Freigegeben</dt><dd>{{ $stats['approved'] }}</dd></dl>
            <p class="ff-metric__detail">Aus Bauplaenen entstanden</p>
        </article>
    </section>

    <div class="ff-profile-tabs" role="tablist" aria-label="Bereiche" x-init="$root.dataset.tabsReady = '1'">
        <div class="ff-profile-tabs__track">
            <button type="button" role="tab" @click="tab = 'queue'"
                :class="tab === 'queue' ? 'ff-profile-tab ff-profile-tab--active' : 'ff-profile-tab'"
                class="ff-profile-tab">Freigaben</button>
            <button type="button" role="tab" @click="tab = 'blueprints'"
                :class="tab === 'blueprints' ? 'ff-profile-tab ff-profile-tab--active' : 'ff-profile-tab'"
                class="ff-profile-tab">Bauplaene</button>
            <button type="button" role="tab" @click="tab = 'settings'"
                :class="tab === 'settings' ? 'ff-profile-tab ff-profile-tab--active' : 'ff-profile-tab'"
                class="ff-profile-tab">Einstellungen</button>
        </div>
    </div>

    <div class="ff-profile-panels">
        {{-- Freigaben --}}
        <div x-show="tab === 'queue'" class="ff-profile-panel space-y-6" data-profile-panel>
            <div class="ff-accounts">
                <div class="ff-accounts__summary">
                    <div>
                        <p class="ff-accounts__eyebrow">Freigabe</p>
                        <h3 class="ff-accounts__title">Erzeugte Entwuerfe pruefen</h3>
                        <p class="ff-accounts__hint">
                            Mit der Freigabe wird die Person aktiv, bekommt die Zeitplaene aus ihrem Bauplan und startet
                            den hinterlegten Onboarding-Workflow.
                        </p>
                    </div>
                    @if($drafts->isNotEmpty())
                        <div class="ff-account-card__actions" style="margin-top:0">
                            <button type="button" wire:click="approveAll"
                                wire:confirm="Wirklich alle {{ $drafts->count() }} Entwuerfe freigeben?"
                                class="ff-btn ff-btn--primary">Alle freigeben</button>
                        </div>
                    @endif
                </div>

                <div class="p-5">
                    @if($drafts->isEmpty())
                        <div class="ff-emptystate">Keine Entwuerfe offen. Ein aktiver Bauplan legt im Takt neue an.</div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="ff-table">
                                <thead>
                                    <tr>
                                        <th>Person</th>
                                        <th>Bauplan</th>
                                        <th>Herkunft</th>
                                        <th>Erstellt</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($drafts as $draft)
                                        <tr wire:key="draft-{{ $draft->id }}">
                                            <td>
                                                <span class="ff-table__strong">{{ $draft->display_name }}</span>
                                                <span class="ff-table__sub">
                                                    {{ data_get($draft->identity_profile, 'occupation') ?: 'Beruf offen' }}
                                                    @if($draft->person_date_of_birth)
                                                        &middot; {{ $draft->person_date_of_birth->age }} Jahre
                                                    @endif
                                                </span>
                                            </td>
                                            <td>{{ $draft->blueprint?->name ?? '—' }}</td>
                                            <td>
                                                <span class="ff-table__sub">
                                                    {{ trim(($draft->person_city ?: '').' '.($draft->person_country ?: '')) ?: 'Ort offen' }}
                                                </span>
                                                @if(data_get($draft->metadata, 'created_by_blueprint.profile_error'))
                                                    <span class="ff-badge ff-badge--alert">AI-Profil fehlgeschlagen</span>
                                                @endif
                                            </td>
                                            <td>{{ $draft->created_at?->format('d.m.Y H:i') }}</td>
                                            <td>
                                                <div class="flex flex-wrap justify-end gap-2">
                                                    <a href="{{ route('persons.show', ['profileId' => $draft->profile_key]) }}"
                                                        class="ff-btn ff-btn--small">Ansehen</a>
                                                    <button type="button" wire:click="approve({{ $draft->id }})"
                                                        class="ff-btn ff-btn--small ff-btn--accent">Freigeben</button>
                                                    <button type="button" wire:click="reject({{ $draft->id }})"
                                                        wire:confirm="Diesen Entwurf ablehnen?"
                                                        class="ff-btn ff-btn--small ff-btn--danger">Ablehnen</button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Bauplaene --}}
        <div x-show="tab === 'blueprints'" class="ff-profile-panel space-y-6" data-profile-panel>
            <div class="ff-accounts">
                <div class="ff-accounts__summary">
                    <div>
                        <p class="ff-accounts__eyebrow">Bauplaene</p>
                        <h3 class="ff-accounts__title">Wie neue Personas entstehen</h3>
                        <p class="ff-accounts__hint">Korridore, Takt, Konten und was nach der Freigabe passieren soll.</p>
                    </div>
                    <div class="ff-account-card__actions" style="margin-top:0">
                        <button type="button" wire:click="newBlueprint" class="ff-btn ff-btn--primary">Bauplan anlegen</button>
                    </div>
                </div>

                <div class="p-5">
                    @if($blueprints->isEmpty())
                        <div class="ff-emptystate">Noch kein Bauplan vorhanden.</div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="ff-table">
                                <thead>
                                    <tr>
                                        <th>Bauplan</th>
                                        <th>Fortschritt</th>
                                        <th>Takt</th>
                                        <th>Onboarding</th>
                                        <th>Zustand</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($blueprints as $blueprint)
                                        <tr wire:key="blueprint-{{ $blueprint->id }}">
                                            <td>
                                                <span class="ff-table__strong">{{ $blueprint->name }}</span>
                                                <span class="ff-table__sub">
                                                    {{ $blueprint->age_min }}–{{ $blueprint->age_max }} Jahre
                                                    @if($blueprint->countries) &middot; {{ implode(', ', $blueprint->countries) }} @endif
                                                </span>
                                            </td>
                                            <td>
                                                {{ $blueprint->created_count }}{{ $blueprint->target_count > 0 ? ' / '.$blueprint->target_count : '' }}
                                                <span class="ff-table__sub">{{ $blueprint->created_today }} heute</span>
                                            </td>
                                            <td>{{ $blueprint->per_day }} pro Tag</td>
                                            <td>{{ $blueprint->onboardingWorkflow?->name ?? '—' }}</td>
                                            <td>
                                                @if($blueprint->is_exhausted)
                                                    <span class="ff-badge ff-badge--muted">Zielzahl erreicht</span>
                                                @elseif($blueprint->is_active)
                                                    <span class="ff-badge ff-badge--ok">Aktiv</span>
                                                @else
                                                    <span class="ff-badge ff-badge--muted">Pausiert</span>
                                                @endif
                                                @if($blueprint->last_error)
                                                    <span class="ff-table__sub">{{ Str::limit($blueprint->last_error, 80) }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="flex flex-wrap justify-end gap-2">
                                                    <button type="button" wire:click="createDraftNow({{ $blueprint->id }})"
                                                        wire:loading.attr="disabled" class="ff-btn ff-btn--small">Jetzt einen erzeugen</button>
                                                    <button type="button" wire:click="toggleBlueprint({{ $blueprint->id }})" class="ff-btn ff-btn--small">
                                                        {{ $blueprint->is_active ? 'Pausieren' : 'Aktivieren' }}
                                                    </button>
                                                    <button type="button" wire:click="editBlueprint({{ $blueprint->id }})" class="ff-btn ff-btn--small">Bearbeiten</button>
                                                    <button type="button" wire:click="deleteBlueprint({{ $blueprint->id }})"
                                                        wire:confirm="Bauplan wirklich entfernen? Bereits erzeugte Personen bleiben bestehen."
                                                        class="ff-btn ff-btn--small ff-btn--danger">Entfernen</button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Einstellungen --}}
        <div x-show="tab === 'settings'" class="ff-profile-panel space-y-6" data-profile-panel>
            <x-admin.panel title="Fabrik-Einstellungen" class="ff-surface">
                <div class="p-5">
                    <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm font-medium text-slate-700">
                        <input type="checkbox" wire:model.defer="factoryEnabled" class="h-4 w-4 rounded border-slate-300">
                        Personen-Fabrik eingeschaltet
                    </label>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="ff-label">Neue Personen pro Tag insgesamt (0 = unbegrenzt)</label>
                            <input type="number" min="0" max="500" wire:model.defer="factoryMaxPerDay" class="ff-input">
                            @error('factoryMaxPerDay') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="mt-5 flex justify-end">
                        <button type="button" wire:click="saveSettings" class="ff-btn ff-btn--primary">Speichern</button>
                    </div>

                    <div class="ff-account-card__notice" style="margin-top:1.25rem">
                        Jede Profilerzeugung ruft ein Sprachmodell auf. Der Takt erzeugt darum hoechstens eine Person je
                        Durchlauf, und der Scheduler prueft alle fuenf Minuten.
                    </div>
                </div>
            </x-admin.panel>
        </div>
    </div>

    {{-- Bauplan-Dialog --}}
    <x-ui.dialog-modal wire:model="showBlueprintModal" maxWidth="2xl">
        <x-slot name="title">{{ $editingBlueprintId ? 'Bauplan bearbeiten' : 'Bauplan anlegen' }}</x-slot>
        <x-slot name="content">
            <div class="grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label class="ff-label">Name</label>
                    <input type="text" wire:model.defer="formName" class="ff-input">
                    @error('formName') <p class="ff-error">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-2">
                    <label class="ff-label">Beschreibung</label>
                    <textarea rows="2" wire:model.defer="formDescription" class="ff-input"></textarea>
                </div>

                <div>
                    <label class="ff-label">Zielanzahl (0 = unbegrenzt)</label>
                    <input type="number" min="0" max="10000" wire:model.defer="formTargetCount" class="ff-input">
                </div>
                <div>
                    <label class="ff-label">Neue Personen pro Tag</label>
                    <input type="number" min="1" max="100" wire:model.defer="formPerDay" class="ff-input">
                </div>

                <div>
                    <label class="ff-label">Laender (durch Komma getrennt)</label>
                    <input type="text" wire:model.defer="formCountries" placeholder="Deutschland, Oesterreich" class="ff-input">
                </div>
                <div>
                    <label class="ff-label">Sprachen</label>
                    <input type="text" wire:model.defer="formLanguages" placeholder="Deutsch, Englisch" class="ff-input">
                </div>
                <div>
                    <label class="ff-label">Geschlechter</label>
                    <input type="text" wire:model.defer="formGenders" placeholder="weiblich, maennlich, divers" class="ff-input">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="ff-label">Alter ab</label>
                        <input type="number" min="16" max="90" wire:model.defer="formAgeMin" class="ff-input">
                    </div>
                    <div>
                        <label class="ff-label">Alter bis</label>
                        <input type="number" min="16" max="90" wire:model.defer="formAgeMax" class="ff-input">
                        @error('formAgeMax') <p class="ff-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="md:col-span-2">
                    <label class="ff-label">Prompt fuer die Profilerzeugung</label>
                    <textarea rows="3" wire:model.defer="formProfilePrompt"
                        placeholder="Beschreibe Milieu, Interessen und Tonfall der Personas." class="ff-input"></textarea>
                </div>

                <div class="md:col-span-2">
                    <label class="ff-label">Konten vorbereiten</label>
                    <div class="mt-2 flex flex-wrap gap-3">
                        @foreach($accountTypes as $type => $definition)
                            @continue($type === 'email')
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input type="checkbox" value="{{ $type }}" wire:model.defer="formAccountTypes" class="h-4 w-4 rounded border-slate-300">
                                {{ $definition['label'] }}
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Das Mailkonto entsteht durch die Mailregistrierung, nicht hier.</p>
                </div>

                <div>
                    <label class="ff-label">Onboarding-Workflow nach der Freigabe</label>
                    <select wire:model.defer="formOnboardingWorkflowId" class="ff-input">
                        <option value="">Keiner</option>
                        @foreach($workflows as $workflow)
                            <option value="{{ $workflow->id }}">{{ $workflow->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm font-medium text-slate-700">
                        <input type="checkbox" wire:model.defer="formGenerateAvatar" class="h-4 w-4 rounded border-slate-300">
                        Profilbild erzeugen lassen
                    </label>
                </div>

                <div class="md:col-span-2">
                    <h4 class="text-sm font-semibold text-slate-800">Zeitplan-Vorlage nach der Freigabe</h4>
                    <div class="mt-2 grid gap-3 md:grid-cols-4">
                        <div class="md:col-span-2">
                            <label class="ff-label">Workflow</label>
                            <select wire:model.defer="formScheduleWorkflowId" class="ff-input">
                                <option value="">Keiner</option>
                                @foreach($workflows as $workflow)
                                    <option value="{{ $workflow->id }}">{{ $workflow->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="ff-label">Intervall (Min.)</label>
                            <input type="number" min="5" max="20160" wire:model.defer="formScheduleIntervalMinutes" class="ff-input">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="ff-label">Von</label>
                                <input type="time" wire:model.defer="formScheduleWindowStart" class="ff-input">
                            </div>
                            <div>
                                <label class="ff-label">Bis</label>
                                <input type="time" wire:model.defer="formScheduleWindowEnd" class="ff-input">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="md:col-span-2">
                    <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm font-medium text-slate-700">
                        <input type="checkbox" wire:model.defer="formIsActive" class="h-4 w-4 rounded border-slate-300">
                        Bauplan aktiv — erzeugt im Takt neue Entwuerfe
                    </label>
                </div>
            </div>
        </x-slot>
        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="$set('showBlueprintModal', false)" class="ff-btn">Abbrechen</button>
                <button type="button" wire:click="saveBlueprint" class="ff-btn ff-btn--primary">Speichern</button>
            </div>
        </x-slot>
    </x-ui.dialog-modal>
</div>
