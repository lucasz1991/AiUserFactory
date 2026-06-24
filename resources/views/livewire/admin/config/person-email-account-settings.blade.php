<div class="space-y-6">
    @if (session()->has('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    @if(! $person)
        <x-admin.panel>
            <div class="text-sm text-gray-500">Keine Person gefunden.</div>
        </x-admin.panel>
    @else
        <x-admin.panel title="E-Mail-Account" description="Zugangsdaten und technische Verbindungsdaten fuer die Persona-Mailbox.">
            <x-slot name="actions">
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('admin.settings', ['tab' => 'mail-registration']) }}" wire:navigate class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-50">
                        Mail-Registrierung
                    </a>
                    <button type="button" wire:click="startMailRegistration" wire:loading.attr="disabled" class="rounded-md border border-blue-200 bg-white px-4 py-2 text-sm font-semibold text-blue-700 shadow-sm hover:bg-blue-50 disabled:cursor-wait disabled:opacity-60">
                        Account registrieren
                    </button>
                    <button type="button" wire:click="buildWebmailSession" wire:loading.attr="disabled" class="rounded-md border border-emerald-200 bg-white px-4 py-2 text-sm font-semibold text-emerald-700 shadow-sm hover:bg-emerald-50 disabled:cursor-wait disabled:opacity-60">
                        Webmail-Session speichern
                    </button>
                    <button type="button" wire:click="saveSettings" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                        Speichern
                    </button>
                </div>
            </x-slot>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="email-account-address" class="block text-sm font-medium text-gray-700">E-Mail-Adresse</label>
                    <input id="email-account-address" type="email" wire:model.defer="emailAddress" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('emailAddress') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="email-account-provider" class="block text-sm font-medium text-gray-700">Provider</label>
                    <input id="email-account-provider" type="text" wire:model.defer="provider" placeholder="z.B. Gmail, Outlook, IONOS" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('provider') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="email-account-username" class="block text-sm font-medium text-gray-700">Login / Benutzername</label>
                    <input id="email-account-username" type="text" wire:model.defer="accountUsername" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('accountUsername') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="email-account-password" class="block text-sm font-medium text-gray-700">Passwort</label>
                    <input id="email-account-password" type="password" wire:model.defer="accountPassword" autocomplete="new-password" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <div class="mt-2 flex items-center justify-between gap-3 text-xs text-gray-500">
                        <span>
                            @if($hasStoredPassword)
                                Passwort ist gespeichert. Leeres Feld behaelt das vorhandene Passwort.
                            @else
                                Noch kein Passwort gespeichert.
                            @endif
                        </span>
                        @if($hasStoredPassword)
                            <button type="button" wire:click="clearStoredPassword" wire:confirm="Gespeichertes E-Mail-Passwort wirklich loeschen?" class="font-semibold text-red-600 hover:text-red-700">
                                Passwort loeschen
                            </button>
                        @endif
                    </div>
                    @error('accountPassword') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="email-account-recovery-email" class="block text-sm font-medium text-gray-700">Recovery-E-Mail</label>
                    <input id="email-account-recovery-email" type="email" wire:model.defer="recoveryEmail" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('recoveryEmail') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="email-account-recovery-phone" class="block text-sm font-medium text-gray-700">Recovery-Telefon</label>
                    <input id="email-account-recovery-phone" type="text" wire:model.defer="recoveryPhone" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('recoveryPhone') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="email-account-webmail-url" class="block text-sm font-medium text-gray-700">Webmail URL</label>
                    <input id="email-account-webmail-url" type="url" wire:model.defer="webmailUrl" placeholder="https://mail.example.com" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('webmailUrl') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-admin.panel>

        @if($webmailSessionResult !== [])
            <div class="rounded-lg border p-4 text-sm {{ data_get($webmailSessionResult, 'ok') ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-amber-200 bg-amber-50 text-amber-950' }}">
                <p class="font-semibold">{{ data_get($webmailSessionResult, 'statusMessage', 'Webmail-Sessionlauf abgeschlossen.') }}</p>
                <p class="mt-1 text-xs">Cookies: {{ data_get($webmailSessionResult, 'cookieCount', data_get($webmailSessionResult, 'sessionSummary.cookieCount', 0)) }}</p>
                @if(!empty($webmailSessionResult['warnings']))
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
                        @foreach($webmailSessionResult['warnings'] as $warning)
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
                        <label for="email-account-imap-host" class="block text-sm font-medium text-gray-700">Host</label>
                        <input id="email-account-imap-host" type="text" wire:model.defer="imapHost" placeholder="imap.example.com" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('imapHost') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="email-account-imap-port" class="block text-sm font-medium text-gray-700">Port</label>
                        <input id="email-account-imap-port" type="number" min="1" max="65535" wire:model.defer="imapPort" placeholder="993" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('imapPort') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="md:col-span-3">
                        <label for="email-account-imap-encryption" class="block text-sm font-medium text-gray-700">Verschluesselung</label>
                        <select id="email-account-imap-encryption" wire:model.defer="imapEncryption" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
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
                        <label for="email-account-smtp-host" class="block text-sm font-medium text-gray-700">Host</label>
                        <input id="email-account-smtp-host" type="text" wire:model.defer="smtpHost" placeholder="smtp.example.com" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('smtpHost') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="email-account-smtp-port" class="block text-sm font-medium text-gray-700">Port</label>
                        <input id="email-account-smtp-port" type="number" min="1" max="65535" wire:model.defer="smtpPort" placeholder="587" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('smtpPort') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="md:col-span-3">
                        <label for="email-account-smtp-encryption" class="block text-sm font-medium text-gray-700">Verschluesselung</label>
                        <select id="email-account-smtp-encryption" wire:model.defer="smtpEncryption" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
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
            <textarea rows="5" wire:model.defer="notes" class="block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
            @error('notes') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="mt-4 flex justify-end">
                <button type="button" wire:click="saveSettings" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                    Accountdaten speichern
                </button>
            </div>
        </x-admin.panel>

        <x-dialog-modal wire:model="showMailRegistrationModal" maxWidth="6xl">
            <x-slot name="title">
                Mail-Registrierung beobachten
            </x-slot>

            <x-slot name="content">
                <div
                    @if(data_get($mailRegistrationStatus, 'isRunning')) wire:poll.2500ms="refreshMailRegistration" @endif
                    class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(420px,560px)]"
                >
                    <div class="overflow-hidden rounded-lg border border-slate-200 bg-slate-950">
                        @if(data_get($mailRegistrationStatus, 'screenshotUrl'))
                            <img src="{{ data_get($mailRegistrationStatus, 'screenshotUrl') }}" alt="Live Screenshot" class="aspect-video w-full object-contain">
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

                    <div class="space-y-4">
                        <div class="rounded-lg border border-slate-200 bg-white p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</div>
                            <div class="mt-2 text-sm font-semibold text-slate-900">{{ data_get($mailRegistrationStatus, 'message', 'Noch kein Lauf gestartet.') }}</div>
                            <div class="mt-2 text-xs text-slate-500">
                                {{ data_get($mailRegistrationStatus, 'providerLabel', '-') }} · {{ data_get($mailRegistrationStatus, 'activeBrowserEngine', data_get($mailRegistrationStatus, 'requestedBrowserEngine', '-')) }}
                            </div>
                            <div class="mt-1 text-xs text-slate-500">
                                Script: {{ data_get($mailRegistrationStatus, 'scriptVersionLabel', 'mail_account.cjs v1') }}
                            </div>
                        </div>

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
                    <button type="button" wire:click="closeMailRegistrationModal" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                        Schliessen
                    </button>
                    <button type="button" wire:click="applyMailRegistrationResult" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                        Ergebnis uebernehmen
                    </button>
                </div>
            </x-slot>
        </x-dialog-modal>
    @endif
</div>
