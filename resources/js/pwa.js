/*
 * PWA-Installation und Web-Push, portiert aus RailTime
 * (RailTime/App/resources/js/pwa.js, read-only Referenz).
 *
 * Angepasst wurden ausschliesslich die Namensraeume: Ereignis- und
 * Storage-Praefix railtime: -> factory:, Meta-Praefix rt- -> ff- und die
 * exportierten Bezeichner Railtime* -> Factory*. Die Logik bleibt
 * unveraendert.
 */

const PWA_STATE_EVENT = 'factory:pwa-state-changed';
const PUSH_DEVICE_BINDING_STORAGE_KEY = 'factory:webpush-device-binding:v1';

let deferredInstallPrompt = null;
let serviceWorkerRegistrationPromise = null;
let lifecycleInitialized = false;
let installConfirmedInSession = false;
let badgeObserver = null;

function currentWindow() {
    return typeof window === 'undefined' ? null : window;
}

function currentNavigator() {
    return typeof navigator === 'undefined' ? null : navigator;
}

function currentStorage() {
    try {
        return currentWindow()?.localStorage || null;
    } catch (_) {
        return null;
    }
}

function normalizedPushDeviceBinding(value) {
    const account = typeof value?.account === 'string' ? value.account.trim() : '';
    const subscriptionId = Number(value?.subscriptionId);

    if (!account || !Number.isSafeInteger(subscriptionId) || subscriptionId < 1) {
        return null;
    }

    return { account, subscriptionId };
}

export function readPushDeviceBinding(storageLike = currentStorage()) {
    if (!storageLike) {
        return null;
    }

    try {
        return normalizedPushDeviceBinding(
            JSON.parse(storageLike.getItem(PUSH_DEVICE_BINDING_STORAGE_KEY) || 'null'),
        );
    } catch (_) {
        return null;
    }
}

export function pushDeviceBindingMatches(binding, accountBinding) {
    return Boolean(
        binding
        && typeof accountBinding === 'string'
        && accountBinding
        && binding.account === accountBinding,
    );
}

export function writePushDeviceBinding(
    accountBinding,
    subscriptionId,
    storageLike = currentStorage(),
) {
    const binding = normalizedPushDeviceBinding({
        account: accountBinding,
        subscriptionId,
    });

    if (!binding || !storageLike) {
        return null;
    }

    try {
        storageLike.setItem(PUSH_DEVICE_BINDING_STORAGE_KEY, JSON.stringify(binding));

        return binding;
    } catch (_) {
        return null;
    }
}

export function clearPushDeviceBinding(storageLike = currentStorage()) {
    if (!storageLike) {
        return;
    }

    try {
        storageLike.removeItem(PUSH_DEVICE_BINDING_STORAGE_KEY);
    } catch (_) {
        // A failed local cleanup must not break the server-side revocation request.
    }
}

export function isIosDevice(navigatorLike = currentNavigator()) {
    if (!navigatorLike) {
        return false;
    }

    const userAgent = String(navigatorLike.userAgent || '');
    const platform = String(navigatorLike.platform || '');

    return /iPad|iPhone|iPod/i.test(userAgent)
        || (platform === 'MacIntel' && Number(navigatorLike.maxTouchPoints || 0) > 1);
}

export function isAndroidDevice(navigatorLike = currentNavigator()) {
    return /Android/i.test(String(navigatorLike?.userAgent || ''));
}

/**
 * Desktop-Rechner (Windows, macOS, Linux, ChromeOS) — bewusst per Ausschluss
 * der Mobilplattformen, weil iPadOS sich als "Macintosh" meldet und deshalb
 * schon in isIosDevice() ueber maxTouchPoints erkannt wird.
 */
export function isDesktopPlatform(navigatorLike = currentNavigator()) {
    if (isIosDevice(navigatorLike) || isAndroidDevice(navigatorLike)) {
        return false;
    }

    return /Windows|Macintosh|Mac OS X|CrOS|Linux/i.test(String(navigatorLike?.userAgent || ''));
}

