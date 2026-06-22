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
                <button type="button" wire:click="saveSettings" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                    Speichern
                </button>
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
    @endif
</div>
