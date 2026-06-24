<div class="space-y-8">
    <div>
        <h2 class="text-lg font-semibold text-gray-900">Mail-Registrierung</h2>
        <p class="mt-1 text-sm text-gray-500">
            Browser-gestuetzter Registrierungsflow mit optionalem Live-Screenshot und DOM-Debug. Der erste Provider ist als beobachteter eigener Provider aktiv; weitere Provider-Slots sind vorbereitet.
        </p>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-5">
        <h3 class="text-sm font-semibold text-gray-900">Browser</h3>

        <div class="mt-5 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
            <div>
                <label for="mail-registration-browser-engine" class="block text-sm font-medium text-gray-700">Browser Engine</label>
                <select id="mail-registration-browser-engine" wire:model.defer="browserEngine" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="cloak-with-chrome-fallback">Cloak mit Chrome-Fallback</option>
                    <option value="cloak">Cloak Browser</option>
                    <option value="chrome">Chrome / Puppeteer</option>
                </select>
                @error('browserEngine') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="mail-registration-navigation-timeout" class="block text-sm font-medium text-gray-700">Navigation-Timeout (Sek.)</label>
                <input id="mail-registration-navigation-timeout" type="number" min="30" max="300" wire:model.defer="navigationTimeoutSeconds" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('navigationTimeoutSeconds') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="mail-registration-observation-timeout" class="block text-sm font-medium text-gray-700">Beobachtungszeit (Sek.)</label>
                <input id="mail-registration-observation-timeout" type="number" min="30" max="1800" wire:model.defer="observationTimeoutSeconds" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('observationTimeoutSeconds') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-3">
            <label class="inline-flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
                <input type="checkbox" wire:model.defer="cloakHumanizeEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                Cloak Humanize aktivieren
            </label>

            <label class="inline-flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
                <input type="checkbox" wire:model.defer="headlessEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                Headless ausfuehren
            </label>

            <label class="inline-flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
                <input type="checkbox" wire:model.defer="livePreviewEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                Live-Screenshots speichern
            </label>

            <label class="inline-flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
                <input type="checkbox" wire:model.defer="domDebugEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                DOM-Debug protokollieren
            </label>

            <div>
                <label for="mail-registration-cloak-preset" class="block text-sm font-medium text-gray-700">Cloak Human Preset</label>
                <input id="mail-registration-cloak-preset" type="text" wire:model.defer="cloakHumanPreset" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('cloakHumanPreset') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-gray-900">Register Email Account</h3>
                <p class="mt-1 text-sm text-gray-500">Webmail-Postfach fuer Registrierungs- und Verifikationsmails.</p>
            </div>
            <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                <input type="checkbox" wire:model.defer="verificationMailboxEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                Als Verifikations-Postfach nutzen
            </label>
            <button type="button" wire:click="buildVerificationMailboxWebmailSession" wire:loading.attr="disabled" class="rounded-md border border-emerald-200 bg-white px-4 py-2 text-sm font-semibold text-emerald-700 shadow-sm hover:bg-emerald-50 disabled:cursor-wait disabled:opacity-60">
                Webmail-Session speichern
            </button>
        </div>

        <div class="mt-5 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
            <div>
                <label for="verification-mailbox-email" class="block text-sm font-medium text-gray-700">E-Mail-Adresse</label>
                <input id="verification-mailbox-email" type="email" wire:model.defer="verificationMailboxEmail" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('verificationMailboxEmail') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="verification-mailbox-provider" class="block text-sm font-medium text-gray-700">Provider</label>
                <select id="verification-mailbox-provider" wire:model.live="verificationMailboxProvider" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="proton">Proton</option>
                    <option value="gmx">GMX</option>
                </select>
                @error('verificationMailboxProvider') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="verification-mailbox-username" class="block text-sm font-medium text-gray-700">Login / Benutzername</label>
                <input id="verification-mailbox-username" type="text" wire:model.defer="verificationMailboxUsername" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('verificationMailboxUsername') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="verification-mailbox-password" class="block text-sm font-medium text-gray-700">Passwort</label>
                <input id="verification-mailbox-password" type="password" wire:model.defer="verificationMailboxPassword" autocomplete="new-password" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <div class="mt-2 flex items-center justify-between gap-3 text-xs text-gray-500">
                    <span>
                        @if($hasStoredVerificationMailboxPassword)
                            Passwort ist gespeichert. Leeres Feld behaelt das vorhandene Passwort.
                        @else
                            Noch kein Passwort gespeichert.
                        @endif
                    </span>
                    @if($hasStoredVerificationMailboxPassword)
                        <button type="button" wire:click="clearVerificationMailboxPassword" wire:confirm="Gespeichertes Passwort des Verifikations-Postfachs wirklich loeschen?" class="font-semibold text-red-600 hover:text-red-700">
                            Passwort loeschen
                        </button>
                    @endif
                </div>
                @error('verificationMailboxPassword') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="verification-mailbox-webmail-url" class="block text-sm font-medium text-gray-700">Webmail URL</label>
                <input id="verification-mailbox-webmail-url" type="url" wire:model.defer="verificationMailboxWebmailUrl" placeholder="https://mail.example.com" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('verificationMailboxWebmailUrl') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        @if($verificationMailboxSessionResult !== [])
            <div class="mt-5 rounded-lg border p-4 text-sm {{ data_get($verificationMailboxSessionResult, 'ok') ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-amber-200 bg-amber-50 text-amber-950' }}">
                <p class="font-semibold">{{ data_get($verificationMailboxSessionResult, 'statusMessage', 'Webmail-Sessionlauf abgeschlossen.') }}</p>
                <p class="mt-1 text-xs">Cookies: {{ data_get($verificationMailboxSessionResult, 'cookieCount', data_get($verificationMailboxSessionResult, 'sessionSummary.cookieCount', 0)) }}</p>
                @if(!empty($verificationMailboxSessionResult['warnings']))
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
                        @foreach($verificationMailboxSessionResult['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-gray-900">Provider 1</h3>
                <p class="mt-1 text-sm text-gray-500">Aktiver Adapter: beobachteter Browserflow ohne Telefonpflicht im lokalen Setting.</p>
            </div>
            <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                <input type="checkbox" wire:model.defer="providerOneEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                Aktiv
            </label>
        </div>

        <div class="mt-5 grid gap-6 md:grid-cols-2">
            <div>
                <label for="mail-provider-one-mode" class="block text-sm font-medium text-gray-700">Adapter</label>
                <select id="mail-provider-one-mode" wire:model.defer="providerOneMode" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="proton_username_check">Proton: Username pruefen</option>
                    <option value="observed_manual">Beobachteter Browserflow</option>
                </select>
                @error('providerOneMode') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="mail-provider-one-label" class="block text-sm font-medium text-gray-700">Name</label>
                <input id="mail-provider-one-label" type="text" wire:model.defer="providerOneLabel" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('providerOneLabel') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="mail-provider-one-url" class="block text-sm font-medium text-gray-700">Registrierungs-URL</label>
                <input id="mail-provider-one-url" type="url" wire:model.defer="providerOneRegistrationUrl" placeholder="https://mail.example.com/register" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('providerOneRegistrationUrl') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="mail-provider-one-completion-url" class="block text-sm font-medium text-gray-700">Abschluss-URL enthaelt</label>
                <input id="mail-provider-one-completion-url" type="text" wire:model.defer="providerOneCompletionUrlContains" placeholder="/welcome" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('providerOneCompletionUrlContains') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="mail-provider-one-completion-selector" class="block text-sm font-medium text-gray-700">Abschluss-CSS-Selector</label>
                <input id="mail-provider-one-completion-selector" type="text" wire:model.defer="providerOneCompletionSelector" placeholder="[data-registration-complete]" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('providerOneCompletionSelector') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="md:col-span-2">
                <label for="mail-provider-one-webmail-url" class="block text-sm font-medium text-gray-700">Webmail URL</label>
                <input id="mail-provider-one-webmail-url" type="url" wire:model.defer="providerOneWebmailUrl" placeholder="https://mail.example.com" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('providerOneWebmailUrl') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-lg border border-gray-200 bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900">Provider 2</h3>
                    <p class="mt-1 text-sm text-gray-500">Slot vorbereitet, Adapter noch geplant.</p>
                </div>
                <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                    <input type="checkbox" wire:model.defer="providerTwoEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                    Aktiv
                </label>
            </div>
            <div class="mt-5 space-y-4">
                <div>
                    <label for="mail-provider-two-label" class="block text-sm font-medium text-gray-700">Name</label>
                    <input id="mail-provider-two-label" type="text" wire:model.defer="providerTwoLabel" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('providerTwoLabel') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="mail-provider-two-url" class="block text-sm font-medium text-gray-700">Registrierungs-URL</label>
                    <input id="mail-provider-two-url" type="url" wire:model.defer="providerTwoRegistrationUrl" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('providerTwoRegistrationUrl') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900">Provider 3</h3>
                    <p class="mt-1 text-sm text-gray-500">Slot vorbereitet, Adapter noch geplant.</p>
                </div>
                <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                    <input type="checkbox" wire:model.defer="providerThreeEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                    Aktiv
                </label>
            </div>
            <div class="mt-5 space-y-4">
                <div>
                    <label for="mail-provider-three-label" class="block text-sm font-medium text-gray-700">Name</label>
                    <input id="mail-provider-three-label" type="text" wire:model.defer="providerThreeLabel" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('providerThreeLabel') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="mail-provider-three-url" class="block text-sm font-medium text-gray-700">Registrierungs-URL</label>
                    <input id="mail-provider-three-url" type="url" wire:model.defer="providerThreeRegistrationUrl" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('providerThreeRegistrationUrl') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>
    </div>

    <div class="flex flex-wrap justify-end gap-3">
        <button type="button" wire:click="startTestRun" wire:loading.attr="disabled" class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60">
            Testlauf starten
        </button>
        <button type="button" wire:click="saveSettings" wire:loading.attr="disabled" class="inline-flex items-center justify-center rounded-md bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
            Speichern
        </button>
    </div>

    <x-dialog-modal wire:model="showVerificationMailboxSessionModal" maxWidth="6xl">
        <x-slot name="title">
            Webmail-Session beobachten
        </x-slot>

        <x-slot name="content">
            <div
                @if(data_get($verificationMailboxSessionResult, 'isRunning')) wire:poll.2500ms="refreshVerificationMailboxSessionRun" @endif
                class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(360px,460px)]"
            >
                <div class="overflow-hidden rounded-lg border border-slate-200 bg-slate-950">
                    @if(data_get($verificationMailboxSessionResult, 'screenshotUrl'))
                        <img src="{{ data_get($verificationMailboxSessionResult, 'screenshotUrl') }}" alt="Webmail Live Screenshot" class="aspect-video w-full object-contain">
                    @else
                        <div class="flex aspect-video items-center justify-center text-sm font-semibold text-slate-300">
                            Noch kein Screenshot verfuegbar.
                        </div>
                    @endif
                </div>

                <div class="space-y-4">
                    <div class="rounded-lg border border-slate-200 bg-white p-4">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</div>
                        <div class="mt-2 text-sm font-semibold text-slate-900">
                            {{ data_get($verificationMailboxSessionResult, 'statusMessage', data_get($verificationMailboxSessionResult, 'message', 'Noch kein Webmail-Sessionlauf gestartet.')) }}
                        </div>
                        <div class="mt-2 text-xs text-slate-500">
                            {{ data_get($verificationMailboxSessionResult, 'providerKey', $verificationMailboxProvider) }} · {{ data_get($verificationMailboxSessionResult, 'activeBrowserEngine', data_get($verificationMailboxSessionResult, 'requestedBrowserEngine', '-')) }}
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            Script: {{ data_get($verificationMailboxSessionResult, 'scriptName', 'webmail_session.cjs') }} v{{ data_get($verificationMailboxSessionResult, 'scriptVersion', 1) }}
                        </div>
                        <div class="mt-2 text-xs text-slate-500">
                            Cookies: {{ data_get($verificationMailboxSessionResult, 'cookieCount', data_get($verificationMailboxSessionResult, 'sessionSummary.cookieCount', 0)) }}
                        </div>
                        @if(data_get($verificationMailboxSessionResult, 'stage'))
                            <div class="mt-1 text-xs text-slate-500">
                                Schritt: {{ data_get($verificationMailboxSessionResult, 'stage') }}
                            </div>
                        @endif
                    </div>

                    @if(!empty($verificationMailboxSessionResult['events']))
                        <div class="max-h-64 overflow-auto rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ablauf</div>
                            <div class="mt-3 space-y-2">
                                @foreach(array_reverse(data_get($verificationMailboxSessionResult, 'events', [])) as $event)
                                    <div class="rounded-md bg-white p-3 text-xs shadow-sm">
                                        <div class="font-semibold text-slate-900">{{ data_get($event, 'stage', '-') }}</div>
                                        <div class="mt-1 text-slate-600">{{ data_get($event, 'message', '-') }}</div>
                                        <div class="mt-1 text-slate-400">{{ data_get($event, 'at', '') }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @php
                        $webmailDebugDom = data_get($verificationMailboxSessionResult, 'debugDom');
                        $webmailDebugDomText = is_array($webmailDebugDom)
                            ? json_encode($webmailDebugDom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                            : (string) $webmailDebugDom;
                    @endphp
                    @if($webmailDebugDomText !== '')
                        <div x-data="{ open: false }" class="rounded-lg border border-slate-200 bg-white text-xs">
                            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                                <button type="button" x-on:click="open = !open" class="font-semibold uppercase tracking-wide text-slate-600">
                                    Aktueller DOM
                                </button>
                                @if(data_get($verificationMailboxSessionResult, 'debugDomUrl'))
                                    <a href="{{ data_get($verificationMailboxSessionResult, 'debugDomUrl') }}" download="webmail-session-dom.json" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                                        DOM herunterladen
                                    </a>
                                @endif
                            </div>
                            <div x-show="open" x-cloak x-collapse.duration.300ms>
                                <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words bg-slate-950 p-3 text-[11px] leading-relaxed text-slate-100">{{ $webmailDebugDomText }}</pre>
                            </div>
                        </div>
                    @endif

                    @if(!empty($verificationMailboxSessionResult['notes']))
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs text-slate-700">
                            <div class="font-semibold uppercase tracking-wide text-slate-500">Notizen</div>
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                @foreach($verificationMailboxSessionResult['notes'] as $note)
                                    <li>{{ $note }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if(!empty($verificationMailboxSessionResult['warnings']))
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-xs text-amber-950">
                            <div class="font-semibold uppercase tracking-wide text-amber-700">Hinweise</div>
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                @foreach($verificationMailboxSessionResult['warnings'] as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <button type="button" wire:click="closeVerificationMailboxSessionModal" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                Schliessen
            </button>
        </x-slot>
    </x-dialog-modal>

    <x-dialog-modal wire:model="showRegistrationRunModal" maxWidth="6xl">
        <x-slot name="title">
            Mail-Registrierung beobachten
        </x-slot>

        <x-slot name="content">
            <div
                @if(data_get($registrationRunStatus, 'isRunning')) wire:poll.2500ms="refreshRegistrationRun" @endif
                class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(420px,560px)]"
            >
                <div class="grid gap-3">
                    <div class="overflow-hidden rounded-lg border border-slate-200 bg-slate-950">
                        <div class="border-b border-slate-800 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-300">Registrierung</div>
                        @if(data_get($registrationRunStatus, 'screenshotUrl'))
                            <img src="{{ data_get($registrationRunStatus, 'screenshotUrl') }}" alt="Registrierung Live Screenshot" class="aspect-video w-full object-contain">
                        @elseif(data_get($registrationRunStatus, 'livePreviewEnabled') === false)
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
                        <div class="border-b border-slate-800 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-300">Webmail</div>
                        @if(data_get($registrationRunStatus, 'webmailScreenshotUrl'))
                            <img src="{{ data_get($registrationRunStatus, 'webmailScreenshotUrl') }}" alt="Webmail Live Screenshot" class="aspect-video w-full object-contain">
                        @elseif(data_get($registrationRunStatus, 'livePreviewEnabled') === false)
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
                        <div class="mt-2 text-sm font-semibold text-slate-900">{{ data_get($registrationRunStatus, 'message', 'Noch kein Lauf gestartet.') }}</div>
                        <div class="mt-2 text-xs text-slate-500">
                            {{ data_get($registrationRunStatus, 'providerLabel', '-') }} · {{ data_get($registrationRunStatus, 'activeBrowserEngine', data_get($registrationRunStatus, 'requestedBrowserEngine', '-')) }}
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            Script: {{ data_get($registrationRunStatus, 'scriptVersionLabel', 'mail_account.cjs v2') }}
                        </div>
                        @if(data_get($registrationRunStatus, 'result.webmailCheckPending') && data_get($registrationRunStatus, 'result.verificationWebmailCheckDueAt'))
                            <div class="mt-2 text-xs font-semibold text-amber-700">
                                Webmail-Check faellig: {{ data_get($registrationRunStatus, 'result.verificationWebmailCheckDueAt') }}
                            </div>
                        @endif
                        @if(data_get($registrationRunStatus, 'debugDomUrl'))
                            <div class="mt-3">
                                <a href="{{ data_get($registrationRunStatus, 'debugDomUrl') }}" download="mail-registration-dom.json" class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                                    DOM herunterladen
                                </a>
                            </div>
                        @endif
                    </div>

                    <div class="max-h-[560px] overflow-auto rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ablauf</div>
                        <div class="mt-3 space-y-3">
                            @forelse(array_reverse(data_get($registrationRunStatus, 'events', [])) as $event)
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
            <button type="button" wire:click="closeRegistrationRunModal" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                Schliessen
            </button>
        </x-slot>
    </x-dialog-modal>
</div>