/**
 * Feinunterscheidung fuer die Installationsanleitung: 'windows' | 'mac' | 'other'
 * (null, wenn es kein Desktop ist).
 */
export function desktopFamily(navigatorLike = currentNavigator()) {
    if (!isDesktopPlatform(navigatorLike)) {
        return null;
    }

    const userAgent = String(navigatorLike?.userAgent || '');

    if (/Windows/i.test(userAgent)) {
        return 'windows';
    }

    if (/Macintosh|Mac OS X/i.test(userAgent)) {
        return 'mac';
    }

    return 'other';
}

/**
 * Safari auf dem Mac kennt kein beforeinstallprompt. Dort fuehrt nur
 * "Ablage > Zum Dock hinzufuegen" (Safari 17+) zur Installation, weshalb dieser
 * Fall eine eigene Anleitung braucht — analog zu iOS.
 */
export function isMacSafari(navigatorLike = currentNavigator()) {
    if (desktopFamily(navigatorLike) !== 'mac') {
        return false;
    }

    const userAgent = String(navigatorLike?.userAgent || '');

    return /Safari/i.test(userAgent) && !/Chrome|Chromium|Edg\//i.test(userAgent);
}

export function isStandaloneMode(
    windowLike = currentWindow(),
    navigatorLike = currentNavigator(),
) {
    return Boolean(
        navigatorLike?.standalone
        || windowLike?.matchMedia?.('(display-mode: standalone)').matches,
    );
}

export function runtimeCapabilities(
    windowLike = currentWindow(),
    navigatorLike = currentNavigator(),
) {
    const secureContext = Boolean(windowLike?.isSecureContext);
    const serviceWorker = Boolean(navigatorLike && 'serviceWorker' in navigatorLike);
    const pushManager = Boolean(windowLike?.PushManager);
    const notifications = Boolean(windowLike?.Notification);

    return {
        secureContext,
        serviceWorker,
        pushManager,
        notifications,
        supported: secureContext && serviceWorker && pushManager && notifications,
        permission: notifications ? windowLike.Notification.permission : 'unsupported',
        ios: isIosDevice(navigatorLike),
        android: isAndroidDevice(navigatorLike),
        standalone: isStandaloneMode(windowLike, navigatorLike),
    };
}

export function urlBase64ToUint8Array(base64String, decode = globalThis.atob) {
    const normalized = String(base64String || '').trim();

    if (!normalized) {
        throw new Error('The VAPID public key is missing.');
    }

    const padding = '='.repeat((4 - (normalized.length % 4)) % 4);
    const base64 = (normalized + padding).replace(/-/g, '+').replace(/_/g, '/');
    const decoded = decode(base64);

    return Uint8Array.from(decoded, (character) => character.charCodeAt(0));
}

export function serviceWorkerScope(scriptUrl, baseUrl = currentWindow()?.location?.href) {
    return new URL('./', new URL(scriptUrl, baseUrl)).href;
}

function dispatchPwaState() {
    currentWindow()?.dispatchEvent(new CustomEvent(PWA_STATE_EVENT));
}

export async function updateFactoryAppBadge(
    count,
    navigatorLike = currentNavigator(),
) {
    const normalized = Math.max(0, Math.min(999, Number(count) || 0));

    try {
        if (normalized > 0 && typeof navigatorLike?.setAppBadge === 'function') {
            await navigatorLike.setAppBadge(normalized);
            return true;
        }

        if (normalized === 0 && typeof navigatorLike?.clearAppBadge === 'function') {
            await navigatorLike.clearAppBadge();
            return true;
        }
    } catch (_) {
        // Der Nutzer oder das Betriebssystem darf App-Badges deaktivieren.
    }

    return false;
}

function syncBadgeFromDocument() {
    const source = document.querySelector('[data-app-badge-count]');

    if (source) {
        updateFactoryAppBadge(source.dataset.appBadgeCount);
    }
}

function observeAppBadge() {
    badgeObserver?.disconnect();
    const source = document.querySelector('[data-app-badge-count]');

    if (!source) {
        return;
    }

    syncBadgeFromDocument();
    badgeObserver = new MutationObserver(syncBadgeFromDocument);
    badgeObserver.observe(source, {
        attributes: true,
        attributeFilter: ['data-app-badge-count'],
    });
}

