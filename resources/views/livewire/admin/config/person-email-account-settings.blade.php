@php
    $input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm transition focus:border-primary-base focus:ring-2 focus:ring-primary-base/30';
    $label = 'block text-sm font-medium text-slate-700';
    $providerAuto = (bool) data_get($providers, $provider.'.auto', false);
@endphp

<div class="space-y-6">
    @if (session()->has('success'))
        <div class="rounded-lg border border-secondary-base/30 bg-secondary-base/10 p-4 text-sm font-medium text-secondary-base">
            {{ session('success') }}
        </div>
    @endif

    @if (! $person)
        <x-admin.panel>
            <div class="text-sm text-slate-500">Keine Person gefunden.</div>
        </x-admin.panel>
    @else
        {{-- ===================== Account-Liste ===================== --}}
        <x-admin.panel title="E-Mail-Accounts" description="Mehrere Mailboxen je Provider verwalten. Der als primaer markierte Account wird von der Automatisierung verwendet.">
            <x-slot name="actions">
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('admin.settings', ['tab' => 'mail-registration']) }}" wire:navigate
                       class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                        Mail-Registrierung
                    </a>
                    <button type="button" wire:click="newAccount"
                            class="rounded-lg bg-primary-base px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-[#274d86]">
                        + Account hinzufuegen
                    </button>
                </div>
            </x-slot>

            @if (count($accounts) === 0)
                <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center">
                    <p class="text-sm font-medium text-slate-600">Noch keine E-Mail-Accounts hinterlegt.</p>
                    <p class="mt-1 text-sm text-slate-400">Lege den ersten Account fuer diese Person an.</p>
                    <button type="button" wire:click="newAccount"
                            class="mt-4 rounded-lg bg-primary-base px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-[#274d86]">
                        + Account hinzufuegen
                    </button>
                </div>
            @else
                <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                    @foreach ($accounts as $acc)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 {{ $editingAccountId === $acc['id'] ? 'bg-primary-base/5' : 'bg-white' }}">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
                                    <span class="mdi mdi-email-outline text-lg"></span>
                                </span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="truncate text-sm font-semibold text-slate-800">{{ $acc['email'] ?: ($acc['username'] ?: 'Ohne Adresse') }}</span>
                                        @if ($acc['is_primary'])
                                            <span class="inline-flex items-center gap-1 rounded-full bg-secondary-base/10 px-2 py-0.5 text-11 font-semibold text-secondary-base">
                                                <span class="mdi mdi-star text-xs"></span> Primaer
                                            </span>
                                        @endif
                                    </div>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400">
                                        <span class="inline-flex items-center gap-1 font-medium text-slate-500">
                                            <span class="h-1.5 w-1.5 rounded-full bg-primary-base"></span> {{ $acc['provider_label'] }}
                                        </span>
                                        @if ($acc['has_password'])
                                            <span class="inline-flex items-center gap-1"><span class="mdi mdi-lock-outline"></span> Passwort</span>
                                        @endif
                                        @if ($acc['has_webmail_session'])
                                            <span class="inline-flex items-center gap-1 text-secondary-base"><span class="mdi mdi-cookie-outline"></span> Webmail-Session ({{ $acc['webmail_cookie_count'] }})</span>
                                        @endif
                                        <span>Aktualisiert: {{ $acc['updated_at_label'] }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                @if (! $acc['is_primary'])
                                    <button type="button" wire:click="setPrimaryAccount({{ $acc['id'] }})"
                                            class="rounded-md px-2.5 py-1.5 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-secondary-base" title="Als primaer setzen">
                                        <span class="mdi mdi-star-outline"></span> Primaer
                                    </button>
                                @endif
                                <button type="button" wire:click="editAccount({{ $acc['id'] }})"
                                        class="rounded-md px-2.5 py-1.5 text-xs font-semibold text-primary-base transition hover:bg-primary-base/10">
                                    Bearbeiten
                                </button>
                                <button type="button" wire:click="deleteAccount({{ $acc['id'] }})"
                                        wire:confirm="Diesen E-Mail-Account wirklich loeschen?"
                                        class="rounded-md px-2.5 py-1.5 text-xs font-semibold text-red-600 transition hover:bg-red-50">
                                    Loeschen
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-admin.panel>

        {{-- ===================== Account-Formular (Add/Edit) ===================== --}}
        @if ($showForm)
            <x-admin.panel
                :title="$editingAccountId ? 'Account bearbeiten' : 'Neuer Account'"
                description="Zugangsdaten und technische Verbindungsdaten fuer diese Mailbox.">
                <x-slot name="actions">
                    <div class="flex flex-wrap gap-2">
                        @if ($providerAuto)
                            <button type="button" wire:click="startMailRegistration" wire:loading.attr="disabled"
                                    class="rounded-lg border border-primary-base/30 bg-white px-4 py-2 text-sm font-semibold text-primary-base shadow-sm transition hover:bg-primary-base/5 disabled:cursor-wait disabled:opacity-60">
                                Account registrieren
                            </button>
                            <button type="button" wire:click="buildWebmailSession" wire:loading.attr="disabled"
                                    class="rounded-lg border border-secondary-base/30 bg-white px-4 py-2 text-sm font-semibold text-secondary-base shadow-sm transition hover:bg-secondary-base/5 disabled:cursor-wait disabled:opacity-60">
                                Webmail-Session speichern
                            </button>
                        @endif
                        <button type="button" wire:click="cancelForm"
                                class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                            Abbrechen
                        </button>
                        <button type="button" wire:click="saveSettings"
                                class="rounded-lg bg-primary-base px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-[#274d86]">
                            Speichern
                        </button>
                    </div>
                </x-slot>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="email-account-address" class="{{ $label }}">E-Mail-Adresse</label>
                        <input id="email-account-address" type="email" wire:model.defer="emailAddress" class="{{ $input }}">
                        @error('emailAddress') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="email-account-provider" class="{{ $label }}">Provider</label>
                        <select id="email-account-provider" wire:model.live="provider" class="{{ $input }}">
                            @foreach ($providers as $key => $config)
                                <option value="{{ $key }}">{{ $config['label'] }}</option>
                            @endforeach
                        </select>
                        @error('provider') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        @unless ($providerAuto)
                            <p class="mt-1.5 text-xs text-slate-400">Custom-Provider: manuelle Einstellungen, keine automatische Registrierung/Webmail-Session.</p>
                        @endunless
                    </div>

                    <div>
                        <label for="email-account-username" class="{{ $label }}">Login / Benutzername</label>
                        <input id="email-account-username" type="text" wire:model.defer="accountUsername" class="{{ $input }}">
                        @error('accountUsername') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="email-account-password" class="{{ $label }}">Passwort</label>
                        <input id="email-account-password" type="password" wire:model.defer="accountPassword" autocomplete="new-password" class="{{ $input }}">
                        <div class="mt-2 flex items-center justify-between gap-3 text-xs text-slate-500">
                            <span>
                                @if ($hasStoredPassword)
                                    Passwort ist gespeichert. Leeres Feld behaelt das vorhandene Passwort.
                                @else
                                    Noch kein Passwort gespeichert.
                                @endif
                            </span>
                            @if ($hasStoredPassword)
                                <button type="button" wire:click="clearStoredPassword" wire:confirm="Gespeichertes E-Mail-Passwort wirklich loeschen?" class="font-semibold text-red-600 hover:text-red-700">
                                    Passwort loeschen
                                </button>
                            @endif
                        </div>
                        @error('accountPassword') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="email-account-recovery-email" class="{{ $label }}">Recovery-E-Mail</label>
                        <input id="email-account-recovery-email" type="email" wire:model.defer="recoveryEmail" class="{{ $input }}">
                        @error('recoveryEmail') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="email-account-recovery-phone" class="{{ $label }}">Recovery-Telefon</label>
                        <input id="email-account-recovery-phone" type="text" wire:model.defer="recoveryPhone" class="{{ $input }}">
                        @error('recoveryPhone') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="md:col-span-2">
                        <label for="email-account-webmail-url" class="{{ $label }}">Webmail URL</label>
                        <input id="email-account-webmail-url" type="url" wire:model.defer="webmailUrl" placeholder="https://mail.example.com" class="{{ $input }}">
                        @error('webmailUrl') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if ($editingWebmailSession !== [])
                    <div class="mt-4 rounded-lg border border-secondary-base/30 bg-secondary-base/5 px-4 py-3 text-sm text-slate-700">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="font-semibold text-secondary-base">Webmail-Session gespeichert</div>
                            <div class="text-xs text-slate-500">{{ data_get($editingWebmailSession, 'updated_at', data_get($editingWebmailSession, 'captured_at', '-')) }}</div>
                        </div>
                        <div class="mt-2 grid gap-2 text-xs md:grid-cols-3">
                            <div>
                                <div class="font-semibold text-slate-500">Cookies</div>
                                <div>{{ (int) data_get($editingWebmailSession, 'cookie_count', 0) }}</div>
                            </div>
                            <div>
                                <div class="font-semibold text-slate-500">Portal</div>
                                <div class="break-all">{{ data_get($editingWebmailSession, 'final_url', '-') }}</div>
                            </div>
                            <div>
                                <div class="font-semibold text-slate-500">Script</div>
                                <div>{{ data_get($editingWebmailSession, 'script_name', '-') }} v{{ data_get($editingWebmailSession, 'script_version', '-') }}</div>
                            </div>
                        </div>
                    </div>
                @endif
            </x-admin.panel>

            @if ($webmailSessionResult !== [])
                <div class="rounded-lg border p-4 text-sm {{ data_get($webmailSessionResult, 'ok') ? 'border-secondary-base/30 bg-secondary-base/5 text-slate-700' : 'border-amber-200 bg-amber-50 text-amber-950' }}">
                    <p class="font-semibold">{{ data_get($webmailSessionResult, 'statusMessage', 'Webmail-Sessionlauf abgeschlossen.') }}</p>
                    <p class="mt-1 text-xs">Cookies: {{ data_get($webmailSessionResult, 'cookieCount', data_get($webmailSessionResult, 'sessionSummary.cookieCount', 0)) }}</p>
                    @if (! empty($webmailSessionResult['warnings']))
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
                            @foreach ($webmailSessionResult['warnings'] as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div class="grid gap-6 xl:grid-cols-2">
                <x-admin.panel title="IMAP">
                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="md:col-span-2">
                            <label for="email-account-imap-host" class="{{ $label }}">Host</label>
                            <input id="email-account-imap-host" type="text" wire:model.defer="imapHost" placeholder="imap.example.com" class="{{ $input }}">
                            @error('imapHost') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="email-account-imap-port" class="{{ $label }}">Port</label>
                            <input id="email-account-imap-port" type="number" min="1" max="65535" wire:model.defer="imapPort" placeholder="993" class="{{ $input }}">
                            @error('imapPort') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-3">
                            <label for="email-account-imap-encryption" class="{{ $label }}">Verschluesselung</label>
                            <select id="email-account-imap-encryption" wire:model.defer="imapEncryption" class="{{ $input }}">
                                <option value="">Nicht angegeben</option>
                                <option value="ssl">SSL</option>
                                <option value="tls">TLS</option>
                                <option value="starttls">STARTTLS</option>
                                <option value="none">Keine</option>
                            </select>
                            @error('imapEncryption') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </x-admin.panel>

                <x-admin.panel title="SMTP">
                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="md:col-span-2">
                            <label for="email-account-smtp-host" class="{{ $label }}">Host</label>
                            <input id="email-account-smtp-host" type="text" wire:model.defer="smtpHost" placeholder="smtp.example.com" class="{{ $input }}">
                            @error('smtpHost') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="email-account-smtp-port" class="{{ $label }}">Port</label>
                            <input id="email-account-smtp-port" type="number" min="1" max="65535" wire:model.defer="smtpPort" placeholder="587" class="{{ $input }}">
                            @error('smtpPort') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-3">
                            <label for="email-account-smtp-encryption" class="{{ $label }}">Verschluesselung</label>
                            <select id="email-account-smtp-encryption" wire:model.defer="smtpEncryption" class="{{ $input }}">
                                <option value="">Nicht angegeben</option>
                                <option value="ssl">SSL</option>
                                <option value="tls">TLS</option>
                                <option value="starttls">STARTTLS</option>
                                <option value="none">Keine</option>
                            </select>
                            @error('smtpEncryption') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </x-admin.panel>
            </div>

            <x-admin.panel title="Notizen">
                <textarea rows="5" wire:model.defer="notes" class="{{ $input }}"></textarea>
                @error('notes') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror

                <div class="mt-4 flex justify-end">
                    <button type="button" wire:click="saveSettings" class="rounded-lg bg-primary-base px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-[#274d86]">
                        Accountdaten speichern
                    </button>
                </div>
            </x-admin.panel>
        @endif

        {{-- ===================== Mail-Registrierungs-Modal ===================== --}}
        @if (! $showMailRegistrationModal && $mailRegistrationRunId)
            @php
                $mailRegistrationBackgroundPollSeconds = max(1, min(60, (int) data_get($mailRegistrationStatus, 'livePreviewPollIntervalSeconds', 3)));
            @endphp
            <div class="hidden" wire:poll.{{ $mailRegistrationBackgroundPollSeconds }}s="refreshMailRegistration"></div>
        @endif

        <x-ui.dialog-modal wire:model="showMailRegistrationModal" maxWidth="6xl">
            <x-slot name="title">
                Mail-Registrierung beobachten
            </x-slot>

            <x-slot name="content">
                @php
                    $mailRegistrationPollSeconds = max(1, min(60, (int) data_get($mailRegistrationStatus, 'livePreviewPollIntervalSeconds', 3)));
                @endphp
                <div
                    @if(data_get($mailRegistrationStatus, 'isRunning')) wire:poll.{{ $mailRegistrationPollSeconds }}s="refreshMailRegistration" @endif
                    class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(420px,560px)]"
                >
                    <div class="grid gap-3">
                        <div class="overflow-hidden rounded-lg border border-slate-200 bg-slate-950">
                            <div class="flex items-center justify-between gap-3 border-b border-slate-800 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-300">
                                <div class="min-w-0">
                                    <div>Registrierung</div>
                                    @include('livewire.admin.config.partials.browser-window-status', [
                                        'windowStatus' => data_get($mailRegistrationStatus, 'registrationWindowStatus'),
                                    ])
                                </div>
                                @if(data_get($mailRegistrationStatus, 'registrationDebugDomUrl'))
                                    <a href="{{ data_get($mailRegistrationStatus, 'registrationDebugDomUrl') }}" download="mail-registration-window-dom.json" class="rounded border border-slate-700 px-2 py-1 text-[10px] text-slate-200 hover:bg-slate-800">
                                        DOM
                                    </a>
                                @endif
                            </div>
                            @if(data_get($mailRegistrationStatus, 'screenshotUrl'))
                                <img src="{{ data_get($mailRegistrationStatus, 'screenshotUrl') }}" alt="Registrierung Live Screenshot" class="aspect-video w-full object-contain">
                            @elseif(data_get($mailRegistrationStatus, 'livePreviewEnabled') === false)
                                <div class="flex aspect-video items-center justify-center text-sm font-semibold text-slate-300">
                                    Live-Screenshots sind deaktiviert.
                                </div>
                            @else
                                <div class="flex aspect-video items-center justify-center text-sm font-semibold text-slate-300">
                                    Noch kein Screenshot verfuegbar.
                                </div>
                            @endif
                        </div>

                        <div class="overflow-hidden rounded-lg border border-slate-200 bg-slate-950">
                            <div class="flex items-center justify-between gap-3 border-b border-slate-800 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-300">
                                <div class="min-w-0">
                                    <div>Webmail</div>
                                    @include('livewire.admin.config.partials.browser-window-status', [
                                        'windowStatus' => data_get($mailRegistrationStatus, 'webmailWindowStatus'),
                                    ])
                                </div>
                                @if(data_get($mailRegistrationStatus, 'webmailDebugDomUrl'))
                                    <a href="{{ data_get($mailRegistrationStatus, 'webmailDebugDomUrl') }}" download="mail-registration-webmail-window-dom.json" class="rounded border border-slate-700 px-2 py-1 text-[10px] text-slate-200 hover:bg-slate-800">
                                        DOM
                                    </a>
                                @endif
                            </div>
                            @if(data_get($mailRegistrationStatus, 'webmailScreenshotUrl'))
                                <img src="{{ data_get($mailRegistrationStatus, 'webmailScreenshotUrl') }}" alt="Webmail Live Screenshot" class="aspect-video w-full object-contain">
                            @elseif(data_get($mailRegistrationStatus, 'livePreviewEnabled') === false)
                                <div class="flex aspect-video items-center justify-center text-sm font-semibold text-slate-300">
                                    Live-Screenshots sind deaktiviert.
                                </div>
                            @else
                                <div class="flex aspect-video items-center justify-center text-sm font-semibold text-slate-300">
                                    Webmail-Fenster noch nicht geoeffnet.
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="rounded-lg border border-slate-200 bg-white p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</div>
                            <div class="mt-2 text-sm font-semibold text-slate-900">{{ data_get($mailRegistrationStatus, 'message', 'Noch kein Lauf gestartet.') }}</div>
                            <div class="mt-2 text-xs text-slate-500">
                                {{ data_get($mailRegistrationStatus, 'providerLabel', '-') }} · {{ data_get($mailRegistrationStatus, 'activeBrowserEngine', data_get($mailRegistrationStatus, 'requestedBrowserEngine', '-')) }}
                            </div>
                            <div class="mt-1 text-xs text-slate-500">
                                Script: {{ data_get($mailRegistrationStatus, 'scriptVersionLabel', 'mail_account.cjs v2') }}
                            </div>
                            <div class="mt-1 text-xs text-slate-500">
                                Screenshots: {{ data_get($mailRegistrationStatus, 'livePreviewEnabled', true) ? 'aktiv' : 'inaktiv' }} · Intervall: {{ data_get($mailRegistrationStatus, 'livePreviewIntervalSeconds', $mailRegistrationPollSeconds) }}s
                            </div>
                            <div class="mt-1 text-xs text-slate-500">
                                Browser-Aktivitaetscheck: {{ data_get($mailRegistrationStatus, 'browserActivityCheckEnabled', true) ? 'aktiv' : 'inaktiv' }}
                            </div>
                            @if(data_get($mailRegistrationStatus, 'processHeartbeatStatus.statusText'))
                                <div class="mt-2 rounded-md {{ data_get($mailRegistrationStatus, 'processHeartbeatStatus.stale') ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-800' }} px-3 py-2 text-xs font-semibold">
                                    {{ data_get($mailRegistrationStatus, 'processHeartbeatStatus.statusText') }}
                                </div>
                            @endif
                            @if(data_get($mailRegistrationStatus, 'result.webmailCheckPending') && data_get($mailRegistrationStatus, 'result.verificationWebmailCheckDueAt'))
                                <div class="mt-2 text-xs font-semibold text-amber-700">
                                    Webmail-Check faellig: {{ data_get($mailRegistrationStatus, 'result.verificationWebmailCheckDueAt') }}
                                </div>
                            @endif
                        </div>

                        @if((int) data_get($mailRegistrationStatus, 'pid') > 0)
                            <livewire:admin.processes.process-monitor
                                :compact="true"
                                :limit="30"
                                :show-header="false"
                                :auto-refresh="true"
                                :root-pid="(int) data_get($mailRegistrationStatus, 'pid')"
                                :run-id="data_get($mailRegistrationStatus, 'runId')"
                                :key="'person-mail-registration-processes-'.data_get($mailRegistrationStatus, 'runId', data_get($mailRegistrationStatus, 'pid'))"
                            />
                        @endif

                        @if(data_get($mailRegistrationStatus, 'result.account'))
                            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                                <div class="font-semibold">Accountdaten erkannt</div>
                                <div class="mt-1 break-all">{{ data_get($mailRegistrationStatus, 'result.account.email') }}</div>
                            </div>
                        @endif

                        <div class="max-h-[560px] overflow-auto rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ablauf</div>
                            <div class="mt-3 space-y-3">
                                @forelse(array_reverse(data_get($mailRegistrationStatus, 'events', [])) as $event)
                                    @php
                                        $debugDom = data_get($event, 'debugDom');
                                        $debugDomText = is_array($debugDom)
                                            ? json_encode($debugDom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                                            : (string) $debugDom;
                                    @endphp
                                    <div class="rounded-md bg-white p-3 text-xs shadow-sm">
                                        <div class="font-semibold text-slate-900">{{ data_get($event, 'stage', '-') }}</div>
                                        <div class="mt-1 text-slate-600">{{ data_get($event, 'message', '-') }}</div>
                                        <div class="mt-1 text-slate-400">{{ data_get($event, 'at', '') }}</div>
                                        @if($debugDomText !== '')
                                            <details class="mt-2 rounded border border-slate-200 bg-slate-950 text-slate-100">
                                                <summary class="cursor-pointer px-2 py-1 font-semibold text-slate-200">DOM Debug</summary>
                                                <pre class="max-h-72 overflow-auto whitespace-pre-wrap break-words p-2 text-[11px] leading-relaxed">{{ $debugDomText }}</pre>
                                            </details>
                                        @endif
                                    </div>
                                @empty
                                    <div class="text-sm text-slate-500">Noch keine Ablaufdaten.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </x-slot>

            <x-slot name="footer">
                <div class="flex justify-end gap-3">
                    <button type="button" wire:click="closeMailRegistrationModal" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                        Schliessen
                    </button>
                    <button type="button" wire:click="applyMailRegistrationResult" class="rounded-lg bg-primary-base px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-[#274d86]">
                        Ergebnis uebernehmen
                    </button>
                </div>
            </x-slot>
        </x-ui.dialog-modal>
    @endif
</div>
