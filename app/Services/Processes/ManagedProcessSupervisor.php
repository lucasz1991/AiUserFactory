<?php

namespace App\Services\Processes;

use App\Models\ManagedProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

class ManagedProcessSupervisor
{
    public function __construct(
        protected ManagedProcessInventory $inventory,
    ) {}

    public function supervise(?string $runId = null): array
    {
        $this->inventory->sync();

        if (! Schema::hasTable('managed_processes') || ! Schema::hasColumn('managed_processes', 'run_id')) {
            return [
                'checked' => 0,
                'restarted' => 0,
                'message' => 'Prozess-Supervisor wartet auf die erweiterten Prozess-Spalten.',
            ];
        }

        $query = ManagedProcess::query()
            ->where('is_root', true)
            ->whereIn('run_type', ['mail-registration', 'webmail-session'])
            ->whereIn('status', ['running', 'exited'])
            ->whereNotNull('runtime_config_path');

        if ($runId !== null && trim($runId) !== '') {
            $query->where('run_id', trim($runId));
        }

        $checked = 0;
        $restarted = 0;

        foreach ($query->latest('last_seen_at')->limit(50)->get() as $process) {
            $checked++;

            if (! $this->shouldRestart($process)) {
                $process->forceFill([
                    'supervisor_checked_at' => now(),
                ])->save();

                continue;
            }

            if ($this->restart($process)) {
                $restarted++;
            }
        }

        return [
            'checked' => $checked,
            'restarted' => $restarted,
            'message' => $restarted > 0
                ? $restarted.' Prozess(e) wurden neu gestartet.'
                : 'Keine haengenden Prozesse gefunden.',
        ];
    }

    protected function shouldRestart(ManagedProcess $process): bool
    {
        $runtime = $this->readJsonFile((string) $process->runtime_config_path);
        $status = $this->readJsonFile((string) $process->status_path);
        $runState = trim((string) ($status['state'] ?? ''));

        if (! in_array($runState, ['queued', 'starting', 'running'], true)) {
            return false;
        }

        if (($runtime['supervisor']['enabled'] ?? true) === false) {
            return false;
        }

        $maxRestarts = max(0, (int) data_get($runtime, 'supervisor.maxRestarts', 2));

        if ((int) $process->restart_count >= $maxRestarts) {
            return false;
        }

        $staleAfterSeconds = $this->staleAfterSeconds($runtime);
        $heartbeatAt = $this->heartbeatAt($process, $status);
        $heartbeatAge = $heartbeatAt ? $heartbeatAt->diffInSeconds(now()) : null;

        if ($process->status === 'exited') {
            return true;
        }

        if ($heartbeatAge === null || $heartbeatAge > $staleAfterSeconds) {
            if ($process->last_restart_at && $process->last_restart_at->diffInSeconds(now()) < $staleAfterSeconds) {
                return false;
            }

            return true;
        }

        return false;
    }

    protected function restart(ManagedProcess $process): bool
    {
        $runtimeConfigPath = (string) $process->runtime_config_path;
        $runtime = $this->readJsonFile($runtimeConfigPath);
        $scriptPath = $this->resolveScriptPath($process, $runtime);

        if ($scriptPath === null || ! File::exists($scriptPath) || ! File::exists($runtimeConfigPath)) {
            $process->forceFill([
                'supervisor_checked_at' => now(),
                'last_action_at' => now(),
                'action_message' => 'Supervisor konnte Script oder Runtime-Konfiguration nicht finden.',
            ])->save();

            return false;
        }

        try {
            if ($process->status === 'running') {
                $this->stopProcessTree((int) $process->pid);
            }

            $this->writeSupervisorStatus((string) $process->status_path, $process);

            $runDirectory = dirname($runtimeConfigPath);
            $timestamp = now()->format('YmdHis');
            $stdoutPath = $runDirectory.DIRECTORY_SEPARATOR.'supervisor-'.$timestamp.'.stdout.log';
            $stderrPath = $runDirectory.DIRECTORY_SEPARATOR.'supervisor-'.$timestamp.'.stderr.log';
            $pid = $this->spawnDetachedProcess([
                $this->resolveNodeBinary(),
                $scriptPath,
                $runtimeConfigPath,
            ], base_path(), $stdoutPath, $stderrPath);
        } catch (\Throwable $exception) {
            $process->forceFill([
                'supervisor_checked_at' => now(),
                'last_action_at' => now(),
                'action_message' => 'Supervisor-Restart fehlgeschlagen: '.$exception->getMessage(),
            ])->save();

            return false;
        }

        $process->forceFill([
            'status' => 'restarted',
            'last_restart_at' => now(),
            'supervisor_checked_at' => now(),
            'last_action_at' => now(),
            'restart_count' => ((int) $process->restart_count) + 1,
            'action_message' => $pid
                ? 'Supervisor hat den Run mit neuer PID '.$pid.' neu gestartet.'
                : 'Supervisor hat den Run neu gestartet.',
        ])->save();

        return true;
    }

