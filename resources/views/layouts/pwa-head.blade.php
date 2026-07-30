{{--
    PWA- und Push-Kopfdaten (Spur W, portiert aus RailTime).

    Bewusst ein eigenes Partial und **kein** Eintrag in `layouts/metahead.php`:
    metahead traegt die Endung `.php` und wird deshalb von der PHP- statt der
    Blade-Engine gerendert; Blade-Direktiven wuerden dort nicht ausgewertet.
    Ausserdem gehoert metahead zum App-Shell-Anspruch von Codex.

    Einbindung: `@include('layouts.pwa-head')` im `<head>` von
    `layouts/master.blade.php` und `layouts/master-without-nav.blade.php` —
    **nach** `layouts.metahead`, weil bei zwei `<link rel="manifest">` das
    erste gewinnt und das Alt-Manifest kein `display: standalone` deklariert.

    Der Block ist absichtlich frei von Datenbank-Zugriffen ausser dem
    Auth-Block, damit auch Fehlerseiten ihn einbinden koennen.
--}}

<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ route('pwa.icon', ['icon' => 'pwa-192.png']) }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ route('pwa.icon', ['icon' => 'apple-touch-icon-180.png']) }}">

<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Factory AI') }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">

<meta name="msapplication-TileColor" content="#081b2d">
<meta name="msapplication-TileImage" content="{{ route('pwa.icon', ['icon' => 'pwa-192.png']) }}">

<meta name="theme-color" content="#2f5c9e" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#081b2d" media="(prefers-color-scheme: dark)">

{{-- Registrierungsquelle des Service Workers. resources/js/pwa.js liest sie und
     verweigert die Registrierung, wenn sie auf einen fremden Origin zeigt. --}}
<meta name="ff-service-worker-url" content="{{ asset('service-worker.js') }}">

@auth
    {{-- Nicht umkehrbare Kontokennung. Wechselt das Konto im selben Browser,
         baut der Client das fremde Push-Abo ab, bevor er ein eigenes anlegt.
         Es verlaesst dabei keine Benutzer-ID den Server. --}}
    <meta
        name="ff-push-account-binding"
        content="{{ \App\Support\Push\WebPushConfiguration::accountBinding(auth()->id()) }}"
    >
@endauth
