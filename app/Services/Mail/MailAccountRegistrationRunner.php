<?php

namespace App\Services\Mail;

use App\Jobs\CheckMailRegistrationWebmailJob;
use App\Jobs\SuperviseManagedProcessesJob;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MailAccountRegistrationRunner
{
    public const SETTINGS_TYPE = 'mail';
    public const SETTINGS_KEY = 'account_registration';
    public const PROVIDER_MODE_OBSERVED_MANUAL = 'observed_manual';
    public const PROVIDER_MODE_PROTON_USERNAME_CHECK = 'proton_username_check';
    public const MAIL_ACCOUNT_SCRIPT_VERSION = 2;
    public const BROWSER_LAUNCHER_SCRIPT_VERSION = 1;

    public function settings(): array
    {
        return $this->normalizeSettings(Setting::getValue(self::SETTINGS_TYPE, self::SETTINGS_KEY));
    }

    public function saveSettings(array $settings): array
    {
        $normalized = $this->normalizeSettings($settings);

        Setting::setValue(self::SETTINGS_TYPE, self::SETTINGS_KEY, $normalized);

        return $normalized;
    }

    public function defaultSettings(): array
    {
        return [
            'browser_engine' => 'cloak-with-chrome-fallback',
            'cloak_humanize_enabled' => false,
            'cloak_human_preset' => '',
            'headless_enabled' => false,
            'preview_modal_enabled' => true,
            'live_preview_enabled' => true,
            'live_preview_interval_seconds' => 3,
            'browser_activity_check_enabled' => true,
            'dom_debug_enabled' => true,
            'navigation_timeout_seconds' => 120,
            'observation_timeout_seconds' => 300,
            'verification_mailbox' => [
                'enabled' => false,
                'email' => '',
                'provider' => 'proton',
                'username' => '',
                'password_encrypted' => null,
                'webmail_url' => 'https://mail.proton.me',
            ],
            'providers' => [
                [
                    'key' => 'proton',
                    'label' => 'Proton',
                    'mode' => self::PROVIDER_MODE_PROTON_USERNAME_CHECK,
                    'enabled' => true,
                    'phone_required' => false,
                    'registration_url' => 'https://account.proton.me/mail/signup',
                    'completion_url_contains' => '',
                    'completion_selector' => '',
                    'webmail_url' => 'https://mail.proton.me',
                ],
                [
                    'key' => 'provider_2',
                    'label' => 'Provider 2',
                    'mode' => 'planned',
                    'enabled' => false,
                    'phone_required' => false,
                    'registration_url' => '',
                    'completion_url_contains' => '',
                    'completion_selector' => '',
                    'webmail_url' => '',
                ],
                [
                    'key' => 'provider_3',
                    'label' => 'Provider 3',
                    'mode' => 'planned',
                    'enabled' => false,
                    'phone_required' => false,
                    'registration_url' => '',
                    'completion_url_contains' => '',
                    'completion_selector' => '',
                    'webmail_url' => '',
                ],
            ],
        ];
    }

    public function normalizeSettings(mixed $settings): array
    {
        $settings = is_array($settings) ? $settings : [];
        $defaults = $this->defaultSettings();
        $browserEngine = trim((string) ($settings['browser_engine'] ?? $defaults['browser_engine']));

        if (! in_array($browserEngine, ['chrome', 'cloak', 'cloak-with-chrome-fallback'], true)) {
            $browserEngine = $defaults['browser_engine'];
        }

        $providers = $this->normalizeProviders($settings['providers'] ?? []);
        $verificationMailbox = $this->normalizeVerificationMailbox($settings['verification_mailbox'] ?? []);

        return [
            'browser_engine' => $browserEngine,
            'cloak_humanize_enabled' => (bool) ($settings['cloak_humanize_enabled'] ?? false),
            'cloak_human_preset' => trim((string) ($settings['cloak_human_preset'] ?? '')),
            'headless_enabled' => (bool) ($settings['headless_enabled'] ?? false),
            'preview_modal_enabled' => (bool) ($settings['preview_modal_enabled'] ?? $defaults['preview_modal_enabled']),
            'live_preview_enabled' => (bool) ($settings['live_preview_enabled'] ?? $defaults['live_preview_enabled']),
            'live_preview_interval_seconds' => max(1, min(60, (int) ($settings['live_preview_interval_seconds'] ?? $defaults['live_preview_interval_seconds']))),
            'browser_activity_check_enabled' => (bool) ($settings['browser_activity_check_enabled'] ?? $defaults['browser_activity_check_enabled']),
            'dom_debug_enabled' => (bool) ($settings['dom_debug_enabled'] ?? $defaults['dom_debug_enabled']),
            'navigation_timeout_seconds' => max(30, min(300, (int) ($settings['navigation_timeout_seconds'] ?? 120))),
            'observation_timeout_seconds' => max(30, min(1800, (int) ($settings['observation_timeout_seconds'] ?? 300))),
            'verification_mailbox' => $verificationMailbox,
            'providers' => $providers,
        ];
    }

    public function start(array $subject = [], ?string $providerKey = null): array
    {
        $settings = $this->settings();
        $provider = $this->resolveProvider($settings, $providerKey);

        if (! in_array(($provider['mode'] ?? ''), [self::PROVIDER_MODE_OBSERVED_MANUAL, self::PROVIDER_MODE_PROTON_USERNAME_CHECK], true)) {
            throw new \RuntimeException('Dieser Provider-Adapter ist noch nicht implementiert.');
        }

        if (! filter_var($provider['registration_url'] ?? '', FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('Bitte hinterlege unter Einstellungen > Mail-Registrierung eine gueltige Registrierungs-URL fuer den ersten Provider.');
        }

        $runId = (string) Str::uuid();
        $runDirectory = $this->runDirectory($runId);
        $publicRunDirectory = $this->publicRunDirectory($runId);

        File::ensureDirectoryExists($runDirectory);
        File::ensureDirectoryExists($publicRunDirectory);

        $statusPath = $runDirectory.DIRECTORY_SEPARATOR.'status.json';
        $resultPath = $runDirectory.DIRECTORY_SEPARATOR.'result.json';
        $configPath = $runDirectory.DIRECTORY_SEPARATOR.'runtime.json';
        $stdoutPath = $runDirectory.DIRECTORY_SEPARATOR.'stdout.log';
        $stderrPath = $runDirectory.DIRECTORY_SEPARATOR.'stderr.log';
        $livePreviewPath = $publicRunDirectory.DIRECTORY_SEPARATOR.'live.png';
        $webmailLivePreviewPath = $publicRunDirectory.DIRECTORY_SEPARATOR.'live-webmail.png';
        $normalizedSubject = $this->normalizeSubject($subject);
        $verificationMailbox = is_array($settings['verification_mailbox'] ?? null) ? $settings['verification_mailbox'] : [];

        if (
            ($verificationMailbox['enabled'] ?? false)
            && trim((string) ($normalizedSubject['recoveryEmail'] ?? '')) === ''
            && trim((string) ($verificationMailbox['email'] ?? '')) !== ''
        ) {
            $normalizedSubject['recoveryEmail'] = trim((string) $verificationMailbox['email']);
        }

        $runtimeConfig = [
            'runId' => $runId,
            'processIdentity' => $this->processIdentity($runId, 'main', $normalizedSubject['personId'] ?? null),
            'processHeartbeatIntervalSeconds' => max(5, (int) $settings['live_preview_interval_seconds']),
            'supervisor' => [
                'enabled' => true,
                'staleAfterSeconds' => max(30, (int) $settings['live_preview_interval_seconds'] * 5),
                'maxRestarts' => 2,
            ],
            'browserEngine' => $settings['browser_engine'],
            'cloakHumanizeEnabled' => (bool) $settings['cloak_humanize_enabled'],
            'cloakHumanPreset' => $settings['cloak_human_preset'],
            'headlessEnabled' => (bool) $settings['headless_enabled'],
            'previewModalEnabled' => (bool) $settings['preview_modal_enabled'],
            'navigationTimeoutMs' => $settings['navigation_timeout_seconds'] * 1000,
            'observationTimeoutMs' => $settings['observation_timeout_seconds'] * 1000,
            'browserProfilePath' => $runDirectory.DIRECTORY_SEPARATOR.'browser-profile',
            'livePreviewEnabled' => (bool) $settings['live_preview_enabled'],
            'livePreviewIntervalSeconds' => (int) $settings['live_preview_interval_seconds'],
            'livePreviewIntervalMs' => (int) $settings['live_preview_interval_seconds'] * 1000,
            'livePreviewPollIntervalSeconds' => (int) $settings['live_preview_interval_seconds'],
            'browserActivityCheckEnabled' => (bool) $settings['browser_activity_check_enabled'],
            'domDebugEnabled' => (bool) $settings['dom_debug_enabled'],
            'verificationMailbox' => $this->runtimeVerificationMailbox($settings['verification_mailbox'] ?? []),
            'livePreviewPath' => $livePreviewPath,
            'livePreviewRelativePath' => $this->publicScreenshotRelativePath($runId),
            'webmailLivePreviewPath' => $webmailLivePreviewPath,
            'webmailLivePreviewRelativePath' => $this->publicWebmailScreenshotRelativePath($runId),
            'statusPath' => $statusPath,
            'resultPath' => $resultPath,
            'scriptName' => 'mail_account.cjs',
            'scriptVersion' => self::MAIL_ACCOUNT_SCRIPT_VERSION,
            'scriptVersionLabel' => 'mail_account.cjs v'.self::MAIL_ACCOUNT_SCRIPT_VERSION,
            'scriptVersions' => [
                'mailAccount' => self::MAIL_ACCOUNT_SCRIPT_VERSION,
                'browserLauncher' => self::BROWSER_LAUNCHER_SCRIPT_VERSION,
            ],
            'provider' => [
                'key' => $provider['key'],
                'label' => $provider['label'],
                'mode' => $provider['mode'],
                'registrationUrl' => $provider['registration_url'],
                'completionUrlContains' => $provider['completion_url_contains'],
                'completionSelector' => $provider['completion_selector'],
                'webmailUrl' => $provider['webmail_url'],
                'phoneRequired' => (bool) $provider['phone_required'],
            ],
            'subject' => $normalizedSubject,
            'protonUsernameCheckTimeoutMs' => 30000,
        ];

        $this->writeJsonFile($statusPath, [
            'runId' => $runId,
            'processKey' => $this->processIdentity($runId, 'main', $normalizedSubject['personId'] ?? null)['processKey'],
            'processIdentity' => $this->processIdentity($runId, 'main', $normalizedSubject['personId'] ?? null),
            'providerKey' => $provider['key'],
            'providerLabel' => $provider['label'],
            'state' => 'queued',
            'stage' => 'queued',
            'message' => 'Mail-Registrierung ist eingeplant.',
            'previewModalEnabled' => (bool) $settings['preview_modal_enabled'],
            'livePreviewEnabled' => (bool) $settings['live_preview_enabled'],
            'livePreviewIntervalSeconds' => (int) $settings['live_preview_interval_seconds'],
            'livePreviewPollIntervalSeconds' => (int) $settings['live_preview_interval_seconds'],
            'browserActivityCheckEnabled' => (bool) $settings['browser_activity_check_enabled'],
            'domDebugEnabled' => (bool) $settings['dom_debug_enabled'],
            'scriptName' => 'mail_account.cjs',
            'scriptVersion' => self::MAIL_ACCOUNT_SCRIPT_VERSION,
            'scriptVersionLabel' => 'mail_account.cjs v'.self::MAIL_ACCOUNT_SCRIPT_VERSION,
            'scriptVersions' => [
                'mailAccount' => self::MAIL_ACCOUNT_SCRIPT_VERSION,
                'browserLauncher' => self::BROWSER_LAUNCHER_SCRIPT_VERSION,
            ],
            'at' => now()->toIso8601String(),
            'events' => [],
        ]);
        $this->writeJsonFile($configPath, $runtimeConfig);

        try {
            $pid = $this->spawnDetachedProcess(
                [
                    $this->resolveNodeBinary(),
                    $this->resolveNodeScriptPath(),
                    $configPath,
                ],
                base_path(),
                $stdoutPath,
                $stderrPath,
            );

            $status = $this->readJsonFile($statusPath) ?: [];
            $status['pid'] = $pid;
            $status['state'] = 'starting';
            $status['stage'] = 'process-started';
            $status['message'] = 'Node-Prozess wurde gestartet.';
            $status['previewModalEnabled'] = (bool) $settings['preview_modal_enabled'];
            $status['livePreviewEnabled'] = (bool) $settings['live_preview_enabled'];
            $status['livePreviewIntervalSeconds'] = (int) $settings['live_preview_interval_seconds'];
            $status['livePreviewPollIntervalSeconds'] = (int) $settings['live_preview_interval_seconds'];
            $status['browserActivityCheckEnabled'] = (bool) $settings['browser_activity_check_enabled'];
            $status['domDebugEnabled'] = (bool) $settings['dom_debug_enabled'];
            $status['scriptName'] = 'mail_account.cjs';
            $status['scriptVersion'] = self::MAIL_ACCOUNT_SCRIPT_VERSION;
            $status['scriptVersionLabel'] = 'mail_account.cjs v'.self::MAIL_ACCOUNT_SCRIPT_VERSION;
            $status['scriptVersions'] = [
                'mailAccount' => self::MAIL_ACCOUNT_SCRIPT_VERSION,
                'browserLauncher' => self::BROWSER_LAUNCHER_SCRIPT_VERSION,
            ];
            $status['at'] = now()->toIso8601String();
            $this->writeJsonFile($statusPath, $status);
        } catch (\Throwable $exception) {
            $this->writeJsonFile($statusPath, [
                'runId' => $runId,
                'processKey' => $this->processIdentity($runId, 'main', $normalizedSubject['personId'] ?? null)['processKey'],
                'processIdentity' => $this->processIdentity($runId, 'main', $normalizedSubject['personId'] ?? null),
                'providerKey' => $provider['key'],
                'providerLabel' => $provider['label'],
                'state' => 'failed',
                'stage' => 'process-start-failed',
                'message' => $exception->getMessage(),
                'previewModalEnabled' => (bool) $settings['preview_modal_enabled'],
                'livePreviewEnabled' => (bool) $settings['live_preview_enabled'],
                'livePreviewIntervalSeconds' => (int) $settings['live_preview_interval_seconds'],
                'livePreviewPollIntervalSeconds' => (int) $settings['live_preview_interval_seconds'],
                'browserActivityCheckEnabled' => (bool) $settings['browser_activity_check_enabled'],
                'domDebugEnabled' => (bool) $settings['dom_debug_enabled'],
                'scriptName' => 'mail_account.cjs',
                'scriptVersion' => self::MAIL_ACCOUNT_SCRIPT_VERSION,
                'scriptVersionLabel' => 'mail_account.cjs v'.self::MAIL_ACCOUNT_SCRIPT_VERSION,
                'scriptVersions' => [
                    'mailAccount' => self::MAIL_ACCOUNT_SCRIPT_VERSION,
                    'browserLauncher' => self::BROWSER_LAUNCHER_SCRIPT_VERSION,
                ],
                'at' => now()->toIso8601String(),
                'events' => [],
            ]);

            throw $exception;
        }

        return $this->readRun($runId) ?? [
            'runId' => $runId,
            'state' => 'starting',
            'stage' => 'process-started',
            'message' => 'Node-Prozess wurde gestartet.',
        ];
    }

    public function readRun(?string $runId): ?array
    {
        $runId = trim((string) $runId);

        if ($runId === '') {
            return null;
        }

        $statusPath = $this->runDirectory($runId).DIRECTORY_SEPARATOR.'status.json';

        if (! File::exists($statusPath)) {
            return null;
        }

        $status = $this->readJsonFile($statusPath) ?: [];
        $result = $this->readResult($runId);
        $state = (string) ($status['state'] ?? 'unknown');
        $webmailCheckPending = is_array($result) && (bool) ($result['webmailCheckPending'] ?? false);

        if ($webmailCheckPending) {
            $status = $this->scheduleWebmailCheckIfNeeded($runId, $status, $result);
            if (($status['state'] ?? null) !== 'failed') {
                $state = 'waiting';
                $status['state'] = $state;
                $status['stage'] = $status['stage'] ?? 'verification-webmail-check-scheduled';
                $status['message'] = (string) ($status['message'] ?? $result['statusMessage'] ?? '');
            }
        } elseif (is_array($result) && in_array($state, ['queued', 'starting', 'running'], true)) {
            $state = ($result['ok'] ?? false) ? 'completed' : 'failed';
            $status['state'] = $state;
            $status['stage'] = $state;
            $status['message'] = (string) ($result['statusMessage'] ?? $status['message'] ?? '');
        }

        $status['runId'] = $runId;
        $settings = $this->settings();
        $status['previewModalEnabled'] = (bool) ($status['previewModalEnabled'] ?? $settings['preview_modal_enabled'] ?? true);
        $status['livePreviewEnabled'] = (bool) ($status['livePreviewEnabled'] ?? $settings['live_preview_enabled'] ?? true);
        $status['livePreviewIntervalSeconds'] = (int) ($status['livePreviewIntervalSeconds'] ?? $settings['live_preview_interval_seconds'] ?? 3);
        $status['livePreviewPollIntervalSeconds'] = (int) ($status['livePreviewPollIntervalSeconds'] ?? $settings['live_preview_interval_seconds'] ?? 3);
        $status['browserActivityCheckEnabled'] = (bool) ($status['browserActivityCheckEnabled'] ?? $settings['browser_activity_check_enabled'] ?? true);
        $status['isRunning'] = in_array((string) ($status['state'] ?? ''), ['queued', 'starting', 'running'], true)
            || ($webmailCheckPending && ($status['state'] ?? null) !== 'failed');
        $status['screenshotUrl'] = $this->screenshotUrl($runId);
        $status['webmailScreenshotUrl'] = $this->webmailScreenshotUrl($runId);
        $status['registrationWindowStatus'] = $this->browserWindowStatus(
            $status,
            'Registrierung',
            $this->publicScreenshotRelativePath($runId),
            'Noch kein Screenshot verfuegbar.'
        );
        $status['webmailWindowStatus'] = $this->browserWindowStatus(
            $status,
            'Webmail',
            $this->publicWebmailScreenshotRelativePath($runId),
            'Webmail-Fenster noch nicht geoeffnet.'
        );
        $status['registrationDebugDomUrl'] = $this->debugDomUrlFor($runId, $status, 'registrationDebugDom', 'debug-dom-registration.json');
        $status['webmailDebugDomUrl'] = $this->debugDomUrlFor($runId, $status, 'webmailDebugDom', 'debug-dom-webmail.json');
        $status['debugDomUrl'] = $this->debugDomUrl($runId, $status);
        $status['result'] = $this->resultSummary($result);
        $status['processHeartbeatStatus'] = $this->processHeartbeatStatus($status);

        if (($status['processHeartbeatStatus']['stale'] ?? false) === true) {
            $status = $this->queueSupervisorJobIfNeeded($runId, $status);
        } elseif (($status['webmailWindowStatus']['stale'] ?? false) === true && ($status['webmailWindowStatus']['hasScreenshot'] ?? false) === true) {
            $status = $this->queueSupervisorJobIfNeeded($runId, $status, true, 'Webmail-Fenster liefert keine aktuellen Screenshots mehr; Supervisor-Restart wird angefordert.');
        }

        return $status;
    }

    public function readResult(?string $runId): ?array
    {
        $runId = trim((string) $runId);

        if ($runId === '') {
            return null;
        }

        $resultPath = $this->runDirectory($runId).DIRECTORY_SEPARATOR.'result.json';

        if (! File::exists($resultPath)) {
            return null;
        }

        return $this->readJsonFile($resultPath);
    }

    public function checkVerificationWebmail(string $runId): array
    {
        $runId = trim($runId);

        if ($runId === '') {
            throw new \InvalidArgumentException('Run-ID fuer Webmail-Check fehlt.');
        }

        $runDirectory = $this->runDirectory($runId);
        $statusPath = $runDirectory.DIRECTORY_SEPARATOR.'status.json';
        $resultPath = $runDirectory.DIRECTORY_SEPARATOR.'result.json';
        $runtimeConfigPath = $runDirectory.DIRECTORY_SEPARATOR.'runtime.json';
        $status = $this->readJsonFile($statusPath) ?: [];
        $result = $this->readJsonFile($resultPath) ?: [];
        $runtimeConfig = $this->readJsonFile($runtimeConfigPath) ?: [];

        if (! File::exists($runtimeConfigPath)) {
            $message = 'Runtime-Konfiguration fuer den Webmail-Check wurde nicht gefunden.';
            $this->writeJsonFile($statusPath, $this->statusWithEvent($status, 'failed', 'verification-webmail-check-failed', $message));

            throw new \RuntimeException($message);
        }

        $this->writeJsonFile($statusPath, $this->statusWithEvent(
            $status,
            'running',
            'verification-webmail-checking',
            'Webmail-Portal wird per verzoegertem Job auf Verifikations-E-Mail geprueft.'
        ));

        $processSuccessful = false;
        $processErrorOutput = '';

        try {
            $process = Process::path(base_path())
                ->timeout(80)
                ->run([
                    $this->resolveNodeBinary(),
                    $this->resolveVerificationWebmailCheckScriptPath($this->runtimeWebmailProvider($runtimeConfig)),
                    $runtimeConfigPath,
                ]);

            $processSuccessful = $process->successful();
            $processErrorOutput = trim($process->errorOutput());
            $payload = json_decode(trim($process->output()), true);
        } catch (\Throwable $exception) {
            $payload = [
                'ok' => false,
                'opened' => false,
                'mailDetected' => false,
                'verificationCode' => '',
                'statusMessage' => 'Webmail-Check konnte nicht ausgefuehrt werden.',
                'error' => $exception->getMessage(),
            ];
        }

        if (! is_array($payload)) {
            $payload = [
                'ok' => false,
                'opened' => false,
                'mailDetected' => false,
                'verificationCode' => '',
                'statusMessage' => 'Webmail-Check hat kein gueltiges JSON-Ergebnis geliefert.',
                'error' => $processErrorOutput,
            ];
        }

        $mailDetected = (bool) ($payload['mailDetected'] ?? $payload['ok'] ?? false);
        $verificationCode = trim((string) ($payload['verificationCode'] ?? ''));
        $message = $mailDetected
            ? ($verificationCode !== ''
                ? 'Verifikationsmail wurde im Webmail erkannt; Code wurde im Ergebnis gespeichert.'
                : 'Verifikationsmail wurde im Webmail erkannt.')
            : (trim((string) ($payload['statusMessage'] ?? '')) ?: 'Keine Verifikationsmail im Webmail erkannt.');

        if (! $processSuccessful && ! $mailDetected) {
            $message = trim((string) ($payload['error'] ?? '')) ?: $message;
        }

        $result = array_replace($result, [
            'ok' => false,
            'statusLevel' => $mailDetected ? 'waiting' : 'partial',
            'statusMessage' => $message,
            'registrationCompleted' => false,
            'manualActionRequired' => true,
            'verificationWebmailOpened' => (bool) ($payload['opened'] ?? false),
            'verificationWebmailMailDetected' => $mailDetected,
            'verificationCode' => $verificationCode,
            'webmailCheckPending' => false,
            'verificationWebmailCheckedAt' => now()->toIso8601String(),
            'verificationWebmailCheckResult' => $payload,
        ]);

        $this->writeJsonFile($resultPath, $result);

        $latestStatus = $this->readJsonFile($statusPath) ?: $status;
        $this->writeJsonFile($statusPath, $this->statusWithEvent(
            $latestStatus,
            $mailDetected ? 'waiting' : 'failed',
            $mailDetected ? 'verification-webmail-message-detected' : 'verification-webmail-message-timeout',
            $message,
            [
                'verificationWebmailCheckedAt' => $result['verificationWebmailCheckedAt'],
                'verificationWebmailMailDetected' => $mailDetected,
            ]
        ));

        return $result;
    }

    protected function scheduleWebmailCheckIfNeeded(string $runId, array $status, array $result): array
    {
        if (! (bool) ($result['webmailCheckPending'] ?? false)) {
            return $status;
        }

        if (trim((string) ($status['webmailCheckJobScheduledAt'] ?? '')) !== '') {
            return $status;
        }

        $dueAt = $this->verificationWebmailCheckDueAt($result);

        try {
            CheckMailRegistrationWebmailJob::dispatch($runId)
                ->onConnection('database')
                ->delay($dueAt);

            $message = 'Registrierung wartet; Webmail-Check wird um '.$dueAt->format('Y-m-d H:i:s').' gestartet.';
            $status = $this->statusWithEvent($status, 'waiting', 'verification-webmail-check-scheduled', $message, [
                'verificationWebmailCheckDueAt' => $dueAt->toIso8601String(),
            ]);
            $status['webmailCheckJobScheduledAt'] = now()->toIso8601String();
            $status['verificationWebmailCheckDueAt'] = $dueAt->toIso8601String();
        } catch (\Throwable $exception) {
            $status = $this->statusWithEvent(
                $status,
                'failed',
                'verification-webmail-check-schedule-failed',
                'Webmail-Check konnte nicht eingeplant werden: '.$exception->getMessage()
            );
        }

        $this->writeJsonFile($this->runDirectory($runId).DIRECTORY_SEPARATOR.'status.json', $status);

        return $status;
    }

    protected function verificationWebmailCheckDueAt(array $result): Carbon
    {
        $rawDueAt = trim((string) ($result['verificationWebmailCheckDueAt'] ?? ''));

        if ($rawDueAt !== '') {
            try {
                return Carbon::parse($rawDueAt);
            } catch (\Throwable) {
                // Fall back to the default delay below.
            }
        }

        return now()->addMinutes(5);
    }

    protected function statusWithEvent(array $status, string $state, string $stage, string $message, array $data = []): array
    {
        $event = array_filter([
            'at' => now()->toIso8601String(),
            'stage' => $stage,
            'message' => $message,
            ...$data,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $events = is_array($status['events'] ?? null) ? $status['events'] : [];
        $events[] = $event;

        if (count($events) > 80) {
            $events = array_slice($events, -80);
        }

        return array_replace($status, [
            'state' => $state,
            'stage' => $stage,
            'message' => $message,
            'at' => now()->toIso8601String(),
            'events' => $events,
        ], $data);
    }

    protected function normalizeProviders(mixed $providers): array
    {
        $providers = is_array($providers) ? array_values($providers) : [];
        $defaults = $this->defaultSettings()['providers'];
        $normalized = [];

        foreach ($defaults as $index => $defaultProvider) {
            $provider = is_array($providers[$index] ?? null) ? $providers[$index] : [];
            $key = trim((string) ($provider['key'] ?? $defaultProvider['key']));
            $mode = trim((string) ($provider['mode'] ?? $defaultProvider['mode']));
            $registrationUrl = trim((string) ($provider['registration_url'] ?? $defaultProvider['registration_url']));

            if ($mode === 'proton') {
                $mode = self::PROVIDER_MODE_PROTON_USERNAME_CHECK;
            }

            if ($mode === self::PROVIDER_MODE_OBSERVED_MANUAL && str_contains(strtolower($registrationUrl), 'proton.me')) {
                $mode = self::PROVIDER_MODE_PROTON_USERNAME_CHECK;
            }

            if (! in_array($mode, [self::PROVIDER_MODE_OBSERVED_MANUAL, self::PROVIDER_MODE_PROTON_USERNAME_CHECK, 'planned'], true)) {
                $mode = $defaultProvider['mode'];
            }

            if ($key === '') {
                $key = $defaultProvider['key'];
            }

            $normalized[] = [
                'key' => $key,
                'label' => trim((string) ($provider['label'] ?? $defaultProvider['label'])) ?: $defaultProvider['label'],
                'mode' => $mode ?: $defaultProvider['mode'],
                'enabled' => (bool) ($provider['enabled'] ?? $defaultProvider['enabled']),
                'phone_required' => (bool) ($provider['phone_required'] ?? $defaultProvider['phone_required']),
                'registration_url' => $registrationUrl,
                'completion_url_contains' => trim((string) ($provider['completion_url_contains'] ?? $defaultProvider['completion_url_contains'])),
                'completion_selector' => trim((string) ($provider['completion_selector'] ?? $defaultProvider['completion_selector'])),
                'webmail_url' => trim((string) ($provider['webmail_url'] ?? $defaultProvider['webmail_url'])),
            ];
        }

        return $normalized;
    }

    protected function normalizeVerificationMailbox(mixed $mailbox): array
    {
        $mailbox = is_array($mailbox) ? $mailbox : [];

        return [
            'enabled' => (bool) ($mailbox['enabled'] ?? false),
            'email' => trim((string) ($mailbox['email'] ?? '')),
            'provider' => $this->normalizeWebmailProvider($mailbox['provider'] ?? 'proton'),
            'username' => trim((string) ($mailbox['username'] ?? '')),
            'password_encrypted' => $this->nullableString($mailbox['password_encrypted'] ?? null),
            'webmail_url' => trim((string) ($mailbox['webmail_url'] ?? '')) ?: $this->defaultWebmailUrl($this->normalizeWebmailProvider($mailbox['provider'] ?? 'proton')),
            'webmail_session' => is_array($mailbox['webmail_session'] ?? null) ? $mailbox['webmail_session'] : null,
        ];
    }

    protected function normalizeWebmailProvider(mixed $provider): string
    {
        $provider = strtolower(trim((string) $provider));

        if ($provider === '' || str_contains($provider, 'proton')) {
            return 'proton';
        }

        if (str_contains($provider, 'gmx')) {
            return 'gmx';
        }

        return 'proton';
    }

    protected function defaultWebmailUrl(string $provider): string
    {
        return $provider === 'gmx'
            ? 'https://www.gmx.net'
            : 'https://mail.proton.me';
    }

    protected function processIdentity(string $runId, string $role, mixed $personId = null): array
    {
        $identity = [
            'processKey' => 'mail-registration:'.$runId.':'.$role,
            'runId' => $runId,
            'runType' => 'mail-registration',
            'role' => $role,
        ];

        if ((int) $personId > 0) {
            $identity['personId'] = (int) $personId;
        }

        return $identity;
    }

    protected function runtimeVerificationMailbox(array $mailbox): array
    {
        $mailbox = $this->normalizeVerificationMailbox($mailbox);
        $enabled = (bool) ($mailbox['enabled'] ?? false);
        $password = $enabled ? $this->decryptString($mailbox['password_encrypted'] ?? null) : null;
        $webmailSession = $this->runtimeWebmailSession($mailbox['webmail_session'] ?? null);

        return [
            'enabled' => $enabled,
            'email' => $mailbox['email'] ?? '',
            'provider' => $mailbox['provider'] ?? '',
            'username' => $mailbox['username'] ?: ($mailbox['email'] ?? ''),
            'password' => $password,
            'webmailUrl' => $mailbox['webmail_url'] ?? '',
            'webmailSession' => $webmailSession,
        ];
    }

    protected function runtimeWebmailSession(mixed $session): ?array
    {
        if (! is_array($session)) {
            return null;
        }

        $encryptedPayload = trim((string) ($session['payload_encrypted'] ?? ''));

        if ($encryptedPayload === '') {
            return null;
        }

        $payload = $this->decryptString($encryptedPayload);

        if ($payload === null || trim($payload) === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function decryptString(mixed $encrypted): ?string
    {
        if (! is_string($encrypted) || trim($encrypted) === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function resolveProvider(array $settings, ?string $providerKey = null): array
    {
        $providers = is_array($settings['providers'] ?? null) ? $settings['providers'] : [];
        $providerKey = trim((string) $providerKey);

        foreach ($providers as $provider) {
            if (! is_array($provider) || ! ($provider['enabled'] ?? false)) {
                continue;
            }

            if ($providerKey === '' || $providerKey === ($provider['key'] ?? null)) {
                return $provider;
            }
        }

        throw new \RuntimeException('Kein aktivierter Mail-Provider gefunden.');
    }

    protected function normalizeSubject(array $subject): array
    {
        return [
            'personId' => $subject['personId'] ?? $subject['person_id'] ?? null,
            'displayName' => trim((string) ($subject['displayName'] ?? $subject['display_name'] ?? '')),
            'firstName' => trim((string) ($subject['firstName'] ?? $subject['first_name'] ?? '')),
            'lastName' => trim((string) ($subject['lastName'] ?? $subject['last_name'] ?? '')),
            'desiredEmail' => trim((string) ($subject['desiredEmail'] ?? $subject['desired_email'] ?? '')),
            'accountUsername' => trim((string) ($subject['accountUsername'] ?? $subject['account_username'] ?? '')),
            'recoveryEmail' => trim((string) ($subject['recoveryEmail'] ?? $subject['recovery_email'] ?? '')),
            'city' => trim((string) ($subject['city'] ?? '')),
            'country' => trim((string) ($subject['country'] ?? '')),
            'timezone' => trim((string) ($subject['timezone'] ?? '')),
        ];
    }

    protected function resultSummary(?array $result): ?array
    {
        if (! is_array($result)) {
            return null;
        }

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'statusLevel' => $result['statusLevel'] ?? null,
            'statusMessage' => $result['statusMessage'] ?? null,
            'registrationCompleted' => (bool) ($result['registrationCompleted'] ?? false),
            'webmailCheckPending' => (bool) ($result['webmailCheckPending'] ?? false),
            'verificationWebmailCheckDueAt' => $result['verificationWebmailCheckDueAt'] ?? null,
            'verificationWebmailCheckedAt' => $result['verificationWebmailCheckedAt'] ?? null,
            'verificationWebmailMailDetected' => (bool) ($result['verificationWebmailMailDetected'] ?? false),
            'verificationCode' => $result['verificationCode'] ?? null,
            'account' => is_array($result['account'] ?? null)
                ? collect($result['account'])->except(['password', 'passwordEncrypted'])->all()
                : null,
        ];
    }

    protected function runDirectory(string $runId): string
    {
        return storage_path('app/mail-registration/runs/'.$runId);
    }

    protected function publicRunDirectory(string $runId): string
    {
        return storage_path('app/public/mail-registration/runs/'.$runId);
    }

    protected function publicScreenshotRelativePath(string $runId): string
    {
        return 'mail-registration/runs/'.$runId.'/live.png';
    }

    protected function publicWebmailScreenshotRelativePath(string $runId): string
    {
        return 'mail-registration/runs/'.$runId.'/live-webmail.png';
    }

    protected function publicDebugDomRelativePath(string $runId): string
    {
        return 'mail-registration/runs/'.$runId.'/debug-dom.json';
    }

    protected function publicWindowDebugDomRelativePath(string $runId, string $filename): string
    {
        return 'mail-registration/runs/'.$runId.'/'.$filename;
    }

    protected function screenshotUrl(string $runId): ?string
    {
        $relativePath = $this->publicScreenshotRelativePath($runId);
        $absolutePath = storage_path('app/public/'.$relativePath);

        if (! File::exists($absolutePath)) {
            return null;
        }

        return Storage::disk('public')->url($relativePath).'?v='.File::lastModified($absolutePath);
    }

    protected function webmailScreenshotUrl(string $runId): ?string
    {
        $relativePath = $this->publicWebmailScreenshotRelativePath($runId);
        $absolutePath = storage_path('app/public/'.$relativePath);

        if (! File::exists($absolutePath)) {
            return null;
        }

        return Storage::disk('public')->url($relativePath).'?v='.File::lastModified($absolutePath);
    }

    protected function browserWindowStatus(array $status, string $label, string $relativePath, string $emptyMessage): array
    {
        $absolutePath = storage_path('app/public/'.$relativePath);
        $hasScreenshot = File::exists($absolutePath);
        $heartbeatAt = null;
        $ageSeconds = null;
        $livePreviewEnabled = (bool) ($status['livePreviewEnabled'] ?? true);
        $intervalSeconds = max(1, (int) ($status['livePreviewIntervalSeconds'] ?? $status['livePreviewPollIntervalSeconds'] ?? 3));
        $isRunning = in_array((string) ($status['state'] ?? ''), ['queued', 'starting', 'running', 'waiting'], true);
        $aliveThreshold = max(10, ($intervalSeconds * 3) + 5);

        if ($hasScreenshot) {
            $heartbeat = Carbon::createFromTimestamp(File::lastModified($absolutePath));
            $heartbeatAt = $heartbeat->toIso8601String();
            $ageSeconds = (int) $heartbeat->diffInSeconds(now());
        }

        $alive = $hasScreenshot && (! $isRunning || $ageSeconds === null || $ageSeconds <= $aliveThreshold);
        $stale = $isRunning && $hasScreenshot && $ageSeconds !== null && $ageSeconds > max(60, $intervalSeconds * 10);

        if (! $livePreviewEnabled) {
            $statusText = 'Screenshots deaktiviert';
        } elseif ($alive && $isRunning) {
            $statusText = 'Lebenszeichen aktiv';
        } elseif ($hasScreenshot) {
            $statusText = 'Letztes Lebenszeichen';
        } else {
            $statusText = $emptyMessage;
        }

        return [
            'label' => $label,
            'alive' => $alive,
            'stale' => $stale,
            'staleAfterSeconds' => max(60, $intervalSeconds * 10),
            'hasScreenshot' => $hasScreenshot,
            'heartbeatAt' => $heartbeatAt,
            'ageSeconds' => $ageSeconds,
            'statusText' => $statusText,
            'state' => (string) ($status['state'] ?? 'unknown'),
            'stage' => (string) ($status['stage'] ?? ''),
            'message' => (string) ($status['message'] ?? ''),
            'livePreviewEnabled' => $livePreviewEnabled,
            'livePreviewIntervalSeconds' => $intervalSeconds,
        ];
    }

    protected function processHeartbeatStatus(array $status): array
    {
        $intervalSeconds = max(1, (int) ($status['livePreviewIntervalSeconds'] ?? $status['livePreviewPollIntervalSeconds'] ?? 3));
        $staleAfterSeconds = max(30, $intervalSeconds * 5);
        $isRunning = in_array((string) ($status['state'] ?? ''), ['queued', 'starting', 'running'], true);
        $heartbeatAt = $this->parseStatusTimestamp($status['heartbeatAt'] ?? $status['at'] ?? null);
        $ageSeconds = $heartbeatAt ? (int) $heartbeatAt->diffInSeconds(now()) : null;
        $stale = $isRunning && ($heartbeatAt === null || $ageSeconds > $staleAfterSeconds);

        return [
            'heartbeatAt' => $heartbeatAt?->toIso8601String(),
            'ageSeconds' => $ageSeconds,
            'staleAfterSeconds' => $staleAfterSeconds,
            'stale' => $stale,
            'statusText' => $stale
                ? 'Kein aktuelles Node-Lebenszeichen; Supervisor wird angefordert.'
                : ($heartbeatAt ? 'Node-Lebenszeichen aktiv.' : 'Noch kein Node-Lebenszeichen.'),
        ];
    }

    protected function queueSupervisorJobIfNeeded(string $runId, array $status, bool $force = false, ?string $reason = null): array
    {
        $queuedAt = $this->parseStatusTimestamp($status['supervisorJobQueuedAt'] ?? null);

        if ($queuedAt && $queuedAt->diffInSeconds(now()) < 60) {
            return $status;
        }

        try {
            SuperviseManagedProcessesJob::dispatch($runId, $force)->onConnection('database');
            $message = $reason ?: 'Supervisor-Job wurde wegen fehlendem Node-Lebenszeichen eingereiht.';
        } catch (\Throwable $exception) {
            $message = 'Supervisor-Job konnte nicht eingereiht werden: '.$exception->getMessage();
        }

        $events = is_array($status['events'] ?? null) ? $status['events'] : [];
        $events[] = [
            'at' => now()->toIso8601String(),
            'stage' => 'supervisor-job-queued',
            'message' => $message,
        ];

        if (count($events) > 80) {
            $events = array_slice($events, -80);
        }

        $status['supervisorJobQueuedAt'] = now()->toIso8601String();
        $status['supervisorMessage'] = $message;
        $status['events'] = $events;

        $this->writeJsonFile($this->runDirectory($runId).DIRECTORY_SEPARATOR.'status.json', $status);

        return $status;
    }

    protected function parseStatusTimestamp(mixed $value): ?Carbon
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function debugDomUrl(string $runId, array $status): ?string
    {
        $debugDom = $this->latestDebugDom($status, 'debugDom');

        if ($debugDom === null) {
            return null;
        }

        $relativePath = $this->publicDebugDomRelativePath($runId);
        $absolutePath = storage_path('app/public/'.$relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, json_encode($debugDom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return Storage::disk('public')->url($relativePath).'?v='.File::lastModified($absolutePath);
    }

    protected function debugDomUrlFor(string $runId, array $status, string $key, string $filename): ?string
    {
        $debugDom = $this->latestDebugDom($status, $key);

        if ($debugDom === null) {
            return null;
        }

        $relativePath = $this->publicWindowDebugDomRelativePath($runId, $filename);
        $absolutePath = storage_path('app/public/'.$relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, json_encode($debugDom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return Storage::disk('public')->url($relativePath).'?v='.File::lastModified($absolutePath);
    }

    protected function latestDebugDom(array $status, string $key = 'debugDom'): mixed
    {
        if (array_key_exists($key, $status) && $status[$key] !== null && $status[$key] !== '') {
            return $status[$key];
        }

        $events = is_array($status['events'] ?? null) ? array_reverse($status['events']) : [];

        foreach ($events as $event) {
            if (is_array($event) && array_key_exists($key, $event) && $event[$key] !== null && $event[$key] !== '') {
                return $event[$key];
            }
        }

        return null;
    }

    protected function writeJsonFile(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function readJsonFile(string $path): ?array
    {
        try {
            $payload = json_decode(File::get($path), true);

            return is_array($payload) ? $payload : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function resolveNodeScriptPath(): string
    {
        $nodeScript = base_path('resources/node/register/mail_account.cjs');

        if (! File::exists($nodeScript)) {
            throw new \RuntimeException(sprintf('Das lokale Node-Skript fuer Mail-Registrierung wurde nicht gefunden: %s', $nodeScript));
        }

        return $nodeScript;
    }

    protected function resolveVerificationWebmailCheckScriptPath(string $provider = 'proton'): string
    {
        $scriptName = $provider === 'gmx'
            ? 'check_verification_webmail_gmx.cjs'
            : 'check_verification_webmail_proton.cjs';
        $nodeScript = base_path('resources/node/register/'.$scriptName);

        if (! File::exists($nodeScript)) {
            throw new \RuntimeException(sprintf('Das lokale Node-Skript fuer Webmail-Verifikationscheck wurde nicht gefunden: %s', $nodeScript));
        }

        return $nodeScript;
    }

    protected function runtimeWebmailProvider(array $runtimeConfig): string
    {
        $mailbox = is_array($runtimeConfig['verificationMailbox'] ?? null) ? $runtimeConfig['verificationMailbox'] : [];

        return $this->normalizeWebmailProvider($mailbox['provider'] ?? 'proton');
    }

    protected function resolveNodeBinary(): string
    {
        $candidates = PHP_OS_FAMILY === 'Windows'
            ? [
                'C:\\Program Files\\nodejs\\node.exe',
                'C:\\Program Files (x86)\\nodejs\\node.exe',
            ]
            : [
                '/usr/bin/node',
                '/usr/local/bin/node',
                '/bin/node',
                '/snap/bin/node',
                '/usr/bin/nodejs',
                '/usr/local/bin/nodejs',
            ];

        foreach ($candidates as $candidate) {
            if (File::exists($candidate)) {
                return $candidate;
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $resolved = Process::timeout(5)->run(['where.exe', 'node']);
        } else {
            $resolved = Process::timeout(5)->run(['sh', '-lc', 'command -v node 2>/dev/null || command -v nodejs 2>/dev/null']);
        }

        $binary = trim(strtok($resolved->output(), "\r\n") ?: '');

        if ($resolved->successful() && $binary !== '') {
            return $binary;
        }

        throw new \RuntimeException('Node.js wurde fuer die Mail-Registrierung nicht gefunden.');
    }

    protected function spawnDetachedProcess(array $command, string $workingDirectory, string $stdoutPath, string $stderrPath): ?int
    {
        File::ensureDirectoryExists(dirname($stdoutPath));
        File::ensureDirectoryExists(dirname($stderrPath));

        if (PHP_OS_FAMILY === 'Windows') {
            $script = '$p = Start-Process'
                .' -FilePath '.$this->powershellQuote($command[0])
                .' -ArgumentList @('.implode(',', array_map(fn (string $argument): string => $this->powershellQuote($argument), array_slice($command, 1))).')'
                .' -WorkingDirectory '.$this->powershellQuote($workingDirectory)
                .' -WindowStyle Hidden'
                .' -RedirectStandardOutput '.$this->powershellQuote($stdoutPath)
                .' -RedirectStandardError '.$this->powershellQuote($stderrPath)
                .' -PassThru; Write-Output $p.Id';

            $result = Process::timeout(15)->run([
                'powershell.exe',
                '-NoProfile',
                '-ExecutionPolicy',
                'Bypass',
                '-Command',
                $script,
            ]);
        } else {
            $shellCommand = sprintf(
                'cd %s && nohup %s > %s 2> %s < /dev/null & echo $!',
                escapeshellarg($workingDirectory),
                implode(' ', array_map('escapeshellarg', $command)),
                escapeshellarg($stdoutPath),
                escapeshellarg($stderrPath),
            );

            $result = Process::timeout(15)->run(['sh', '-lc', $shellCommand]);
        }

        if (! $result->successful()) {
            throw new \RuntimeException(trim($result->errorOutput()) ?: 'Der Node-Prozess konnte nicht gestartet werden.');
        }

        $pid = (int) trim($result->output());

        return $pid > 0 ? $pid : null;
    }

    protected function powershellQuote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
