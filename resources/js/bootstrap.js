/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Echtzeit-Transport (Spur X): Laravel Echo ueber Laravel Reverb.
 *
 * Reverb spricht das Pusher-Protokoll, deshalb bleibt `pusher-js` der
 * Unterbau. Es gibt bewusst **keine** eigene Subdomain und keinen nach aussen
 * offenen Port: der Browser verbindet sich auf 443 gegen die normale Domain,
 * Apache leitet `/app` und `/apps` intern auf den Loopback-Port weiter
 * (Produktion 127.0.0.1:8081; lokal spricht der Browser denselben Port direkt).
 *
 * Fehlt die Konfiguration — etwa in einer Umgebung ohne laufenden
 * Reverb-Server — wird bewusst **kein** Echo angelegt. `window.Echo` bleibt
 * dann `undefined`, und aufrufender Code muss darauf pruefen. Das ist
 * Absicht: ein Echo, das ins Leere verbindet, erzeugt nur endlose
 * Reconnect-Versuche in der Konsole.
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;

if (reverbKey) {
    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
    const forceTLS = scheme === 'https';

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? (forceTLS ? 443 : 80)),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS,
        enabledTransports: forceTLS ? ['ws', 'wss'] : ['ws'],
    });
}
