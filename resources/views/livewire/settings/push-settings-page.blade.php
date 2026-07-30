{{-- Vollseitiger Einstieg /app-installation (Spur W). --}}
<div class="space-y-6" x-data="factoryPwaInstall({
    messages: {
        installed: 'Die App ist auf diesem Geraet installiert.',
        ready: 'Dieser Browser kann die App direkt installieren.',
        manual: 'Dieser Browser installiert nur ueber sein eigenes Menue — die Anleitung unten zeigt den Weg.',
        accepted: 'Die App wird installiert.',
        failed: 'Die Installation konnte nicht gestartet werden.',
    },
    targets: {
        ios: '#install-ios',
        android: '#install-android',
        desktop: '#install-desktop',
        fallback: '#install-desktop',
    },
})">
    <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
        <p class="text-11 font-semibold uppercase tracking-widest text-primary-base">Factory AI</p>
        <h1 class="mt-2 text-22 font-bold tracking-tight text-gray-900">App installieren und steuern</h1>
        <p class="mt-2 max-w-3xl text-base leading-7 text-gray-600">
            Factory AI laesst sich wie eine native Anwendung installieren: eigenes Fenster ohne
            Adressleiste, eigenes Symbol im Startmenue oder auf dem Home-Bildschirm und
            Benachrichtigungen, die auch ankommen, wenn kein Browser offen ist.
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button
                type="button"
                class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-primary-base px-4 py-2 text-base font-semibold text-white transition hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-primary-base focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="disabled"
                @click="installApp()"
            >
                <i data-feather="download" class="h-4 w-4"></i>
                <span x-show.important="mode !== 'installed'">App installieren</span>
                <span x-cloak x-show.important="mode === 'installed'">Bereits installiert</span>
            </button>
            <p class="text-13 leading-6 text-gray-500" x-text="statusText()"></p>
        </div>

        <div aria-live="polite" class="mt-3 space-y-2">
            <p x-cloak x-show.important="notice" class="rounded-lg bg-gray-50 px-4 py-3 text-13 text-gray-700 ring-1 ring-inset ring-gray-200" x-text="notice"></p>
            <p x-cloak x-show.important="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-13 text-red-800" x-text="error"></p>
        </div>
    </section>

    @livewire('settings.push-settings', ['showTest' => true, 'showInstall' => false])

    <section class="grid gap-4 lg:grid-cols-3">
        <article id="install-desktop" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-16 font-semibold text-gray-900">Windows, macOS, Linux</h2>
            <ol class="mt-3 list-decimal space-y-2 pl-5 text-13 leading-6 text-gray-600">
                <li>In Chrome oder Edge auf das Installationssymbol rechts in der Adressleiste klicken — oder oben den Knopf <span class="font-semibold">App installieren</span> benutzen.</li>
                <li>In Safari auf dem Mac stattdessen <span class="font-semibold">Ablage &rarr; Zum Dock hinzufuegen</span> waehlen.</li>
                <li>Firefox installiert keine Web-Apps; Push funktioniert dort trotzdem, solange der Browser laeuft.</li>
            </ol>
        </article>

        <article id="install-android" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-16 font-semibold text-gray-900">Android</h2>
            <ol class="mt-3 list-decimal space-y-2 pl-5 text-13 leading-6 text-gray-600">
                <li>Seite in Chrome oeffnen.</li>
                <li>Menue (drei Punkte) &rarr; <span class="font-semibold">App installieren</span> beziehungsweise <span class="font-semibold">Zum Startbildschirm hinzufuegen</span>.</li>
                <li>App vom Startbildschirm oeffnen und oben Benachrichtigungen aktivieren.</li>
            </ol>
        </article>

        <article id="install-ios" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-16 font-semibold text-gray-900">iPhone und iPad</h2>
            <ol class="mt-3 list-decimal space-y-2 pl-5 text-13 leading-6 text-gray-600">
                <li>Seite in Safari oeffnen (Chrome auf iOS kann es nicht).</li>
                <li>Teilen-Symbol &rarr; <span class="font-semibold">Zum Home-Bildschirm</span>.</li>
                <li>App vom Home-Bildschirm starten. <span class="font-semibold">Erst danach</span> laesst iOS Benachrichtigungen zu — im Safari-Tab bleibt der Knopf aus.</li>
            </ol>
        </article>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-5 text-13 leading-6 text-gray-600 shadow-sm">
        <h2 class="text-16 font-semibold text-gray-900">Was technisch passiert</h2>
        <ul class="mt-3 list-disc space-y-1.5 pl-5">
            <li>Beim Aktivieren legt der Browser ein Push-Abo beim Dienst seines Herstellers an (Google, Mozilla, Apple, Microsoft). Nur dessen Endpunkt wird hier gespeichert — verschluesselt.</li>
            <li>Der Server kennt weder Geraet noch Standort. Er kann ausschliesslich an diesen Endpunkt zustellen, und nur solange das Abo gueltig ist.</li>
            <li>Meldet sich in diesem Browser ein anderes Konto an, wird das fremde Abo automatisch abgebaut, bevor ein neues entsteht.</li>
            <li>Abmelden loescht das Abo im Browser und markiert es serverseitig als widerrufen — es wird nichts mehr zugestellt.</li>
            @if ($pushDiagnostics['auto_provisioned'])
                <li>Die VAPID-Schluessel dieser Installation wurden gerade automatisch erzeugt und liegen unter <code>storage/app/private/webpush-vapid.json</code>.</li>
            @endif
        </ul>
    </section>
</div>