function installState() {
    const windowLike = currentWindow();
    const navigatorLike = currentNavigator();

    return {
        // Nur der aktuelle Display-Modus und das echte Browser-Ereignis sind
        // belastbar. Ein dauerhafter Storage-Marker wuerde nach einer
        // Deinstallation veralten und den Installationsknopf blockieren.
        installed: isStandaloneMode(windowLike, navigatorLike) || installConfirmedInSession,
        promptAvailable: Boolean(deferredInstallPrompt),
        ios: isIosDevice(navigatorLike),
        android: isAndroidDevice(navigatorLike),
        desktop: isDesktopPlatform(navigatorLike),
        desktopFamily: desktopFamily(navigatorLike),
        macSafari: isMacSafari(navigatorLike),
    };
}

export function installationMode(state = {}) {
    if (state.installed) {
        return 'installed';
    }

    if (state.promptAvailable) {
        return 'prompt';
    }

    if (state.ios || state.android || state.desktop) {
        return 'manual';
    }

    return 'unsupported';
}

function configuredServiceWorkerUrl() {
    const windowLike = currentWindow();

    if (!windowLike) {
        return null;
    }

    const configured = document.querySelector('meta[name="ff-service-worker-url"]')?.content;
    const manifest = document.querySelector('link[rel="manifest"]')?.href;

    if (configured) {
        return new URL(configured, windowLike.location.href);
    }

    if (manifest) {
        return new URL('service-worker.js', manifest);
    }

    return new URL('service-worker.js', windowLike.location.origin + '/');
}

export async function registerFactoryServiceWorker() {
    const windowLike = currentWindow();
    const navigatorLike = currentNavigator();
    const capabilities = runtimeCapabilities(windowLike, navigatorLike);

    if (!capabilities.secureContext || !capabilities.serviceWorker) {
        return null;
    }

    if (!serviceWorkerRegistrationPromise) {
        serviceWorkerRegistrationPromise = (async () => {
            const scriptUrl = configuredServiceWorkerUrl();

            if (!scriptUrl || scriptUrl.origin !== windowLike.location.origin) {
                throw new Error('The service worker must be served from the app origin.');
            }

            return navigatorLike.serviceWorker.register(scriptUrl.href, {
                scope: serviceWorkerScope(scriptUrl.href),
                updateViaCache: 'none',
            });
        })().catch((error) => {
            serviceWorkerRegistrationPromise = null;
            throw error;
        });
    }

    return serviceWorkerRegistrationPromise;
}

async function readyServiceWorkerRegistration() {
    const registration = await registerFactoryServiceWorker();

    if (registration?.active) {
        return registration;
    }

    return currentNavigator().serviceWorker.ready;
}

export async function reconcilePushDeviceAccount({
    accountBinding,
    registration,
    deviceBinding = readPushDeviceBinding(),
    clearBinding = clearPushDeviceBinding,
}) {
    if (
        !accountBinding
        || !registration?.pushManager
        || pushDeviceBindingMatches(deviceBinding, accountBinding)
    ) {
        return false;
    }

    const subscription = await registration.pushManager.getSubscription();

    if (!subscription) {
        clearBinding();

        return false;
    }

    const removed = await subscription.unsubscribe();

    if (removed) {
        clearBinding();
    }

    return removed;
}

export function setupFactoryPwa() {
    const windowLike = currentWindow();

    if (!windowLike || lifecycleInitialized) {
        return;
    }

    lifecycleInitialized = true;

    windowLike.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredInstallPrompt = event;
        installConfirmedInSession = false;

        dispatchPwaState();
    });

    windowLike.addEventListener('appinstalled', () => {
        deferredInstallPrompt = null;
        installConfirmedInSession = true;

        dispatchPwaState();
    });

    const register = () => {
        registerFactoryServiceWorker()
            .then(async (registration) => {
                const accountBinding = document.querySelector(
                    'meta[name="ff-push-account-binding"]',
                )?.content;

                if (accountBinding) {
                    await reconcilePushDeviceAccount({
                        accountBinding,
                        registration,
                    });
                }

                dispatchPwaState();
            })
            .catch(() => dispatchPwaState());
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            register();
            observeAppBadge();
        }, { once: true });
    } else {
        register();
        observeAppBadge();
    }

    document.addEventListener('livewire:navigated', observeAppBadge);
}

