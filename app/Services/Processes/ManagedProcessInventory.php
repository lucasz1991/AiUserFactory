<?php

namespace App\Services\Processes;

use App\Models\ManagedProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class ManagedProcessInventory
{
    private const IDLE_MINUTES = 15;

    private const IDLE_CPU_PERCENT = 0.5;

    public function sync(): array
    {
        $inventory = $this->loadProcessInventory();

        if ($inventory->isEmpty()) {
            return [
                'seen' => 0,
                'managed' => 0,
                'message' => 'Keine Systemprozesse lesbar.',
            ];
        }

        $childrenByParent = $inventory->groupBy('parent_pid');
        $rootPids = $inventory
            ->filter(fn (object $entry): bool => $this->isManagedRootCommand((string) ($entry->command ?? '')))
            ->pluck('pid')
            ->map(fn (mixed $pid): int => (int) $pid)
            ->filter(fn (int $pid): bool => $pid > 0)
            ->values();
        $managedPids = [];
        $familyRootByPid = [];

        foreach ($rootPids as $rootPid) {
            $managedPids[$rootPid] = true;
            $familyRootByPid[$rootPid] = $rootPid;

            foreach ($this->descendantPids($rootPid, $childrenByParent) as $descendantPid) {
                $managedPids[$descendantPid] = true;
                $familyRootByPid[$descendantPid] = $rootPid;
            }
        }

        $now = now();
        $seenPids = [];

        foreach ($inventory->filter(fn (object $entry): bool => isset($managedPids[(int) $entry->pid])) as $entry) {
            $pid = (int) $entry->pid;
            $seenPids[] = $pid;
            $metadata = $this->metadataForCommand((string) ($entry->command ?? ''));
            $elapsedSeconds = max(0, (int) ($entry->elapsed_seconds ?? 0));
            $cpuPercent = is_numeric($entry->cpu_percent ?? null) ? (float) $entry->cpu_percent : null;
            $isIdle = $elapsedSeconds >= self::IDLE_MINUTES * 60
                && $cpuPercent !== null
                && $cpuPercent <= self::IDLE_CPU_PERCENT;

            ManagedProcess::query()->updateOrCreate(
                ['pid' => $pid],
                [
                    'parent_pid' => $this->nullablePositiveInteger($entry->parent_pid ?? null),
                    'family_root_pid' => $familyRootByPid[$pid] ?? $pid,
                    'process_type' => $metadata['process_type'],
                    'executable' => $metadata['executable'],
                    'script_name' => $metadata['script_name'],
                    'command' => (string) ($entry->command ?? ''),
                    'short_command' => $this->shortenCommand((string) ($entry->command ?? '')),
                    'status' => 'running',
                    'is_managed' => true,
                    'is_root' => $rootPids->contains($pid),
                    'is_idle_suspect' => $isIdle,
                    'cpu_percent' => $cpuPercent,
                    'memory_mb' => is_numeric($entry->memory_mb ?? null) ? (float) $entry->memory_mb : null,
                    'elapsed_seconds' => $elapsedSeconds,
                    'started_at' => $entry->started_at ?? null,
                    'detected_at' => ManagedProcess::query()->where('pid', $pid)->value('detected_at') ?: $now,
                    'last_seen_at' => $now,
                    'exited_at' => null,
                    'metadata' => [
                        'platform' => PHP_OS_FAMILY,
                        'state' => $entry->state ?? null,
                        'runtime_config_path' => $metadata['runtime_config_path'],
                        'raw_executable' => $entry->executable ?? null,
                    ],
                ],
            );
        }

        ManagedProcess::query()
            ->where('status', 'running')
            ->whereNotIn('pid', $seenPids ?: [0])
            ->update([
                'status' => 'exited',
                'exited_at' => $now,
                'last_action_at' => $now,
                'action_message' => 'Prozess wurde beim Sync nicht mehr im System gefunden.',
            ]);

        return [
            'seen' => $inventory->count(),
            'managed' => count($seenPids),
            'message' => count($seenPids).' verwaltete Prozesse erkannt.',
        ];
    }

    public function terminate(ManagedProcess $process, bool $force = false): array
    {
        if (! $process->is_managed || $process->pid <= 1) {
            return [
                'ok' => false,
                'message' => 'Dieser Prozess ist nicht als verwaltbarer Followflow-Prozess markiert.',
            ];
        }

        $command = (string) $process->command;

        if (! $this->isManagedCommand($command)) {
            return [
                'ok' => false,
                'message' => 'Der Prozess wurde nicht beendet, weil sein Kommando nicht mehr als Followflow-Prozess erkannt wird.',
            ];
        }

        $process->forceFill([
            'status' => $force ? 'kill_requested' : 'terminate_requested',
            'last_action_at' => now(),
            'action_message' => $force ? 'Beenden erzwingen wurde angefordert.' : 'Beenden wurde angefordert.',
        ])->save();

        $result = PHP_OS_FAMILY === 'Windows'
            ? Process::timeout(10)->run(array_values(array_filter([
                'taskkill',
                '/PID',
                (string) $process->pid,
                '/T',
                $force ? '/F' : null,
            ])))
            : Process::timeout(10)->run([
                'kill',
                '-'.($force ? 'KILL' : 'TERM'),
                (string) $process->pid,
            ]);

        if (! $result->successful()) {
            $message = trim($result->errorOutput() ?: $result->output()) ?: 'Unbekannter Fehler beim Beenden.';
            $process->forceFill([
                'status' => 'running',
                'last_action_at' => now(),
                'action_message' => $message,
            ])->save();

            return [
                'ok' => false,
                'message' => $message,
            ];
        }

        $process->forceFill([
            'status' => $force ? 'killed' : 'terminated',
            'exited_at' => now(),
            'last_action_at' => now(),
            'action_message' => trim($result->output()) ?: ($force ? 'Prozess wurde erzwungen beendet.' : 'Prozess wurde beendet.'),
        ])->save();

        return [
            'ok' => true,
            'message' => 'Prozess '.$process->pid.' wurde '.($force ? 'erzwungen beendet.' : 'beendet.'),
        ];
    }

    public function loadProcessInventory(): Collection
    {
        return PHP_OS_FAMILY === 'Windows'
            ? $this->loadWindowsInventory()
            : $this->loadUnixInventory();
    }

    private function loadWindowsInventory(): Collection
    {
        $script = <<<'POWERSHELL'
Get-CimInstance Win32_Process |
  Select-Object ProcessId,ParentProcessId,Name,CommandLine,WorkingSetSize,CreationDate |
  ConvertTo-Json -Depth 2 -Compress
POWERSHELL;

        $process = Process::timeout(10)->run([
            'powershell',
            '-NoProfile',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            $script,
        ]);

        if (! $process->successful()) {
            Log::warning('Managed process Windows inventory failed.', [
                'error' => trim($process->errorOutput() ?: $process->output()),
            ]);

            return collect();
        }

        $decoded = json_decode(trim($process->output()), true);

        if (! is_array($decoded)) {
            return collect();
        }

        if (array_key_exists('ProcessId', $decoded)) {
            $decoded = [$decoded];
        }

        $now = now();

        return collect($decoded)
            ->map(function (array $entry) use ($now): ?object {
                $pid = (int) ($entry['ProcessId'] ?? 0);

                if ($pid <= 0) {
                    return null;
                }

                $startedAt = $this->parseWindowsDate($entry['CreationDate'] ?? null);

                return (object) [
                    'pid' => $pid,
                    'parent_pid' => (int) ($entry['ParentProcessId'] ?? 0),
                    'state' => null,
                    'cpu_percent' => null,
                    'memory_mb' => isset($entry['WorkingSetSize']) ? round(((float) $entry['WorkingSetSize']) / 1024 / 1024, 2) : null,
                    'elapsed_seconds' => $startedAt ? max(0, $startedAt->diffInSeconds($now)) : 0,
                    'started_at' => $startedAt,
                    'executable' => $entry['Name'] ?? null,
                    'command' => trim((string) ($entry['CommandLine'] ?? $entry['Name'] ?? '')),
                ];
            })
            ->filter()
            ->values();
    }

    private function loadUnixInventory(): Collection
    {
        $process = Process::timeout(10)->run(['sh', '-lc', 'ps -axo pid=,ppid=,etime=,stat=,pcpu=,pmem=,command=']);

        if (! $process->successful()) {
            Log::warning('Managed process Unix inventory failed.', [
                'error' => trim($process->errorOutput() ?: $process->output()),
            ]);

            return collect();
        }

        return collect(preg_split('/\R/u', trim($process->output())) ?: [])
            ->map(fn (string $line): ?object => $this->parseUnixProcessLine($line))
            ->filter()
            ->values();
    }

    private function parseUnixProcessLine(string $line): ?object
    {
        if (! preg_match('/^\s*(\d+)\s+(\d+)\s+(\S+)\s+(\S+)\s+([0-9.]+)\s+([0-9.]+)\s+(.+)$/u', $line, $matches)) {
            return null;
        }

        $elapsedSeconds = $this->parseElapsedSeconds($matches[3]);

        return (object) [
            'pid' => (int) $matches[1],
            'parent_pid' => (int) $matches[2],
            'state' => $matches[4],
            'cpu_percent' => (float) $matches[5],
            'memory_mb' => null,
            'elapsed_seconds' => $elapsedSeconds,
            'started_at' => now()->subSeconds($elapsedSeconds),
            'executable' => null,
            'command' => trim($matches[7]),
        ];
    }

    private function descendantPids(int $pid, Collection $childrenByParent, array &$seen = []): array
    {
        if ($pid <= 0 || isset($seen[$pid])) {
            return [];
        }

        $seen[$pid] = true;
        $descendants = [];

        foreach ($childrenByParent->get($pid, collect()) as $child) {
            $childPid = (int) ($child->pid ?? 0);

            if ($childPid <= 0 || isset($seen[$childPid])) {
                continue;
            }

            $descendants[] = $childPid;
            array_push($descendants, ...$this->descendantPids($childPid, $childrenByParent, $seen));
        }

        return $descendants;
    }

    private function isManagedRootCommand(string $command): bool
    {
        $normalized = Str::of($command)->replace('\\', '/')->lower()->toString();
        $basePath = Str::of(base_path())->replace('\\', '/')->lower()->toString();

        if ($normalized === '') {
            return false;
        }

        if (! preg_match('/\bnode(?:js)?(?:\.exe)?\b/i', $command)) {
            return false;
        }

        return str_contains($normalized, $basePath)
            && preg_match('/\b(mail_account|webmail_session|check_verification_webmail|scrape-instagram)[\w.-]*\.cjs\b/i', $command);
    }

    private function isManagedCommand(string $command): bool
    {
        return $this->isManagedRootCommand($command)
            || preg_match('/\b(chrome|chromium|msedge|cloak|node|nodejs|php)(?:\.exe)?\b/i', $command);
    }

    private function metadataForCommand(string $command): array
    {
        $scriptName = null;

        if (preg_match('/([^\\\\\/\s"\']+\.cjs)\b/i', $command, $matches)) {
            $scriptName = $matches[1];
        } elseif (preg_match('/\bartisan(?:\.php)?\s+([a-z0-9:_-]+)/i', $command, $matches)) {
            $scriptName = 'artisan '.$matches[1];
        }

        $processType = match (true) {
            $scriptName === 'mail_account.cjs' => 'mail-registration',
            is_string($scriptName) && str_starts_with($scriptName, 'webmail_session') => 'webmail-session',
            is_string($scriptName) && str_starts_with($scriptName, 'check_verification_webmail') => 'verification-webmail-check',
            is_string($scriptName) && str_starts_with($scriptName, 'scrape-instagram') => 'instagram-scraper',
            preg_match('/\b(chrome|chromium|msedge|cloak)(?:\.exe)?\b/i', $command) === 1 => 'browser-child',
            preg_match('/\bnode(?:js)?(?:\.exe)?\b/i', $command) === 1 => 'node',
            default => 'app-process',
        };

        return [
            'process_type' => $processType,
            'executable' => $this->commandExecutable($command),
            'script_name' => $scriptName,
            'runtime_config_path' => $this->runtimeConfigPath($command),
        ];
    }

    private function commandExecutable(string $command): ?string
    {
        $command = trim($command);

        if ($command === '') {
            return null;
        }

        if (preg_match('/^"([^"]+)"/', $command, $matches)) {
            return basename($matches[1]);
        }

        return basename(strtok($command, ' ') ?: $command);
    }

    private function runtimeConfigPath(string $command): ?string
    {
        if (preg_match('/([A-Za-z]:[^\s"\']+runtime\.json|\/[^\s"\']+runtime\.json)/i', $command, $matches)) {
            return $matches[1];
        }

        if (preg_match('/([A-Za-z]:[^\s"\']+\.json|\/[^\s"\']+\.json)/i', $command, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function parseElapsedSeconds(string $elapsed): int
    {
        $days = 0;
        $time = $elapsed;

        if (str_contains($elapsed, '-')) {
            [$dayPart, $time] = explode('-', $elapsed, 2);
            $days = max(0, (int) $dayPart);
        }

        $parts = array_map('intval', explode(':', $time));

        if (count($parts) === 2) {
            return ($days * 86400) + ($parts[0] * 60) + $parts[1];
        }

        if (count($parts) === 3) {
            return ($days * 86400) + ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }

        return $days * 86400;
    }

    private function parseWindowsDate(mixed $value): ?Carbon
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        try {
            if (preg_match('/^(\d{14})/', $value, $matches)) {
                return Carbon::createFromFormat('YmdHis', $matches[1]);
            }

            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function shortenCommand(string $command, int $limit = 500): string
    {
        $command = trim(preg_replace('/\s+/', ' ', $command) ?: $command);

        return strlen($command) > $limit ? substr($command, 0, $limit - 20).'... [truncated]' : $command;
    }

    private function nullablePositiveInteger(mixed $value): ?int
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