    protected function writeSupervisorStatus(string $statusPath, ManagedProcess $process): void
    {
        if ($statusPath === '') {
            return;
        }

        $status = $this->readJsonFile($statusPath);
        $events = is_array($status['events'] ?? null) ? $status['events'] : [];
        $events[] = [
            'at' => now()->toIso8601String(),
            'stage' => 'supervisor-restarting',
            'message' => 'Supervisor startet den Node-Prozess neu.',
            'previousPid' => $process->pid,
            'restartCount' => ((int) $process->restart_count) + 1,
        ];

        if (count($events) > 80) {
            $events = array_slice($events, -80);
        }

        $status['state'] = 'starting';
        $status['stage'] = 'supervisor-restarting';
        $status['message'] = 'Supervisor startet den Node-Prozess neu.';
        $status['at'] = now()->toIso8601String();
        $status['heartbeatAt'] = now()->toIso8601String();
        $status['events'] = $events;

        $this->writeJsonFile($statusPath, $status);
    }

    protected function resolveScriptPath(ManagedProcess $process, array $runtime): ?string
    {
        return match ($process->run_type) {
            'mail-registration' => base_path('resources/node/register/mail_account.cjs'),
            'webmail-session' => base_path('resources/node/session/'.($this->webmailProvider($runtime) === 'gmx' ? 'webmail_session_gmx.cjs' : 'webmail_session_proton.cjs')),
            default => null,
        };
    }

    protected function webmailProvider(array $runtime): string
    {
        $provider = strtolower(trim((string) ($runtime['provider'] ?? data_get($runtime, 'verificationMailbox.provider', 'proton'))));

        return str_contains($provider, 'gmx') ? 'gmx' : 'proton';
    }

    protected function staleAfterSeconds(array $runtime): int
    {
        $configured = (int) data_get($runtime, 'supervisor.staleAfterSeconds', 0);
        $interval = (int) ($runtime['livePreviewIntervalSeconds'] ?? 3);

        return max(30, $configured > 0 ? $configured : ($interval * 5));
    }

    protected function heartbeatAt(ManagedProcess $process, array $status): ?Carbon
    {
        if ($process->heartbeat_at) {
            return $process->heartbeat_at;
        }

        $raw = $status['heartbeatAt'] ?? $status['at'] ?? null;

        if (! is_scalar($raw) || trim((string) $raw) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $raw);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function stopProcessTree(int $pid): void
    {
        if ($pid <= 1) {
            return;
        }

        $result = PHP_OS_FAMILY === 'Windows'
            ? Process::timeout(10)->run(['taskkill', '/PID', (string) $pid, '/T', '/F'])
            : Process::timeout(10)->run(['kill', '-KILL', (string) $pid]);

        if (! $result->successful()) {
            Log::warning('Managed process supervisor could not stop stale process.', [
                'pid' => $pid,
                'message' => trim($result->errorOutput() ?: $result->output()),
            ]);
        }
    }

    protected function readJsonFile(string $path): array
    {
        try {
            if ($path === '' || ! File::exists($path)) {
                return [];
            }

            $decoded = json_decode(File::get($path), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    protected function writeJsonFile(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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

        $binary = trim(strtok($resolved->output(), "\r\n") ?: '');

        if ($resolved->successful() && $binary !== '') {
            return $binary;
        }

        throw new \RuntimeException('Node.js wurde fuer den Prozess-Supervisor nicht gefunden.');
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
            throw new \RuntimeException(trim($result->errorOutput()) ?: 'Der Node-Prozess konnte nicht neu gestartet werden.');
        }

        $pid = (int) trim($result->output());

        return $pid > 0 ? $pid : null;
    }

    protected function powershellQuote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
