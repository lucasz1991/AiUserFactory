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

    public function start(array $account, string $scope = 'webmail'): array
    {
        $runtime = $this->runtimeConfig($account, $scope);
        $runId = $runtime['runId'];
        $runDirectory = $this->runDirectory($runId);
        $statusPath = $runtime['statusPath'];
        $stdoutPath = $runDirectory.DIRECTORY_SEPARATOR.'stdout.log';
        $stderrPath = $runDirectory.DIRECTORY_SEPARATOR.'stderr.log';
        $configPath = $runDirectory.DIRECTORY_SEPARATOR.'runtime.json';

        File::ensureDirectoryExists($runDirectory);
        File::ensureDirectoryExists(dirname($runtime['livePreviewPath']));
        File::ensureDirectoryExists(dirname($runtime['sessionFilePath']));

        $this->writeJsonFile($statusPath, [
            'runId' => $runId,
            'providerKey' => $runtime['provider'],
            'state' => 'queued',
            'stage' => 'queued',
            'message' => 'Webmail-Sessionlauf ist eingeplant.',
            'livePreviewEnabled' => (bool) ($runtime['livePreviewEnabled'] ?? true),
            'livePreviewIntervalSeconds' => (int) ($runtime['livePreviewIntervalSeconds'] ?? 3),
            'livePreviewPollIntervalSeconds' => (int) ($runtime['livePreviewPollIntervalSeconds'] ?? 3),
            'scriptName' => basename($this->resolveNodeScriptPath($runtime['provider'])),
            'scriptVersion' => 1,
            'at' => now()->toIso8601String(),
            'events' => [],
        ]);
        $this->writeJsonFile($configPath, $runtime);

        try {
            $pid = $this->spawnDetachedProcess([
                $this->resolveNodeBinary(),
                $this->resolveNodeScriptPath($runtime['provider']),
                $configPath,
            ], base_path(), $stdoutPath, $stderrPath);

            $status = $this->readJsonFile($statusPath) ?: [];
            $status['pid'] = $pid;
            $status['state'] = 'starting';
            $status['stage'] = 'process-started';
            $status['message'] = 'Webmail-Sessionprozess wurde gestartet.';
            $status['livePreviewEnabled'] = (bool) ($runtime['livePreviewEnabled'] ?? true);
            $status['livePreviewIntervalSeconds'] = (int) ($runtime['livePreviewIntervalSeconds'] ?? 3);
            $status['livePreviewPollIntervalSeconds'] = (int) ($runtime['livePreviewPollIntervalSeconds'] ?? 3);
            $status['at'] = now()->toIso8601String();
            $this->writeJsonFile($statusPath, $status);
        } catch (\Throwable $exception) {
            $this->writeJsonFile($statusPath, [
                'runId' => $runId,
                'providerKey' => $runtime['provider'],
                'state' => 'failed',
                'stage' => 'process-start-failed',
                'message' => $exception->getMessage(),
                'livePreviewEnabled' => (bool) ($runtime['livePreviewEnabled'] ?? true),
                'livePreviewIntervalSeconds' => (int) ($runtime['livePreviewIntervalSeconds'] ?? 3),
                'livePreviewPollIntervalSeconds' => (int) ($runtime['livePreviewPollIntervalSeconds'] ?? 3),
                'scriptName' => basename($this->resolveNodeScriptPath($runtime['provider'])),
                'scriptVersion' => 1,
                'at' => now()->toIso8601String(),
                'events' => [],
            ]);

            throw $exception;
        }

        return $this->readRun($runId) ?? [
            'runId' => $runId,
            'state' => 'starting',
            'stage' => 'process-started',
            'message' => 'Webmail-Sessionprozess wurde gestartet.',
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

        if (is_array($result)) {
            $result = $this->finalizeRunResult($runId, $result);

            if (in_array($state, ['queued', 'starting', 'running'], true)) {
                $state = ($result['ok'] ?? false) ? 'completed' : 'failed';
                $status['state'] = $state;
                $status['stage'] = $state;
                $status['message'] = (string) ($result['statusMessage'] ?? $status['message'] ?? '');
                $this->writeJsonFile($statusPath, $status);
            }
        }

        $status['runId'] = $runId;
        $status['isRunning'] = in_array((string) ($status['state'] ?? ''), ['queued', 'starting', 'running'], true);
        $status['livePreviewIntervalSeconds'] = (int) ($status['livePreviewIntervalSeconds'] ?? 3);
        $status['livePreviewPollIntervalSeconds'] = (int) ($status['livePreviewPollIntervalSeconds'] ?? $status['livePreviewIntervalSeconds']);
        $status['screenshotUrl'] = $this->runScreenshotUrl($runId);
        $status['debugDomUrl'] = $this->debugDomUrl($runId, $status);
        $status['debugDom'] = $this->latestDebugDom($status);
        $status['result'] = $result;

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
            'livePreviewIntervalSeconds' => max(1, min(60, (int) ($account['livePreviewIntervalSeconds'] ?? ceil(((int) ($account['livePreviewIntervalMs'] ?? 3000)) / 1000)))),
            'livePreviewIntervalMs' => max(1000, (int) ($account['livePreviewIntervalMs'] ?? ((int) ($account['livePreviewIntervalSeconds'] ?? 3)) * 1000)),
            'livePreviewPollIntervalSeconds' => max(1, min(60, (int) ($account['livePreviewIntervalSeconds'] ?? ceil(((int) ($account['livePreviewIntervalMs'] ?? 3000)) / 1000)))),
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

    protected function runtimeConfig(array $account, string $scope): array
    {
        $provider = $this->normalizeProvider($account['provider'] ?? null);
        $webmailUrl = trim((string) ($account['webmailUrl'] ?? $account['webmail_url'] ?? '')) ?: $this->defaultWebmailUrl($provider);
        $runId = (string) Str::uuid();
        $runDirectory = $this->runDirectory($runId);
        $publicRelativePreviewPath = $this->publicScreenshotRelativePath($runId);

        if ($webmailUrl === '' || ! filter_var($webmailUrl, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('Bitte hinterlege eine gueltige Webmail-URL.');
        }

        return [
            'runId' => $runId,
            'scope' => $this->safeScope($scope),
            'provider' => $provider,
            'email' => trim((string) ($account['email'] ?? '')),
            'username' => trim((string) ($account['username'] ?? $account['email'] ?? '')),
            'password' => (string) ($account['password'] ?? ''),
            'webmailUrl' => $webmailUrl,
            'sessionFilePath' => $runDirectory.DIRECTORY_SEPARATOR.'session.json',
            'browserEngine' => trim((string) ($account['browserEngine'] ?? 'cloak-with-chrome-fallback')),
            'cloakHumanizeEnabled' => (bool) ($account['cloakHumanizeEnabled'] ?? false),
            'cloakHumanPreset' => trim((string) ($account['cloakHumanPreset'] ?? '')),
            'headlessEnabled' => (bool) ($account['headlessEnabled'] ?? false),
            'browserProfilePath' => $runDirectory.DIRECTORY_SEPARATOR.'browser-profile',
            'livePreviewEnabled' => (bool) ($account['livePreviewEnabled'] ?? true),
            'livePreviewIntervalSeconds' => max(1, min(60, (int) ($account['livePreviewIntervalSeconds'] ?? ceil(((int) ($account['livePreviewIntervalMs'] ?? 3000)) / 1000)))),
            'livePreviewIntervalMs' => max(1000, (int) ($account['livePreviewIntervalMs'] ?? ((int) ($account['livePreviewIntervalSeconds'] ?? 3)) * 1000)),
            'livePreviewPollIntervalSeconds' => max(1, min(60, (int) ($account['livePreviewIntervalSeconds'] ?? ceil(((int) ($account['livePreviewIntervalMs'] ?? 3000)) / 1000)))),
            'livePreviewPath' => storage_path('app/public/'.$publicRelativePreviewPath),
            'livePreviewRelativePath' => $publicRelativePreviewPath,
            'statusPath' => $runDirectory.DIRECTORY_SEPARATOR.'status.json',
            'resultPath' => $runDirectory.DIRECTORY_SEPARATOR.'result.json',
            'navigationTimeoutMs' => max(30000, (int) ($account['navigationTimeoutMs'] ?? 120000)),
            'observationTimeoutMs' => max(30000, min(180000, (int) ($account['observationTimeoutMs'] ?? 60000))),
            'postLoginWaitMs' => max(500, (int) ($account['postLoginWaitMs'] ?? 2500)),
            'typingDelayMs' => max(0, (int) ($account['typingDelayMs'] ?? 35)),
        ];
    }

    protected function finalizeRunResult(string $runId, array $result): array
    {
        if (($result['sessionFinalized'] ?? false) === true) {
            return $result;
        }

        $sessionFilePath = trim((string) ($result['sessionFilePath'] ?? $this->runDirectory($runId).DIRECTORY_SEPARATOR.'session.json'));
        $sessionPayload = File::exists($sessionFilePath) ? trim(File::get($sessionFilePath)) : '';

        if ($sessionPayload === '') {
            $result['ok'] = false;
            $result['warnings'] = array_values(array_filter([
                ...((array) ($result['warnings'] ?? [])),
                'Es wurde keine Webmail-Session-Datei erzeugt.',
            ]));
            $result['sessionFinalized'] = true;
            $result['screenshotUrl'] = $this->runScreenshotUrl($runId);
            $this->writeJsonFile($this->runDirectory($runId).DIRECTORY_SEPARATOR.'result.json', $result);

            return $result;
        }

        $decodedSession = json_decode($sessionPayload, true);
        $summary = [
            'capturedAt' => is_array($decodedSession) ? ($decodedSession['capturedAt'] ?? now()->toIso8601String()) : now()->toIso8601String(),
            'finalUrl' => is_array($decodedSession) ? ($decodedSession['finalUrl'] ?? null) : null,
            'origin' => is_array($decodedSession) ? ($decodedSession['origin'] ?? null) : null,
            'cookieCount' => is_array($decodedSession) && is_array($decodedSession['cookies'] ?? null)
                ? count($decodedSession['cookies'])
                : 0,
        ];

        $result['encryptedSessionPayload'] = Crypt::encryptString($sessionPayload);
        $result['sessionPayloadHash'] = hash('sha256', $sessionPayload);
        $result['sessionSummary'] = $summary;
        $result['sessionFinalized'] = true;
        $result['screenshotUrl'] = $this->runScreenshotUrl($runId);

        File::delete($sessionFilePath);
        $this->writeJsonFile($this->runDirectory($runId).DIRECTORY_SEPARATOR.'result.json', $result);

        return $result;
    }

    protected function runDirectory(string $runId): string
    {
        return storage_path('app/webmail-session/runs/'.$runId);
    }

    protected function publicScreenshotRelativePath(string $runId): string
    {
        return 'webmail-session/runs/'.$runId.'/live.png';
    }

    protected function publicDebugDomRelativePath(string $runId): string
    {
        return 'webmail-session/runs/'.$runId.'/debug-dom.json';
    }

    protected function runScreenshotUrl(string $runId): ?string
    {
        return $this->screenshotUrl($this->publicScreenshotRelativePath($runId));
    }

    protected function debugDomUrl(string $runId, array $status): ?string
    {
        $debugDom = $this->latestDebugDom($status);

        if ($debugDom === null) {
            return null;
        }

        $relativePath = $this->publicDebugDomRelativePath($runId);
        $absolutePath = storage_path('app/public/'.$relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, json_encode($debugDom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return Storage::disk('public')->url($relativePath).'?v='.File::lastModified($absolutePath);
    }

    protected function latestDebugDom(array $status): mixed
    {
        $events = is_array($status['events'] ?? null) ? array_reverse($status['events']) : [];

        foreach ($events as $event) {
            if (is_array($event) && array_key_exists('debugDom', $event) && $event['debugDom'] !== null && $event['debugDom'] !== '') {
                return $event['debugDom'];
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
            throw new \RuntimeException(trim($result->errorOutput()) ?: 'Der Webmail-Sessionprozess konnte nicht gestartet werden.');
        }

        $pid = (int) trim($result->output());

        return $pid > 0 ? $pid : null;
    }

    protected function powershellQuote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
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
