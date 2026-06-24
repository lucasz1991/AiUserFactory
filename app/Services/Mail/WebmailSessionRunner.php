<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WebmailSessionRunner
{
    public const PROVIDER_PROTON = 'proton';
    public const PROVIDER_GMX = 'gmx';

    public function capture(array $account, string $scope = 'webmail'): array
    {
        $provider = $this->normalizeProvider($account['provider'] ?? null);
        $webmailUrl = trim((string) ($account['webmailUrl'] ?? $account['webmail_url'] ?? ''));
        $runId = (string) Str::uuid();
        $safeScope = $this->safeScope($scope);
        $sessionFilePath = storage_path('app/mail-sessions/'.$safeScope.'-'.$runId.'.json');
        $runtimeConfigPath = storage_path('app/tmp/webmail-session-'.Str::uuid().'.json');
        $publicRelativePreviewPath = 'mail-sessions/'.$safeScope.'-'.$runId.'/live.png';
        $livePreviewPath = storage_path('app/public/'.$publicRelativePreviewPath);

        if ($webmailUrl === '') {
            $webmailUrl = $this->defaultWebmailUrl($provider);
        }

        if ($webmailUrl === '' || ! filter_var($webmailUrl, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('Bitte hinterlege eine gueltige Webmail-URL.');
        }

        $runtimeConfig = [
            'provider' => $provider,
            'email' => trim((string) ($account['email'] ?? '')),
            'username' => trim((string) ($account['username'] ?? $account['email'] ?? '')),
            'password' => (string) ($account['password'] ?? ''),
            'webmailUrl' => $webmailUrl,
            'sessionFilePath' => $sessionFilePath,
            'browserEngine' => trim((string) ($account['browserEngine'] ?? 'cloak-with-chrome-fallback')),
            'cloakHumanizeEnabled' => (bool) ($account['cloakHumanizeEnabled'] ?? false),
            'cloakHumanPreset' => trim((string) ($account['cloakHumanPreset'] ?? '')),
            'headlessEnabled' => (bool) ($account['headlessEnabled'] ?? false),
            'browserProfilePath' => storage_path('app/mail-sessions/'.$safeScope.'-'.$runId.'/browser-profile'),
            'livePreviewEnabled' => (bool) ($account['livePreviewEnabled'] ?? true),
            'livePreviewPath' => $livePreviewPath,
            'livePreviewRelativePath' => $publicRelativePreviewPath,
            'navigationTimeoutMs' => max(30000, (int) ($account['navigationTimeoutMs'] ?? 120000)),
            'observationTimeoutMs' => max(30000, min(180000, (int) ($account['observationTimeoutMs'] ?? 60000))),
            'postLoginWaitMs' => max(500, (int) ($account['postLoginWaitMs'] ?? 2500)),
            'typingDelayMs' => max(0, (int) ($account['typingDelayMs'] ?? 35)),
        ];

        File::ensureDirectoryExists(dirname($runtimeConfigPath));
        File::put($runtimeConfigPath, json_encode($runtimeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            $process = Process::path(base_path())
                ->timeout(max(90, (int) ceil($runtimeConfig['observationTimeoutMs'] / 1000) + 60))
                ->run([
                    $this->resolveNodeBinary(),
                    $this->resolveNodeScriptPath($provider),
                    $runtimeConfigPath,
                ]);
        } finally {
            if (File::exists($runtimeConfigPath)) {
                File::delete($runtimeConfigPath);
            }
        }

        $payload = json_decode(trim($process->output()), true);

        if (! is_array($payload)) {
            throw new \RuntimeException('Das Webmail-Session-Skript hat kein gueltiges JSON-Ergebnis geliefert.');
        }

        $sessionPayload = File::exists($sessionFilePath)
            ? trim(File::get($sessionFilePath))
            : '';

        if ($sessionPayload === '') {
            if (File::exists($sessionFilePath)) {
                File::delete($sessionFilePath);
            }

            $payload['ok'] = false;
            $payload['warnings'] = array_values(array_filter([
                ...((array) ($payload['warnings'] ?? [])),
                'Es wurde keine Webmail-Session-Datei erzeugt.',
            ]));
            $payload['screenshotUrl'] = $this->screenshotUrl($publicRelativePreviewPath);

            return $payload;
        }

        $decodedSession = json_decode($sessionPayload, true);
        $payload['encryptedSessionPayload'] = Crypt::encryptString($sessionPayload);
        $payload['sessionPayloadHash'] = hash('sha256', $sessionPayload);
        $payload['providerKey'] = $provider;
        $payload['screenshotUrl'] = $this->screenshotUrl($publicRelativePreviewPath);
        $payload['sessionSummary'] = [
            'capturedAt' => is_array($decodedSession) ? ($decodedSession['capturedAt'] ?? now()->toIso8601String()) : now()->toIso8601String(),
            'finalUrl' => is_array($decodedSession) ? ($decodedSession['finalUrl'] ?? null) : null,
            'origin' => is_array($decodedSession) ? ($decodedSession['origin'] ?? null) : null,
            'cookieCount' => is_array($decodedSession) && is_array($decodedSession['cookies'] ?? null)
                ? count($decodedSession['cookies'])
                : 0,
        ];

        File::delete($sessionFilePath);

        return $payload;
    }

    protected function resolveNodeScriptPath(string $provider): string
    {
        $scriptName = match ($provider) {
            self::PROVIDER_GMX => 'webmail_session_gmx.cjs',
            self::PROVIDER_PROTON => 'webmail_session_proton.cjs',
            default => throw new \RuntimeException('Nicht unterstuetzter Webmail-Provider: '.$provider),
        };
        $nodeScript = base_path('resources/node/session/'.$scriptName);

        if (! File::exists($nodeScript)) {
            throw new \RuntimeException(sprintf('Das lokale Node-Skript fuer Webmail-Session wurde nicht gefunden: %s', $nodeScript));
        }

        return $nodeScript;
    }

    protected function normalizeProvider(mixed $provider): string
    {
        $provider = strtolower(trim((string) $provider));

        if ($provider === '' || str_contains($provider, 'proton')) {
            return self::PROVIDER_PROTON;
        }

        if (str_contains($provider, 'gmx')) {
            return self::PROVIDER_GMX;
        }

        if (in_array($provider, [self::PROVIDER_PROTON, self::PROVIDER_GMX], true)) {
            return $provider;
        }

        throw new \RuntimeException('Bitte waehle als Webmail-Provider Proton oder GMX.');
    }

    protected function defaultWebmailUrl(string $provider): string
    {
        return match ($provider) {
            self::PROVIDER_GMX => 'https://www.gmx.net',
            self::PROVIDER_PROTON => 'https://mail.proton.me',
            default => '',
        };
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

        $resolved = PHP_OS_FAMILY === 'Windows'
            ? Process::timeout(5)->run(['where.exe', 'node'])
            : Process::timeout(5)->run(['sh', '-lc', 'command -v node 2>/dev/null || command -v nodejs 2>/dev/null']);

        if ($resolved->successful()) {
            $candidate = trim(strtok($resolved->output(), "\r\n") ?: '');

            if ($candidate !== '') {
                return $candidate;
            }
        }

        throw new \RuntimeException('Node.js wurde fuer Webmail-Session nicht gefunden.');
    }

    protected function safeScope(string $scope): string
    {
        return Str::slug($scope) ?: 'webmail';
    }

    protected function screenshotUrl(string $relativePath): ?string
    {
        $absolutePath = storage_path('app/public/'.$relativePath);

        if (! File::exists($absolutePath)) {
            return null;
        }

        return Storage::disk('public')->url($relativePath).'?v='.File::lastModified($absolutePath);
    }
}
