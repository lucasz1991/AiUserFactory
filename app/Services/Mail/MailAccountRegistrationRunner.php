<?php

namespace App\Services\Mail;

use App\Models\Setting;
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
            'navigation_timeout_seconds' => 120,
            'observation_timeout_seconds' => 300,
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

        return [
            'browser_engine' => $browserEngine,
            'cloak_humanize_enabled' => (bool) ($settings['cloak_humanize_enabled'] ?? false),
            'cloak_human_preset' => trim((string) ($settings['cloak_human_preset'] ?? '')),
            'headless_enabled' => (bool) ($settings['headless_enabled'] ?? false),
            'navigation_timeout_seconds' => max(30, min(300, (int) ($settings['navigation_timeout_seconds'] ?? 120))),
            'observation_timeout_seconds' => max(30, min(1800, (int) ($settings['observation_timeout_seconds'] ?? 300))),
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

        $runtimeConfig = [
            'runId' => $runId,
            'browserEngine' => $settings['browser_engine'],
            'cloakHumanizeEnabled' => (bool) $settings['cloak_humanize_enabled'],
            'cloakHumanPreset' => $settings['cloak_human_preset'],
            'headlessEnabled' => (bool) $settings['headless_enabled'],
            'navigationTimeoutMs' => $settings['navigation_timeout_seconds'] * 1000,
            'observationTimeoutMs' => $settings['observation_timeout_seconds'] * 1000,
            'browserProfilePath' => $runDirectory.DIRECTORY_SEPARATOR.'browser-profile',
            'livePreviewEnabled' => true,
            'livePreviewPath' => $livePreviewPath,
            'livePreviewRelativePath' => $this->publicScreenshotRelativePath($runId),
            'statusPath' => $statusPath,
            'resultPath' => $resultPath,
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
            'subject' => $this->normalizeSubject($subject),
            'protonUsernameCheckTimeoutMs' => 30000,
        ];

        $this->writeJsonFile($statusPath, [
            'runId' => $runId,
            'providerKey' => $provider['key'],
            'providerLabel' => $provider['label'],
            'state' => 'queued',
            'stage' => 'queued',
            'message' => 'Mail-Registrierung ist eingeplant.',
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
            $status['at'] = now()->toIso8601String();
            $this->writeJsonFile($statusPath, $status);
        } catch (\Throwable $exception) {
            $this->writeJsonFile($statusPath, [
                'runId' => $runId,
                'providerKey' => $provider['key'],
                'providerLabel' => $provider['label'],
                'state' => 'failed',
                'stage' => 'process-start-failed',
                'message' => $exception->getMessage(),
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

        if (is_array($result) && in_array($state, ['queued', 'starting', 'running'], true)) {
            $state = ($result['ok'] ?? false) ? 'completed' : 'failed';
            $status['state'] = $state;
            $status['stage'] = $state;
            $status['message'] = (string) ($result['statusMessage'] ?? $status['message'] ?? '');
        }

        $status['runId'] = $runId;
        $status['isRunning'] = in_array((string) ($status['state'] ?? ''), ['queued', 'starting', 'running'], true);
        $status['screenshotUrl'] = $this->screenshotUrl($runId);
        $status['result'] = $this->resultSummary($result);

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

    protected function normalizeProviders(mixed $providers): array
    {
        $providers = is_array($providers) ? array_values($providers) : [];
        $defaults = $this->defaultSettings()['providers'];
        $normalized = [];

        foreach ($defaults as $index => $defaultProvider) {
            $provider = is_array($providers[$index] ?? null) ? $providers[$index] : [];
            $key = trim((string) ($provider['key'] ?? $defaultProvider['key']));
            $mode = trim((string) ($provider['mode'] ?? $defaultProvider['mode']));

            if ($mode === 'proton') {
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
                'registration_url' => trim((string) ($provider['registration_url'] ?? $defaultProvider['registration_url'])),
                'completion_url_contains' => trim((string) ($provider['completion_url_contains'] ?? $defaultProvider['completion_url_contains'])),
                'completion_selector' => trim((string) ($provider['completion_selector'] ?? $defaultProvider['completion_selector'])),
                'webmail_url' => trim((string) ($provider['webmail_url'] ?? $defaultProvider['webmail_url'])),
            ];
        }

        return $normalized;
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

    protected function screenshotUrl(string $runId): ?string
    {
        $relativePath = $this->publicScreenshotRelativePath($runId);
        $absolutePath = storage_path('app/public/'.$relativePath);

        if (! File::exists($absolutePath)) {
            return null;
        }

        return Storage::disk('public')->url($relativePath).'?v='.File::lastModified($absolutePath);
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
