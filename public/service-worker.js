/*
 * Service Worker fuer Web-Push, portiert aus RailTime
 * (RailTime/App/public/service-worker.js, read-only Referenz).
 *
 * Bewusst ohne Caching-Strategie: die Anwendung ist vollstaendig
 * serverseitig gerendert, ein Offline-Cache wuerde nur veraltete
 * Livewire-Antworten ausliefern. Zweck ist ausschliesslich Push,
 * Klick-Routing und das App-Badge.
 *
 * Abweichungen zu RailTime: Praefix railtime: -> factory:, die
 * RailTime-Kategorie 'calls' ist durch 'copilot' ersetzt, und der
 * Sonderfall 'offener Chat unterdrueckt den Push' entfaellt, weil
 * FollowFlow keine Chat-Ansicht je Push kennt.
 */

'use strict';

const FALLBACK_TITLE = 'Factory AI';
const FALLBACK_ICON = 'pwa-icons/pwa-192.png';
const FALLBACK_BADGE = 'pwa-icons/push-badge-96.png';
const FOREGROUND_ACK_TYPE = 'factory:push-received-ack';
const FOREGROUND_CONTEXT_ACK_TYPE = 'factory:push-context-ack';
const FOREGROUND_ACK_TIMEOUT_MS = 750;

function registrationScope() {
    return new URL(self.registration.scope);
}

function isInsideRegistrationScope(url) {
    const scope = registrationScope();

    return url.origin === scope.origin && url.pathname.startsWith(scope.pathname);
}

function scopedUrl(value, fallback = './') {
    const scope = registrationScope();

    try {
        const candidate = new URL(typeof value === 'string' && value.trim() ? value : fallback, scope);

        return isInsideRegistrationScope(candidate) ? candidate : new URL(fallback, scope);
    } catch (_) {
        return new URL(fallback, scope);
    }
}

function readPushPayload(event) {
    if (!event.data) {
        return {};
    }

    try {
        const payload = event.data.json();

        return payload && typeof payload === 'object' ? payload : {};
    } catch (_) {
        try {
            return { body: event.data.text() };
        } catch (_) {
            return {};
        }
    }
}

function textValue(value, fallback = '') {
    return typeof value === 'string' && value.trim() ? value.trim() : fallback;
}

async function updateAppBadge(value) {
    const count = Math.max(0, Math.min(999, Number(value) || 0));

    try {
        if (count > 0 && typeof self.navigator?.setAppBadge === 'function') {
            await self.navigator.setAppBadge(count);
        } else if (count === 0 && typeof self.navigator?.clearAppBadge === 'function') {
            await self.navigator.clearAppBadge();
        }
    } catch (_) {
        // Badge-Unterstützung ist eine progressive Erweiterung.
    }
}

function requestForegroundAck(client, message) {
    if (typeof MessageChannel === 'undefined') {
        return Promise.resolve(false);
    }

    return new Promise((resolve) => {
        const channel = new MessageChannel();
        let settled = false;
        let timeoutId = null;

        const finish = (acknowledged) => {
            if (settled) {
                return;
            }

            settled = true;
            clearTimeout(timeoutId);
            channel.port1.onmessage = null;
            channel.port1.onmessageerror = null;
            channel.port1.close();
            resolve(acknowledged);
        };

        channel.port1.onmessage = (event) => {
            const acknowledgement = event.data;

            finish(
                acknowledgement?.type === FOREGROUND_ACK_TYPE
                && acknowledgement.notification_id === message.notification_id
            );
        };
        channel.port1.onmessageerror = () => finish(false);
        channel.port1.start();

        timeoutId = setTimeout(() => finish(false), FOREGROUND_ACK_TIMEOUT_MS);

        try {
            client.postMessage(message, [channel.port2]);
        } catch (_) {
            channel.port2.close();
            finish(false);
        }
    });
}