export async function promptFactoryInstall() {
    if (!deferredInstallPrompt) {
        return { outcome: 'unavailable' };
    }

    const prompt = deferredInstallPrompt;
    deferredInstallPrompt = null;

    try {
        await prompt.prompt();
        const choice = await prompt.userChoice;
        dispatchPwaState();

        return choice;
    } catch (error) {
        dispatchPwaState();
        throw error;
    }
}

export function registerFactoryPwaInstall(Alpine) {
    Alpine.data('factoryPwaInstall', (config = {}) => ({
        install: installState(),
        busy: false,
        notice: '',
        error: '',
        pwaStateListener: null,

        init() {
            this.pwaStateListener = () => {
                this.install = installState();
            };
            window.addEventListener(PWA_STATE_EVENT, this.pwaStateListener);
        },

        destroy() {
            if (this.pwaStateListener) {
                window.removeEventListener(PWA_STATE_EVENT, this.pwaStateListener);
            }
        },

        get mode() {
            return installationMode(this.install);
        },

        get disabled() {
            return this.busy || this.mode === 'installed';
        },

        statusText() {
            if (this.mode === 'installed') {
                return config.messages?.installed || '';
            }

            if (this.mode === 'prompt') {
                return config.messages?.ready || '';
            }

            return config.messages?.manual || '';
        },

        guideTarget() {
            if (this.install.ios) {
                return config.targets?.ios;
            }

            if (this.install.android) {
                return config.targets?.android;
            }

            return config.targets?.desktop || config.targets?.fallback;
        },

        openGuide() {
            const target = this.guideTarget();

            if (target) {
                document.querySelector(target)?.scrollIntoView({
                    behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
                        ? 'auto'
                        : 'smooth',
                    block: 'start',
                });
            }

            this.notice = config.messages?.manual || '';
        },

        async installApp() {
            this.notice = '';
            this.error = '';

            if (this.mode === 'installed') {
                return;
            }

            if (this.mode !== 'prompt') {
                this.openGuide();

                return;
            }

            this.busy = true;

            try {
                const choice = await promptFactoryInstall();
                this.install = installState();

                if (choice?.outcome === 'accepted') {
                    this.notice = config.messages?.accepted || '';
                } else {
                    this.openGuide();
                }
            } catch (error) {
                this.error = messageFrom(error, config.messages?.failed || '');
            } finally {
                this.busy = false;
            }
        },
    }));
}

