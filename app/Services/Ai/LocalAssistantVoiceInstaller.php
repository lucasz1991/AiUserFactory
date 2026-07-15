<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class LocalAssistantVoiceInstaller
{
    public function __construct(
        private readonly LocalAssistantVoiceService $voice,
    ) {}

    /**
     * @return array{
     *     state_status: string,
     *     running: bool,
     *     pid: int|null,
     *     can_start: bool,
     *     preflight_errors: list<string>,
     *     state: array<string, mixed>,
     *     voice: array<string, mixed>,
     *     log_path: string,
     *     log_tail: string
     * }
     */
    public function status(): array
    {
        $voice = $this->voice->status();
        $state = $this->readState();
        $pid = max(0, (int) ($state['pid'] ?? 0));
        $running = $pid > 1 && $this->processIsRunning($pid);
        $stateStatus = trim((string) ($state['status'] ?? 'idle')) ?: 'idle';

        if ($running) {
            $stateStatus = 'running';
        } elseif (($voice['ready'] ?? false) === true) {
            $stateStatus = 'complete';
        } elseif (in_array($stateStatus, ['launching', 'running'], true)) {
            $stateStatus = 'interrupted';
        }

        $preflightErrors = $this->preflightErrors();

        return [
            'state_status' => $stateStatus,
            'running' => $running,
            'pid' => $pid > 1 ? $pid : null,
            'can_start' => $preflightErrors === []
                && ! $running
                && ! (bool) ($voice['ready'] ?? false),
            'preflight_errors' => $preflightErrors,
            'state' => $state,
            'voice' => $voice,
            'log_path' => $this->logPath(),
            'log_tail' => $this->readLogTail(),
        ];
    }

    /**
     * @return array{started: bool, already_ready: bool, pid: int|null, log_path: string}
     */
    public function startDetached(int $buildJobs = 2): array
    {
        $this->assertBuildJobs($buildJobs);
        $this->assertLinux();
        $this->ensureRuntimeDirectories();

        $lock = $this->openLock();

        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            throw new RuntimeException('Eine Installation der lokalen Sprachlaufzeit laeuft bereits.');
        }

        try {
            $status = $this->status();

            if (($status['voice']['ready'] ?? false) === true) {
                return [
                    'started' => false,
                    'already_ready' => true,
                    'pid' => $status['pid'],
                    'log_path' => $this->logPath(),
                ];
            }

            if ($status['running']) {
                throw new RuntimeException('Eine Installation der lokalen Sprachlaufzeit laeuft bereits.');
            }

            if ($status['preflight_errors'] !== []) {
                throw new RuntimeException(implode(' ', $status['preflight_errors']));
            }

            $launchedAt = now()->toIso8601String();
            $this->appendLog(sprintf(
                "\n[local-voice-launcher] %s: Installationslauf wird gestartet.\n",
                $launchedAt,
            ));
            $this->writeState([
                'status' => 'launching',
                'pid' => null,
                'build_jobs' => $buildJobs,
                'launched_at' => $launchedAt,
            ]);

            $pid = $this->spawnDetachedWorker($buildJobs);
            $state = $this->readState();

            if (($state['status'] ?? null) === 'launching') {
                $state['pid'] = $pid;
                $this->writeState($state);
            }

            return [
                'started' => true,
                'already_ready' => false,
                'pid' => $pid,
                'log_path' => $this->logPath(),
            ];
        } catch (Throwable $exception) {
            $state = $this->readState();

            if (($state['status'] ?? null) === 'launching') {
                $this->writeState([
                    ...$state,
                    'status' => 'failed',
                    'finished_at' => now()->toIso8601String(),
                    'message' => $exception->getMessage(),
                ]);
            }

            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function runForeground(int $buildJobs = 2, ?callable $output = null): int
    {
        $this->assertBuildJobs($buildJobs);
        $this->assertLinux();
        $this->ensureRuntimeDirectories();
        $emit = static function (string $buffer, string $type = Process::OUT) use ($output): void {
            if ($output !== null) {
                $output($buffer, $type);
            }
        };
        $lock = $this->openLock();
        $locked = false;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }

            usleep(100_000);
        }

        if (! $locked) {
            fclose($lock);
            $emit("[local-voice] FEHLER: Eine Installation laeuft bereits.\n", Process::ERR);

            return 1;
        }

        $startedAt = now()->toIso8601String();

        try {
            if (($this->voice->status()['ready'] ?? false) === true) {
                $this->writeState([
                    'status' => 'complete',
                    'pid' => getmypid(),
                    'build_jobs' => $buildJobs,
                    'started_at' => $startedAt,
                    'finished_at' => now()->toIso8601String(),
                    'message' => 'Die lokale Sprachlaufzeit war bereits vollstaendig bereit.',
                ]);
                $emit("[local-voice] Die lokale Sprachlaufzeit ist bereits vollstaendig bereit.\n");

                return 0;
            }

            $preflightErrors = $this->preflightErrors();

            if ($preflightErrors !== []) {
                throw new RuntimeException(implode(' ', $preflightErrors));
            }

            $this->writeState([
                'status' => 'running',
                'pid' => getmypid(),
                'build_jobs' => $buildJobs,
                'started_at' => $startedAt,
            ]);

            $process = new Process(
                [$this->resolveBashBinary(), $this->scriptPath()],
                base_path(),
                [
                    'PHP_BINARY' => $this->resolvePhpBinary(),
                    'BUILD_JOBS' => (string) $buildJobs,
                ],
            );
            $process->setTimeout(max(300, (int) config(
                'services.local_assistant_voice.install.timeout',
                7200,
            )));
            $idleTimeout = max(0, (int) config(
                'services.local_assistant_voice.install.idle_timeout',
                900,
            ));

            if ($idleTimeout > 0) {
                $process->setIdleTimeout($idleTimeout);
            }

            $process->run(static function (string $type, string $buffer) use ($emit): void {
                $emit($buffer, $type);
            });

            if (! $process->isSuccessful()) {
                throw new RuntimeException(
                    trim($process->getErrorOutput())
                    ?: 'Das Bootstrap-Script endete mit Exit-Code '.($process->getExitCode() ?? 1).'.',
                );
            }

            $this->writeState([
                'status' => 'complete',
                'pid' => getmypid(),
                'build_jobs' => $buildJobs,
                'started_at' => $startedAt,
                'finished_at' => now()->toIso8601String(),
                'exit_code' => 0,
                'message' => 'Whisper und Piper wurden installiert und aktiviert.',
            ]);

            return 0;
        } catch (Throwable $exception) {
            $this->writeState([
                'status' => 'failed',
                'pid' => getmypid(),
                'build_jobs' => $buildJobs,
                'started_at' => $startedAt,
                'finished_at' => now()->toIso8601String(),
                'exit_code' => 1,
                'message' => $exception->getMessage(),
            ]);
            $emit('[local-voice] FEHLER: '.$exception->getMessage()."\n", Process::ERR);

            return 1;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<string> */
    protected function preflightErrors(): array
    {
        $errors = [];

        if (! $this->isLinux()) {
            return ['Die automatische Voice-Installation ist ausschliesslich unter Linux verfuegbar.'];
        }

        if (! is_file($this->scriptPath()) || ! is_readable($this->scriptPath())) {
            $errors[] = 'Das Voice-Bootstrap-Script fehlt oder ist nicht lesbar: '.$this->scriptPath();
        }

        if (! is_file(base_path('.env')) || ! is_writable(base_path('.env'))) {
            $errors[] = 'Die Laravel-.env fehlt oder ist fuer den Domain-Benutzer nicht schreibbar.';
        }

        if (! is_writable(base_path())) {
            $errors[] = 'Das Laravel-Verzeichnis ist fuer den atomaren .env-Austausch nicht schreibbar.';
        }

        foreach ([
            fn (): string => $this->resolvePhpBinary(),
            fn (): string => $this->resolveBashBinary(),
            ...array_map(
                fn (string $command): callable => fn (): string => $this->resolveExecutable($command),
                [
                    'sh',
                    'nohup',
                    'awk',
                    'cmake',
                    'curl',
                    'dirname',
                    'basename',
                    'mkdir',
                    'mktemp',
                    'mv',
                    'rm',
                    'rmdir',
                    'sha256sum',
                    'tar',
                    'uname',
                    'ffmpeg',
                    'python3',
                ],
            ),
        ] as $resolver) {
            try {
                $resolver();
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if (! collect(['c++', 'g++', 'clang++'])->contains(function (string $compiler): bool {
            try {
                $this->resolveExecutable($compiler);

                return true;
            } catch (Throwable) {
                return false;
            }
        })) {
            $errors[] = 'Kein C++-Compiler fuer whisper.cpp gefunden (c++, g++ oder clang++).';
        }

        try {
            $python = $this->resolveExecutable('python3');
            $pythonCheck = new Process([
                $python,
                '-c',
                'import ensurepip, sys, venv; raise SystemExit(0 if sys.version_info >= (3, 9) else 1)',
            ]);
            $pythonCheck->setTimeout(10);
            $pythonCheck->run();

            if (! $pythonCheck->isSuccessful()) {
                $errors[] = 'Python 3.9 oder neuer inklusive venv und ensurepip ist fuer Piper erforderlich (Ubuntu: python3-venv).';
            }
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        return array_values(array_unique($errors));
    }

    protected function spawnDetachedWorker(int $buildJobs): int
    {
        $phpBinary = $this->resolvePhpBinary();
        $command = [
            $phpBinary,
            base_path('artisan'),
            'assistant:voice:install',
            '--foreground',
            '--build-jobs='.$buildJobs,
        ];
        $commandLine = implode(' ', array_map('escapeshellarg', $command));
        $environmentPrefix = 'PHP_BINARY='.escapeshellarg($phpBinary)
            .' BUILD_JOBS='.escapeshellarg((string) $buildJobs);
        $shellCommand = sprintf(
            'cd %1$s && if command -v setsid >/dev/null 2>&1; then %2$s setsid nohup %3$s >> %4$s 2>&1 < /dev/null & echo $!; else %2$s nohup %3$s >> %4$s 2>&1 < /dev/null & echo $!; fi',
            escapeshellarg(base_path()),
            $environmentPrefix,
            $commandLine,
            escapeshellarg($this->logPath()),
        );
        $process = new Process([$this->resolveExecutable('sh'), '-lc', $shellCommand], base_path());
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                trim($process->getErrorOutput())
                ?: 'Der lokale Voice-Installationsprozess konnte nicht gestartet werden.',
            );
        }

        $pid = (int) trim($process->getOutput());

        if ($pid <= 1) {
            throw new RuntimeException('Der Voice-Installationsprozess lieferte keine gueltige PID.');
        }

        return $pid;
    }

    protected function processIsRunning(int $pid): bool
    {
        if (! $this->isLinux() || $pid <= 1) {
            return false;
        }

        try {
            $process = new Process([$this->resolveExecutable('kill'), '-0', (string) $pid]);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    protected function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    protected function resolvePhpBinary(): string
    {
        $configured = trim((string) config(
            'services.local_assistant_voice.install.php_binary',
            '',
        ));
        $pleskVersion = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $candidates = array_values(array_unique(array_filter([
            $configured,
            PHP_BINARY,
            '/opt/plesk/php/'.$pleskVersion.'/bin/php',
            '/usr/bin/php',
            '/usr/local/bin/php',
            (new ExecutableFinder)->find('php'),
        ])));

        foreach ($candidates as $candidate) {
            if (is_file($candidate)
                && is_executable($candidate)
                && ! str_contains(strtolower(basename($candidate)), 'fpm')) {
                return $candidate;
            }
        }

        throw new RuntimeException('Keine ausfuehrbare PHP-CLI fuer die Voice-Installation gefunden.');
    }

    protected function resolveBashBinary(): string
    {
        return $this->resolveExecutable(trim((string) config(
            'services.local_assistant_voice.install.bash_binary',
            'bash',
        )) ?: 'bash');
    }

    protected function resolveExecutable(string $command): string
    {
        $candidate = str_contains($command, DIRECTORY_SEPARATOR)
            ? $command
            : (new ExecutableFinder)->find($command);

        if (! is_string($candidate) || ! is_file($candidate) || ! is_executable($candidate)) {
            throw new RuntimeException('Erforderlicher Befehl wurde nicht gefunden: '.$command);
        }

        return $candidate;
    }

    protected function scriptPath(): string
    {
        return (string) config(
            'services.local_assistant_voice.install.script',
            base_path('scripts/bootstrap-local-assistant-voice.sh'),
        );
    }

    protected function statePath(): string
    {
        return (string) config(
            'services.local_assistant_voice.install.state_path',
            storage_path('app/voice-runtime/install-state.json'),
        );
    }

    protected function lockPath(): string
    {
        return (string) config(
            'services.local_assistant_voice.install.lock_path',
            storage_path('app/voice-runtime/install.lock'),
        );
    }

    protected function logPath(): string
    {
        return (string) config(
            'services.local_assistant_voice.install.log_path',
            storage_path('logs/local-assistant-voice-install.log'),
        );
    }

    /** @return resource */
    private function openLock()
    {
        $handle = @fopen($this->lockPath(), 'c+');

        if ($handle === false) {
            throw new RuntimeException('Die Voice-Installationssperre konnte nicht geoeffnet werden.');
        }

        return $handle;
    }

    private function ensureRuntimeDirectories(): void
    {
        File::ensureDirectoryExists(dirname($this->statePath()), 0750, true);
        File::ensureDirectoryExists(dirname($this->lockPath()), 0750, true);
        File::ensureDirectoryExists(dirname($this->logPath()), 0750, true);
    }

    /** @return array<string, mixed> */
    private function readState(): array
    {
        $path = $this->statePath();

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $state */
    private function writeState(array $state): void
    {
        $state['updated_at'] = now()->toIso8601String();
        $encoded = json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL;

        if (@file_put_contents($this->statePath(), $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Der Voice-Installationsstatus konnte nicht geschrieben werden.');
        }

        @chmod($this->statePath(), 0640);
    }

    private function appendLog(string $message): void
    {
        if (@file_put_contents($this->logPath(), $message, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Das Voice-Installationslog konnte nicht geschrieben werden.');
        }
    }

    private function readLogTail(int $maxBytes = 65_536, int $maxLines = 80): string
    {
        $path = $this->logPath();

        if (! is_file($path) || ! is_readable($path)) {
            return '';
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            $size = max(0, (int) filesize($path));
            $offset = max(0, $size - $maxBytes);
            fseek($handle, $offset);
            $contents = (string) stream_get_contents($handle);

            if ($offset > 0 && ($lineBreak = strpos($contents, "\n")) !== false) {
                $contents = substr($contents, $lineBreak + 1);
            }

            $lines = preg_split('/\R/', trim($contents)) ?: [];

            return implode(PHP_EOL, array_slice($lines, -$maxLines));
        } finally {
            fclose($handle);
        }
    }

    private function assertLinux(): void
    {
        if (! $this->isLinux()) {
            throw new RuntimeException(
                'Die automatische Voice-Installation ist ausschliesslich unter Linux verfuegbar.',
            );
        }
    }

    private function assertBuildJobs(int $buildJobs): void
    {
        if ($buildJobs < 1 || $buildJobs > 16) {
            throw new RuntimeException('BUILD_JOBS muss zwischen 1 und 16 liegen.');
        }
    }
}