function requestForegroundContext(client, message) {
    if (typeof MessageChannel === 'undefined') {
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        const channel = new MessageChannel();
        let settled = false;
        let timeoutId = null;

        const finish = (context) => {
            if (settled) {
                return;
            }

            settled = true;
            clearTimeout(timeoutId);
            channel.port1.onmessage = null;
            channel.port1.onmessageerror = null;
            channel.port1.close();
            resolve(context);
        };

        channel.port1.onmessage = (event) => {
            const acknowledgement = event.data;

            if (
                acknowledgement?.type !== FOREGROUND_CONTEXT_ACK_TYPE
                || acknowledgement.notification_id !== message.notification_id
            ) {
                finish(null);

                return;
            }

            finish({
                focused: Boolean(acknowledgement.focused),
            });
        };
        channel.port1.onmessageerror = () => finish(null);
        channel.port1.start();

        timeoutId = setTimeout(() => finish(null), FOREGROUND_ACK_TIMEOUT_MS);

        try {
            client.postMessage(message, [channel.port2]);
        } catch (_) {
            channel.port2.close();
            finish(null);
        }
    });
}

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    const payload = readPushPayload(event);
    const data = payload.data && typeof payload.data === 'object' ? payload.data : {};
    const target = scopedUrl(data.url ?? payload.url, './');
    const notificationId = textValue(data.notification_id, '');
    const title = textValue(payload.title, FALLBACK_TITLE);
    const category = textValue(data.category, '');
    const options = {
        body: textValue(payload.body, ''),
        icon: scopedUrl(payload.icon, FALLBACK_ICON).href,
        badge: scopedUrl(payload.badge, FALLBACK_BADGE).href,
        tag: textValue(payload.tag, notificationId || 'factory'),
        data: {
            ...data,
            url: target.href,
        },
    };

    if (category === 'copilot') {
        // Ein wartender Copilot-Checkpoint blockiert den Lauf: die
        // Benachrichtigung bleibt stehen, bis jemand reagiert.
        options.requireInteraction = true;
        options.renotify = true;
        options.tag = notificationId || 'factory-copilot';
        options.vibrate = [300, 150, 300];
    }

    if (Array.isArray(payload.actions)) {
        options.actions = payload.actions
            .filter((action) => action && typeof action.title === 'string' && typeof action.action === 'string')
            .slice(0, 2)
            .map((action) => ({
                title: action.title,
                action: action.action,
                ...(action.icon ? { icon: scopedUrl(action.icon, FALLBACK_ICON).href } : {}),
            }));
    }

    event.waitUntil((async () => {
        await updateAppBadge(data.badge_count);

        const windows = await self.clients.matchAll({
            type: 'window',
            includeUncontrolled: true,
        });
        const visibleClients = windows.filter((client) => {
            if (client.visibilityState !== 'visible') {
                return false;
            }

            try {
                return isInsideRegistrationScope(new URL(client.url));
            } catch (_) {
                return false;
            }
        });

        if (visibleClients.length > 0) {
            const foregroundContexts = (await Promise.all(
                visibleClients.map(async (client) => ({
                    client,
                    context: await requestForegroundContext(client, {
                        type: 'factory:push-context-request',
                        notification_id: notificationId || options.tag,
                        category,
                        title,
                        body: options.body,
                        url: target.href,
                    }),
                }))
            )).filter(({ context }) => context !== null);
            foregroundContexts.sort(
                (left, right) => Number(right.context.focused) - Number(left.context.focused)
            );

            for (const { client } of foregroundContexts) {
                const acknowledged = await requestForegroundAck(client, {
                    type: 'factory:push-received',
                    notification_id: notificationId || options.tag,
                    category,
                    title,
                    body: options.body,
                    url: target.href,
                });

                if (acknowledged) {
                    return;
                }
            }
        }

        await self.registration.showNotification(title, options);
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = scopedUrl(event.notification.data?.url, './');

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({
            type: 'window',
            includeUncontrolled: true,
        });

        for (const client of windows) {
            let clientUrl;

            try {
                clientUrl = new URL(client.url);
            } catch (_) {
                continue;
            }

            if (!isInsideRegistrationScope(clientUrl)) {
                continue;
            }

            try {
                await client.navigate(target.href);
                await client.focus();

                return;
            } catch (_) {
                // Ein inzwischen geschlossenes Fenster wird übersprungen.
            }
        }

        await self.clients.openWindow(target.href);
    })());
});