function browserName(navigatorLike = currentNavigator()) {
    const brands = navigatorLike?.userAgentData?.brands;

    if (Array.isArray(brands)) {
        const brand = brands.find(({ brand: name }) => !/Not.A.Brand/i.test(name));

        if (brand?.brand) {
            return String(brand.brand).slice(0, 60);
        }
    }

    const userAgent = String(navigatorLike?.userAgent || '');

    if (/EdgiOS|EdgA|Edg\//i.test(userAgent)) return 'Microsoft Edge';
    if (/CriOS|Chrome\//i.test(userAgent)) return 'Google Chrome';
    if (/FxiOS|Firefox\//i.test(userAgent)) return 'Mozilla Firefox';
    if (/Safari\//i.test(userAgent)) return 'Safari';

    return 'Browser';
}

function platformName(capabilities) {
    if (capabilities.ios) return 'ios';
    if (capabilities.android) return 'android';
    if (capabilities.supported) return 'desktop';

    return 'unknown';
}

function deviceName(capabilities) {
    if (capabilities.ios) return 'iPhone / iPad';
    if (capabilities.android) return 'Android-Gerät';

    return 'Desktop';
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function apiRequest(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.headers || {}),
        },
    });

    let payload = null;

    if (response.status !== 204) {
        try {
            payload = await response.json();
        } catch (_) {
            payload = null;
        }
    }

    if (!response.ok) {
        const error = new Error(payload?.message || `HTTP ${response.status}`);
        error.status = response.status;
        throw error;
    }

    return payload;
}

function serializedSubscription(subscription, capabilities) {
    const json = subscription.toJSON();
    const encodings = currentWindow().PushManager?.supportedContentEncodings || [];

    if (!json.endpoint || !json.keys?.p256dh || !json.keys?.auth) {
        throw new Error('The browser returned an incomplete push subscription.');
    }

    return {
        endpoint: json.endpoint,
        keys: {
            p256dh: json.keys.p256dh,
            auth: json.keys.auth,
        },
        contentEncoding: encodings.includes('aes128gcm') ? 'aes128gcm' : (encodings[0] || 'aes128gcm'),
        platform: platformName(capabilities),
        browser: browserName(),
        deviceName: deviceName(capabilities),
    };
}

export async function syncExistingPushSubscription({
    subscription,
    capabilities,
    serverEnabled,
    serverConfigured,
    accountBinding,
    deviceBinding,
    subscribeUrl,
    request,
    serialize = serializedSubscription,
}) {
    if (
        !subscription
        || capabilities.permission !== 'granted'
        || !serverEnabled
        || !serverConfigured
        || !pushDeviceBindingMatches(deviceBinding, accountBinding)
    ) {
        return null;
    }

    return request(subscribeUrl, {
        method: 'POST',
        body: JSON.stringify(serialize(subscription, capabilities)),
    });
}

/**
 * Dieses Geraet fuer Web-Push anmelden, sofern alles dafuer bereitsteht.
 *
 * Wird vom Einrichtungsdialog nach erteilter Benachrichtigungs-Berechtigung
 * aufgerufen: Die Berechtigung allein zeigt noch nichts an – eingehende
 * Anrufe laufen ueber den Service Worker, und der braucht ein beim Server
 * registriertes Abo. Ohne diesen Schritt waere "Erteilt" ein leeres
 * Versprechen, sobald kein Tab mehr offen ist.
 *
 * Kehrt still mit null zurueck, wo Push prinzipiell nicht geht (Browser ohne
 * PushManager, iOS ohne Installation als App, fehlende Konfiguration): Der
 * Dialog soll damit nicht belaestigen – die Einstellungsseite erklaert die
 * Details. Echte Fehler (Netz, Server) werden geworfen.
 */
export async function ensureCurrentDevicePushSubscription({
    vapidPublicKey,
    accountBinding,
    subscribeUrl,
    unsubscribeUrl,
    capabilities = runtimeCapabilities(),
    registrationProvider = readyServiceWorkerRegistration,
    request = apiRequest,
    serialize = serializedSubscription,
    reconcile = reconcilePushDeviceAccount,
    readBinding = readPushDeviceBinding,
    writeBinding = writePushDeviceBinding,
} = {}) {
    if (!vapidPublicKey || !accountBinding || !subscribeUrl) {
        return null;
    }

    if (!capabilities.supported || capabilities.permission !== 'granted') {
        return null;
    }

    if (capabilities.ios && !capabilities.standalone) {
        return null;
    }

    const registration = await registrationProvider();

    // Ein Abo eines anderen Kontos in diesem Browser zuerst aufloesen –
    // dieselbe Regel wie beim Laden der Einstellungsseite.
    await reconcile({ accountBinding, registration });

    let subscription = await registration.pushManager.getSubscription();
    const alreadyBound = Boolean(subscription)
        && pushDeviceBindingMatches(readBinding(), accountBinding);
    let createdLocally = false;

    if (!subscription) {
        subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
        });
        createdLocally = true;
    }

    try {
        const result = await request(subscribeUrl, {
            method: 'POST',
            body: JSON.stringify(serialize(subscription, capabilities)),
        });
        const binding = writeBinding(accountBinding, result?.subscription_id);

        if (!binding) {
            // Ohne lokale Bindung wuerde refresh() das Abo als fremd einstufen
            // und wieder abbauen – dann lieber sauber zuruecksetzen.
            await subscription.unsubscribe().catch(() => false);

            if (unsubscribeUrl && result?.subscription_id) {
                await request(unsubscribeUrl, {
                    method: 'DELETE',
                    body: JSON.stringify({ subscription_id: result.subscription_id }),
                }).catch(() => null);
            }

            throw new Error('The push device binding could not be stored.');
        }

        return { subscription, binding, alreadyBound };
    } catch (error) {
        if (createdLocally) {
            await subscription.unsubscribe().catch(() => false);
        }

        throw error;
    }
}

