<?php

namespace App\Livewire\Settings;

use App\Support\Push\PushCategory;
use App\Support\Push\WebPushConfiguration;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Einbettbares Panel fuer Installation, Push-Abo und Kategorien.
 *
 * Portiert aus RailTime (`App\Livewire\Settings\PushSettings`). Es rendert
 * bewusst nur die Huelle: der gesamte Zustand — Berechtigung, Abo, Server-
 * status — lebt im Browser und wird von `resources/js/pwa.js` ueber die
 * Alpine-Komponente `factoryPushSettings` gepflegt. Livewire wuerde diesen
 * Zustand nur veraltet spiegeln.
 */
class PushSettings extends Component
{
    /**
     * Testversand anzeigen. Auf der eigenen Seite an, in eingebetteten
     * Kurzfassungen (z. B. einem spaeteren Einstellungs-Tab) aus.
     */
    public bool $showTest = false;

    public bool $showInstall = true;

    public bool $showPreferences = true;

    public function render(): View
    {
        $diagnostics = WebPushConfiguration::diagnostics();

        return view('livewire.settings.push-settings', [
            'pushDiagnostics' => $diagnostics,
            'categories' => PushCategory::cases(),
            'clientConfig' => [
                'serverEnabled' => $diagnostics['enabled'],
                'serverConfigured' => $diagnostics['configured'],
                'testEnabled' => (bool) config('webpush.test_enabled'),
                'vapidPublicKey' => (string) config('webpush.vapid.public_key'),
                'accountBinding' => WebPushConfiguration::accountBinding(auth()->id()),
                'urls' => [
                    'status' => route('push.status'),
                    'subscribe' => route('push.subscriptions.store'),
                    'unsubscribe' => route('push.subscriptions.destroy'),
                    'preferences' => route('push.preferences.update'),
                    'test' => route('push.test'),
                ],
                'preferences' => collect(PushCategory::cases())
                    ->mapWithKeys(fn (PushCategory $category): array => [
                        $category->value => ['enabled' => false],
                    ])
                    ->all(),
                'messages' => [
                    'loadFailed' => 'Der Push-Status konnte nicht geladen werden.',
                    'syncFailed' => 'Das vorhandene Abo konnte nicht mit dem Server abgeglichen werden.',
                    'statusLoading' => 'Status wird geprueft',
                    'statusLoadingDescription' => 'Berechtigung, Service Worker und Serverzustand werden abgefragt.',
                    'statusInsecure' => 'Keine sichere Verbindung',
                    'statusInsecureDescription' => 'Push-Benachrichtigungen brauchen HTTPS. Ueber http:// stellt kein Browser sie bereit — Ausnahme ist nur localhost.',
                    'statusIosInstall' => 'Erst zum Home-Bildschirm hinzufuegen',
                    'statusIosInstallDescription' => 'iPhone und iPad erlauben Push ausschliesslich in einer installierten App. Fuege die Seite ueber Teilen und "Zum Home-Bildschirm" hinzu und oeffne sie von dort.',
                    'statusUnsupported' => 'Dieser Browser kann keine Push-Benachrichtigungen',
                    'statusUnsupportedDescription' => 'Es fehlen Service Worker, Push-Manager oder Benachrichtigungen. Aktuelle Versionen von Chrome, Edge, Firefox oder Safari beherrschen alles drei.',
                    'statusUnavailable' => 'Push ist serverseitig nicht bereit',
                    'statusUnavailableDescription' => 'Die Anwendung hat noch keine gueltigen VAPID-Schluessel oder Push ist abgeschaltet. Details stehen unten im Diagnoseblock.',
                    'statusBlocked' => 'Benachrichtigungen sind blockiert',
                    'statusBlockedDescription' => 'Der Browser hat Benachrichtigungen fuer diese Seite gesperrt. Das laesst sich nur in den Seiteneinstellungen des Browsers zuruecknehmen, nicht von hier aus.',
                    'statusActive' => 'Dieses Geraet erhaelt Benachrichtigungen',
                    'statusActiveDescription' => 'Das Abo ist auf dem Server hinterlegt. Benachrichtigungen kommen auch an, wenn kein Tab offen ist.',
                    'statusReady' => 'Bereit zum Aktivieren',
                    'statusReadyDescription' => 'Alles Noetige ist vorhanden. Beim Aktivieren fragt der Browser einmalig nach der Berechtigung.',
                    'installAccepted' => 'Die App wird installiert.',
                    'installFailed' => 'Die Installation konnte nicht gestartet werden.',
                    'subscribeUnavailable' => 'Push kann auf diesem Geraet gerade nicht aktiviert werden.',
                    'permissionDenied' => 'Die Berechtigung wurde abgelehnt. Erlaube Benachrichtigungen in den Seiteneinstellungen des Browsers.',
                    'permissionDismissed' => 'Die Abfrage wurde geschlossen, ohne zu entscheiden. Versuche es erneut.',
                    'subscribed' => 'Dieses Geraet erhaelt ab jetzt Benachrichtigungen.',
                    'subscribeFailed' => 'Das Geraet konnte nicht angemeldet werden.',
                    'unsubscribed' => 'Dieses Geraet erhaelt keine Benachrichtigungen mehr.',
                    'unsubscribeFailed' => 'Das Geraet konnte nicht abgemeldet werden.',
                    'preferencesSaved' => 'Auswahl gespeichert.',
                    'preferencesFailed' => 'Die Auswahl konnte nicht gespeichert werden.',
                    'testQueued' => 'Testbenachrichtigung wurde verschickt. Sie kommt binnen weniger Sekunden an.',
                    'testFailed' => 'Die Testbenachrichtigung konnte nicht verschickt werden.',
                ],
            ],
        ]);
    }
}
