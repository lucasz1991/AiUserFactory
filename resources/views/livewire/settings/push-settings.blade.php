{{--
    Push-Panel (Spur W, portiert aus RailTime).

    Der komplette Zustand liegt in der Alpine-Komponente `factoryPushSettings`
    aus resources/js/pwa.js. Livewire rendert hier nur einmalig die Huelle und
    die serverseitige Konfiguration.
--}}
<div
    x-data="factoryPushSettings(@js($clientConfig))"
    data-testid="push-settings"
    class="rounded-xl border border-gray-200 bg-white shadow-sm"
>
    <div class="flex items-start gap-3 border-b border-gray-200 p-4 sm:p-5">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-base/10 text-primary-base">
            <i data-feather="bell" class="h-5 w-5"></i>
        </span>
        <div class="min-w-0">
            <h2 class="text-16 font-semibold text-gray-900">Push-Benachrichtigungen</h2>
            <p class="mt-1 text-13 leading-6 text-gray-500">
                Dieses Geraet kann Benachrichtigungen empfangen, auch wenn kein Browserfenster offen ist.
            </p>
        </div>
    </div>

    <div class="space-y-4 p-4 sm:p-5">
        {{-- Statuszeile --}}
        <div class="flex items-center justify-between gap-4 rounded-lg bg-gray-50 px-4 py-3 ring-1 ring-inset ring-gray-200">
            <div class="flex min-w-0 items-center gap-3">
                <span
                    class="h-2.5 w-2.5 shrink-0 rounded-full"
                    :class="currentDeviceSubscribed ? 'bg-emerald-500' : 'bg-gray-400'"
                    aria-hidden="true"
                ></span>
                <div class="min-w-0">
                    <p class="truncate text-base font-semibold text-gray-900" x-text="statusTitle()"></p>
                    <p class="mt-0.5 text-13 leading-6 text-gray-500" x-text="statusDescription()"></p>
                </div>
            </div>

            <span
                class="shrink-0 rounded-full px-2.5 py-1 text-11 font-semibold"
                :class="currentDeviceSubscribed
                    ? 'bg-emerald-100 text-emerald-700'
                    : 'bg-gray-200 text-gray-600'"
                x-text="currentDeviceSubscribed ? 'Aktiv' : 'Inaktiv'"
            ></span>
        </div>

        {{-- Plattformhinweise --}}
        <p
            x-cloak
            x-show.important="showIosInstallHelp"
            class="rounded-lg bg-sky-50 px-4 py-3 text-13 leading-6 text-sky-900 ring-1 ring-inset ring-sky-200"
        >
            Auf iPhone und iPad gibt es Push nur in der installierten App:
            Teilen-Symbol antippen, <span class="font-semibold">Zum Home-Bildschirm</span> waehlen,
            danach die App von dort oeffnen und hier zurueckkehren.
        </p>

        <p
            x-cloak
            x-show.important="showDesktopInstallHelp"
            class="rounded-lg bg-gray-50 px-4 py-3 text-13 leading-6 text-gray-600 ring-1 ring-inset ring-gray-200"
        >
            Dieser Browser bietet keinen Installationsdialog an.
            In Safari auf dem Mac fuehrt <span class="font-semibold">Ablage &rarr; Zum Dock hinzufuegen</span> zum Ziel,
            in Chrome und Edge das Installationssymbol rechts in der Adressleiste.
            Push funktioniert auch ohne Installation, solange der Browser laeuft.
        </p>

        {{-- Aktionen --}}
        <div class="flex flex-wrap gap-2">
            @if ($showInstall)
                <button
                    x-cloak
                    x-show.important="canPromptInstall"
                    type="button"
                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-base font-semibold text-white transition hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-base focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60"
                    :disabled="busy !== null"
                    @click="promptInstall()"
                >
                    <i data-feather="download" class="h-4 w-4"></i>
                    App installieren
                </button>
            @endif

            <button
                x-cloak
                x-show.important="canSubscribe"
                type="button"
                class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-primary-base px-4 py-2 text-base font-semibold text-white transition hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-primary-base focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60"
                :disabled="busy !== null"
                @click="subscribe()"
            >
                <i data-feather="bell" class="h-4 w-4"></i>
                <span x-show.important="busy !== 'subscribe'">Benachrichtigungen aktivieren</span>
                <span x-cloak x-show.important="busy === 'subscribe'">Wird aktiviert &hellip;</span>
            </button>

            <button
                x-cloak
                x-show.important="canUnsubscribe"
                type="button"
                class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-base font-semibold text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-base focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60"
                :disabled="busy !== null"
                @click="unsubscribe()"
            >
                <i data-feather="bell-off" class="h-4 w-4"></i>
                Dieses Geraet abmelden
            </button>

            @if ($showTest)
                <button
                    x-cloak
                    x-show.important="canTest"
                    type="button"
                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-base font-semibold text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-base focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60"
                    :disabled="busy !== null"
                    @click="sendTest()"
                >
                    <i data-feather="send" class="h-4 w-4"></i>
                    <span x-show.important="busy !== 'test'">Testbenachrichtigung senden</span>
                    <span x-cloak x-show.important="busy === 'test'">Wird gesendet &hellip;</span>
                </button>
            @endif
        </div>

        {{-- Kategorien. Erscheinen erst, wenn ueberhaupt ein Geraet angemeldet ist. --}}
        @if ($showPreferences)
            <div x-cloak x-show.important="showPreferences" class="space-y-2 border-t border-gray-200 pt-4">
                <p class="text-13 font-semibold uppercase tracking-wide text-gray-500">Wovon willst du erfahren?</p>

                @foreach ($categories as $category)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg px-3 py-2.5 transition hover:bg-gray-50">
                        <input
                            type="checkbox"
                            class="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary-base focus:ring-primary-base"
                            :checked="preferences[@js($category->value)]"
                            :disabled="busy !== null"
                            @change="savePreference(@js($category->value), $event.target.checked)"
                        >
                        <span class="min-w-0">
                            <span class="block text-base font-medium text-gray-900">{{ $category->label() }}</span>
                            <span class="block text-13 leading-6 text-gray-500">{{ $category->description() }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        @endif

        {{-- Meldungen --}}
        <div aria-live="polite" class="space-y-2">
            <p
                x-cloak
                x-show.important="error"
                class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-13 text-red-800"
                x-text="error"
            ></p>
            <p
                x-cloak
                x-show.important="success"
                class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-13 text-emerald-800"
                x-text="success"
            ></p>
        </div>

        {{-- Serverseitige Diagnose. Nur Ursachencodes, niemals Schluesselmaterial. --}}
        @if ($pushDiagnostics['issues'] !== [])
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-13 leading-6 text-amber-900">
                <p class="font-semibold">Serverseitig offen:</p>
                <ul class="mt-1 list-inside list-disc">
                    @foreach ($pushDiagnostics['issues'] as $issue)
                        <li>
                            @switch($issue)
                                @case('disabled')
                                    Web-Push ist abgeschaltet (<code>WEBPUSH_ENABLED</code>).
                                    @break
                                @case('subject_missing')
                                    Es ist kein VAPID-Subject gesetzt und <code>APP_URL</code> liefert kein gueltiges https.
                                    @break
                                @case('subject_invalid')
                                    Das VAPID-Subject ist weder eine <code>mailto:</code>-Adresse noch eine https-URL.
                                    @break
                                @case('public_key_missing')
                                    Der oeffentliche VAPID-Schluessel fehlt.
                                    @break
                                @case('private_key_missing')
                                    Der private VAPID-Schluessel fehlt.
                                    @break
                                @case('credentials_invalid')
                                    Das VAPID-Schluesselpaar ist ungueltig.
                                    @break
                                @case('auto_provision_failed')
                                    Die Schluessel konnten nicht automatisch angelegt werden — <code>storage/app/private</code> ist vermutlich nicht beschreibbar.
                                    @break
                                @default
                                    {{ $issue }}
                            @endswitch
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>