function messageFrom(error, fallback) {
    return typeof error?.message === 'string' && error.message && !/^HTTP \d+$/.test(error.message)
        ? error.message
        : fallback;
}

export function registerFactoryPushSettings(Alpine) {
    Alpine.data('factoryPushSettings', (config = {}) => ({
        config,
        initialized: false,
        busy: null,
        error: '',
        success: '',
        capabilities: runtimeCapabilities(),
        install: installState(),
        subscription: null,
        deviceBinding: readPushDeviceBinding(),
        server: {
            enabled: Boolean(config.serverEnabled),
            configured: Boolean(config.serverConfigured),
            subscription_count: 0,
            preferences: {},
        },
        preferences: Object.fromEntries(
            Object.entries(config.preferences || {}).map(([category, item]) => [
                category,
                Boolean(item?.enabled),
            ]),
        ),
        pwaStateListener: null,

        async init() {
            this.pwaStateListener = () => {
                this.capabilities = runtimeCapabilities();
                this.install = installState();
            };
            window.addEventListener(PWA_STATE_EVENT, this.pwaStateListener);

            await this.refresh();
        },

        destroy() {
            if (this.pwaStateListener) {
                window.removeEventListener(PWA_STATE_EVENT, this.pwaStateListener);
            }
        },

        text(key, fallback = '') {
            return this.config.messages?.[key] || fallback;
        },

        clearNotices() {
            this.error = '';
            this.success = '';
        },

        async refresh({ syncExisting = true } = {}) {
            this.capabilities = runtimeCapabilities();
            this.install = installState();

            try {
                if (this.capabilities.supported) {
                    const registration = await readyServiceWorkerRegistration();
                    const browserSubscription = await registration.pushManager.getSubscription();
                    const storedBinding = readPushDeviceBinding();
                    const belongsToCurrentAccount = pushDeviceBindingMatches(
                        storedBinding,
                        this.config.accountBinding,
                    );
                    this.deviceBinding = storedBinding;

                    if (browserSubscription && belongsToCurrentAccount) {
                        this.subscription = browserSubscription;

                        if (syncExisting) {
                            try {
                                const result = await syncExistingPushSubscription({
                                    subscription: browserSubscription,
                                    capabilities: this.capabilities,
                                    serverEnabled: this.config.serverEnabled,
                                    serverConfigured: this.config.serverConfigured,
                                    accountBinding: this.config.accountBinding,
                                    deviceBinding: storedBinding,
                                    subscribeUrl: this.config.urls.subscribe,
                                    request: apiRequest,
                                });

                                if (result?.subscription_id) {
                                    this.deviceBinding = writePushDeviceBinding(
                                        this.config.accountBinding,
                                        result.subscription_id,
                                    ) || storedBinding;
                                }
                            } catch (error) {
                                const syncError = new Error(this.text('syncFailed'));
                                syncError.status = error.status;
                                throw syncError;
                            }
                        }
                    } else {
                        this.subscription = null;

                        if (browserSubscription) {
                            const removed = await browserSubscription.unsubscribe().catch(() => false);

                            if (removed) {
                                clearPushDeviceBinding();
                                this.deviceBinding = null;
                            }
                        } else if (storedBinding) {
                            if (belongsToCurrentAccount) {
                                try {
                                    await apiRequest(this.config.urls.unsubscribe, {
                                        method: 'DELETE',
                                        body: JSON.stringify({
                                            subscription_id: storedBinding.subscriptionId,
                                        }),
                                    });
                                } catch (error) {
                                    const syncError = new Error(this.text('syncFailed'));
                                    syncError.status = error.status;
                                    throw syncError;
                                }
                            }

                            clearPushDeviceBinding();
                            this.deviceBinding = null;
                        }
                    }
                } else {
                    this.subscription = null;
                    this.deviceBinding = readPushDeviceBinding();
                }

                const status = await apiRequest(this.config.urls.status);
                this.server = status;
                this.preferences = {
                    ...this.preferences,
                    ...(status.preferences || {}),
                };
            } catch (error) {
                this.error = messageFrom(error, this.text('loadFailed'));
            } finally {
                this.initialized = true;
            }
        },

        get currentDeviceSubscribed() {
            return Boolean(
                this.subscription
                && pushDeviceBindingMatches(this.deviceBinding, this.config.accountBinding),
            );
        },

        get hasSubscribedDevice() {
            return this.currentDeviceSubscribed || Number(this.server.subscription_count || 0) > 0;
        },

        get canPromptInstall() {
            return !this.install.installed && this.install.promptAvailable;
        },

        /**
         * Safari auf dem Mac bietet keinen Installationsdialog an — dort ist die
         * Anleitung der einzige Weg. Auf Windows/Chrome/Edge greift stattdessen
         * canPromptInstall.
         */
        get showDesktopInstallHelp() {
            return this.install.desktop
                && !this.install.installed
                && !this.install.promptAvailable;
        },

        get showIosInstallHelp() {
            return this.install.ios && !this.install.installed;
        },

        get canSubscribe() {
            return this.initialized
                && this.server.enabled
                && this.server.configured
                && this.capabilities.supported
                && (!this.capabilities.ios || this.capabilities.standalone)
                && this.capabilities.permission !== 'denied'
                && !this.currentDeviceSubscribed;
        },

        get canUnsubscribe() {
            return this.currentDeviceSubscribed;
        },

        get canTest() {
            return Boolean(config.testEnabled)
                && this.server.enabled
                && this.server.configured
                && this.currentDeviceSubscribed
                && Number.isSafeInteger(Number(this.deviceBinding?.subscriptionId));
        },

        get showPreferences() {
            return this.server.enabled && this.server.configured && this.hasSubscribedDevice;
        },

        statusTitle() {
            if (!this.initialized) return this.text('statusLoading');
            if (!this.capabilities.secureContext) return this.text('statusInsecure');
            if (this.capabilities.ios && !this.capabilities.standalone) return this.text('statusIosInstall');
            if (!this.capabilities.supported) return this.text('statusUnsupported');
            if (!this.server.enabled || !this.server.configured) return this.text('statusUnavailable');
            if (this.capabilities.permission === 'denied') return this.text('statusBlocked');
            if (this.currentDeviceSubscribed) return this.text('statusActive');

            return this.text('statusReady');
        },

        statusDescription() {
            if (!this.initialized) return this.text('statusLoadingDescription');
            if (!this.capabilities.secureContext) return this.text('statusInsecureDescription');
            if (this.capabilities.ios && !this.capabilities.standalone) {
                return this.text('statusIosInstallDescription');
            }
            if (!this.capabilities.supported) return this.text('statusUnsupportedDescription');
            if (!this.server.enabled || !this.server.configured) {
                return this.text('statusUnavailableDescription');
            }
            if (this.capabilities.permission === 'denied') {
                return this.text('statusBlockedDescription');
            }
            if (this.currentDeviceSubscribed) return this.text('statusActiveDescription');

            return this.text('statusReadyDescription');
        },

        async promptInstall() {
            this.clearNotices();
            this.busy = 'install';

            try {
                const choice = await promptFactoryInstall();

                if (choice?.outcome === 'accepted') {
                    this.success = this.text('installAccepted');
                }
            } catch (error) {
                this.error = messageFrom(error, this.text('installFailed'));
            } finally {
                this.install = installState();
                this.busy = null;
            }
        },

        async subscribe() {
            this.clearNotices();
            this.busy = 'subscribe';
            let createdLocally = false;
            let subscription = null;

            try {
                this.capabilities = runtimeCapabilities();

                if (!this.canSubscribe) {
                    throw new Error(this.text('subscribeUnavailable'));
                }

                let permission = this.capabilities.permission;

                if (permission !== 'granted') {
                    permission = await window.Notification.requestPermission();
                    this.capabilities = runtimeCapabilities();
                }

                if (permission !== 'granted') {
                    throw new Error(
                        permission === 'denied'
                            ? this.text('permissionDenied')
                            : this.text('permissionDismissed'),
                    );
                }

                const registration = await readyServiceWorkerRegistration();
                subscription = await registration.pushManager.getSubscription();

                if (!subscription) {
                    subscription = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(this.config.vapidPublicKey),
                    });
                    createdLocally = true;
                }

                const result = await apiRequest(this.config.urls.subscribe, {
                    method: 'POST',
                    body: JSON.stringify(serializedSubscription(subscription, this.capabilities)),
                });
                const binding = writePushDeviceBinding(
                    this.config.accountBinding,
                    result?.subscription_id,
                );

                if (!binding) {
                    await subscription.unsubscribe().catch(() => false);
                    await apiRequest(this.config.urls.unsubscribe, {
                        method: 'DELETE',
                        body: JSON.stringify({ subscription_id: result?.subscription_id }),
                    }).catch(() => null);
                    throw new Error(this.text('subscribeFailed'));
                }

                this.subscription = subscription;
                this.deviceBinding = binding;
                await this.refresh({ syncExisting: false });
                this.error = '';
                this.success = this.text('subscribed');
            } catch (error) {
                if (createdLocally && subscription) {
                    await subscription.unsubscribe().catch(() => false);
                }

                this.error = messageFrom(error, this.text('subscribeFailed'));
            } finally {
                this.busy = null;
            }
        },

        async unsubscribe() {
            this.clearNotices();
            this.busy = 'unsubscribe';

            try {
                const binding = readPushDeviceBinding();

                if (!pushDeviceBindingMatches(binding, this.config.accountBinding)) {
                    throw new Error(this.text('unsubscribeFailed'));
                }

                const registration = await readyServiceWorkerRegistration();
                const subscription = await registration.pushManager.getSubscription();

                if (subscription) {
                    const removed = await subscription.unsubscribe();

                    if (!removed) {
                        throw new Error(this.text('unsubscribeFailed'));
                    }
                }

                this.subscription = null;
                this.deviceBinding = null;
                clearPushDeviceBinding();

                await apiRequest(this.config.urls.unsubscribe, {
                    method: 'DELETE',
                    body: JSON.stringify({ subscription_id: binding.subscriptionId }),
                });
                await this.refresh();
                this.error = '';
                this.success = this.text('unsubscribed');
            } catch (error) {
                this.error = messageFrom(error, this.text('unsubscribeFailed'));
            } finally {
                this.busy = null;
            }
        },

        async savePreference(category, enabled) {
            const previous = Boolean(this.preferences[category]);
            this.preferences[category] = Boolean(enabled);
            this.clearNotices();
            this.busy = `preference:${category}`;

            try {
                await apiRequest(this.config.urls.preferences, {
                    method: 'PATCH',
                    body: JSON.stringify({ preferences: this.preferences }),
                });
                this.success = this.text('preferencesSaved');
            } catch (error) {
                this.preferences[category] = previous;
                this.error = messageFrom(error, this.text('preferencesFailed'));
            } finally {
                this.busy = null;
            }
        },

        async sendTest() {
            this.clearNotices();
            this.busy = 'test';

            try {
                if (!this.canTest) {
                    throw new Error(this.text('testFailed'));
                }

                await apiRequest(this.config.urls.test, {
                    method: 'POST',
                    body: JSON.stringify({
                        subscription_id: Number(this.deviceBinding.subscriptionId),
                    }),
                });
                this.success = this.text('testQueued');
            } catch (error) {
                this.error = messageFrom(error, this.text('testFailed'));
            } finally {
                this.busy = null;
            }
        },
    }));
}
