<?php

namespace App\Services\Processes;

use App\Models\ManagedProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
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
        $entriesByPid = $inventory->keyBy('pid');
        $rootPids = $inventory
            ->filter(fn (object $entry): bool => $this->isManagedRootCommand((string) ($entry->command ?? '')))
            ->pluck('pid')
            ->map(fn (mixed $pid): int => (int) $pid)
            ->filter(fn (int $pid): bool => $pid > 0)
            ->values();
        $managedPids = [];
        $familyRootByPid = [];
        $rootMetadataByPid = [];

        foreach ($rootPids as $rootPid) {
            $managedPids[$rootPid] = true;
            $familyRootByPid[$rootPid] = $rootPid;
            $rootEntry = $entriesByPid->get($rootPid);
            $rootMetadataByPid[$rootPid] = $rootEntry
                ? $this->metadataForCommand((string) ($rootEntry->command ?? ''))
                : [];

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
            $rootPid = $familyRootByPid[$pid] ?? $pid;
            $commandMetadata = $this->metadataForCommand((string) ($entry->command ?? ''));
            $rootMetadata = $rootMetadataByPid[$rootPid] ?? [];
            $runtimeConfigPath = $commandMetadata['runtime_config_path'] ?: ($rootMetadata['runtime_config_path'] ?? null);
            $runtimeContext = $this->runtimeProcessContext($runtimeConfigPath, $pid === $rootPid ? 'main' : 'child');
            $metadata = [
                ...$commandMetadata,
                'runtime_config_path' => $runtimeConfigPath,
                'root_runtime_config_path' => $rootMetadata['runtime_config_path'] ?? null,
                'status_path' => $runtimeContext['status_path'],
                'process_identity' => $runtimeContext['process_identity'],
                'workflow_context' => $runtimeContext['workflow_context'],
                'subject_person_id' => $runtimeContext['person_id'],
                'status_state' => $runtimeContext['status_state'],
                'status_stage' => $runtimeContext['last_stage'],
            ];
            $elapsedSeconds = max(0, (int) ($entry->elapsed_seconds ?? 0));
            $cpuPercent = is_numeric($entry->cpu_percent ?? null) ? (float) $entry->cpu_percent : null;
            $isIdle = $elapsedSeconds >= self::IDLE_MINUTES * 60
                && $cpuPercent !== null
                && $cpuPercent <= self::IDLE_CPU_PERCENT;
            $payload = [
                'parent_pid' => $this->nullablePositiveInteger($entry->parent_pid ?? null),
                'family_root_pid' => $rootPid,
                'process_type' => $metadata['process_type'] ?? 'app-process',
                'executable' => $metadata['executable'] ?? null,
                'script_name' => $metadata['script_name'] ?? null,
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
                    'runtime_config_path' => $runtimeConfigPath,
                    'raw_executable' => $entry->executable ?? null,
                    'process_identity' => $runtimeContext['process_identity'],
                    'workflow_context' => $runtimeContext['workflow_context'],
                    'subject_person_id' => $runtimeContext['person_id'],
                    'status_state' => $runtimeContext['status_state'],
                    'status_stage' => $runtimeContext['last_stage'],
                    'heartbeat_at' => $runtimeContext['heartbeat_at']?->toIso8601String(),
                ],
            ];

            foreach ([
                'process_key' => $runtimeContext['process_key'],
                'run_id' => $runtimeContext['run_id'],
                'run_type' => $runtimeContext['run_type'],
                'process_role' => $runtimeContext['process_role'],
                'runtime_config_path' => $runtimeConfigPath,
                'status_path' => $runtimeContext['status_path'],
                'heartbeat_at' => $runtimeContext['heartbeat_at'],
                'heartbeat_age_seconds' => $runtimeContext['heartbeat_age_seconds'],
                'last_stage' => $runtimeContext['last_stage'],
                'last_message' => $runtimeContext['last_message'],
            ] as $column => $value) {
                if ($this->managedProcessHasColumn($column)) {
                    $payload[$column] = $value;
                }
            }

            ManagedProcess::query()->updateOrCreate(
                ['pid' => $pid],
                $payload,
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

        $targetProcesses = $this->managedProcessFamily($process);
        $targetPids = $targetProcesses
            ->pluck('pid')
            ->map(fn (mixed $pid): int => (int) $pid)
            ->filter(fn (int $pid): bool => $pid > 1)
            ->unique()
            ->values();

        if ($targetPids->isEmpty()) {
            $targetPids = collect([(int) $process->pid]);
        }

        $failureMessage = $this->terminatePids($targetPids, (int) ($process->family_root_pid ?: $process->pid), $force);

        if ($failureMessage !== null) {
            $process->forceFill([
                'status' => 'running',
                'last_action_at' => now(),
                'action_message' => $failureMessage,
            ])->save();

            return [
                'ok' => false,
                'message' => $failureMessage,
            ];
        }

        ManagedProcess::query()
            ->whereIn('pid', $targetPids->all())
            ->update([
                'status' => $force ? 'killed' : 'terminated',
                'exited_at' => now(),
                'last_action_at' => now(),
                'action_message' => $force ? 'Prozessfamilie wurde erzwungen beendet.' : 'Prozessfamilie wurde beendet.',
            ]);

        $process->forceFill([
            'status' => $force ? 'killed' : 'terminated',
            'exited_at' => now(),
            'last_action_at' => now(),
            'action_message' => $force ? 'Prozessfamilie wurde erzwungen beendet.' : 'Prozessfamilie wurde beendet.',
        ])->save();

        $count = $targetPids->count();

        return [
            'ok' => true,
            'message' => $count > 1
                ? $count.' zugehoerige Prozesse wurden '.($force ? 'erzwungen beendet.' : 'beendet.')
                : 'Prozess '.$process->pid.' wurde '.($force ? 'erzwungen beendet.' : 'beendet.'),
        ];
    }

    protected function managedProcessFamily(ManagedProcess $process): Collection
    {
        $rootPid = (int) ($process->family_root_pid ?: $process->pid);

        if ($rootPid <= 1) {
            return collect([$process]);
        }

        $family = ManagedProcess::query()
            ->where(function ($query) use ($rootPid): void {
                $query->where('family_root_pid', $rootPid)
                    ->orWhere('pid', $rootPid);
            })
            ->whereIn('status', ['running', 'terminate_requested', 'kill_requested'])
            ->orderByRaw('CASE WHEN pid = ? THEN 1 ELSE 0 END', [$rootPid])
            ->get();

        return $family->isNotEmpty() ? $family : collect([$process]);
    }

    protected function terminatePids(Collection $pids, int $rootPid, bool $force): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $result = Process::timeout(10)->run(array_values(array_filter([
                'taskkill',
                '/PID',
                (string) $rootPid,
                '/T',
                $force ? '/F' : null,
            ])));

            return $result->successful()
                ? null
                : (trim($result->errorOutput() ?: $result->output()) ?: 'Unbekannter Fehler beim Beenden.');
        }

        $this->terminateUnixProcessTree($pids, $rootPid, $force);

        foreach ($pids as $pid) {
            $result = Process::timeout(10)->run([
                'kill',
                '-'.($force ? 'KILL' : 'TERM'),
                (string) $pid,
            ]);

            if (! $result->successful()) {
                $message = trim($result->errorOutput() ?: $result->output());

                if (! str_contains(Str::lower($message), 'no such process')) {
                    return $message ?: 'Unbekannter Fehler beim Beenden.';
                }
            }
        }

        return null;
    }

    protected function terminateUnixProcessTree(Collection $pids, int $rootPid, bool $force): void
    {
        $signal = $force ? 'KILL' : 'TERM';
        $pidList = $pids
            ->map(fn (mixed $pid): int => (int) $pid)
            ->filter(fn (int $pid): bool => $pid > 1)
            ->unique()
            ->implode(' ');

        if ($pidList === '') {
            return;
        }

        $script = <<<'SH'
signal="$1"
root="$2"
shift 2
kill_tree() {
  current="$1"
  if command -v pgrep >/dev/null 2>&1; then
    for child in $(pgrep -P "$current" 2>/dev/null); do
      kill_tree "$child"
    done
  fi
  kill "-$signal" "$current" 2>/dev/null || true
}
for pid in "$@"; do
  kill_tree "$pid"
done
if [ "$root" -gt 1 ] 2>/dev/null; then
  kill "-$signal" "-$root" 2>/dev/null || true
fi
SH;

        Process::timeout(10)->run(['sh', '-lc', $script, 'sh', $signal, (string) $rootPid, ...explode(' ', $pidList)]);
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
            && preg_match('/\b(mail_account|webmail_session|check_verification_webmail|run_step|scrape-instagram)[\w.-]*\.cjs\b/i', $command);
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
            $scriptName === 'run_step.cjs' => 'workflow-task',
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

    private function runtimeProcessContext(?string $runtimeConfigPath, string $fallbackRole): array
    {
        $runtimeConfigPath = trim((string) $runtimeConfigPath) ?: null;
        $runtimeConfig = $runtimeConfigPath ? $this->readJsonFile($runtimeConfigPath) : [];
        $statusPath = trim((string) data_get($runtimeConfig, 'statusPath')) ?: null;
        $status = $statusPath ? $this->readJsonFile($statusPath) : [];
        $identity = data_get($status, 'processIdentity');

        if (! is_array($identity) || $identity === []) {
            $identity = data_get($runtimeConfig, 'processIdentity');
        }

        $identity = is_array($identity) ? $identity : [];
        $workflowContext = data_get($status, 'workflow');

        if (! is_array($workflowContext) || $workflowContext === []) {
            $workflowContext = data_get($runtimeConfig, 'workflow');
        }

        $workflowContext = is_array($workflowContext) ? $workflowContext : [];
        $runId = trim((string) (data_get($identity, 'runId') ?: data_get($status, 'runId') ?: data_get($runtimeConfig, 'runId')));
        $runType = trim((string) (data_get($identity, 'runType') ?: $this->runTypeFromRuntimePath($runtimeConfigPath)));
        $role = trim((string) (data_get($identity, 'role') ?: $fallbackRole));
        $personId = (int) (
            data_get($identity, 'personId')
            ?: data_get($runtimeConfig, 'subject.personId')
            ?: data_get($runtimeConfig, 'subject.person_id')
            ?: data_get($runtimeConfig, 'personId')
            ?: 0
        );
        $processKey = trim((string) (data_get($identity, 'processKey') ?: data_get($status, 'processKey')));

        if ($processKey === '' && $runId !== '' && $runType !== '') {
            $processKey = $runType.':'.$runId.':'.$role;
        }

        $heartbeatAt = $this->parseTimestamp(data_get($status, 'heartbeatAt') ?: data_get($status, 'at'));

        if (! $heartbeatAt && $statusPath && File::exists($statusPath)) {
            $heartbeatAt = Carbon::createFromTimestamp(File::lastModified($statusPath));
        }

        return [
            'process_key' => $processKey ?: null,
            'run_id' => $runId ?: null,
            'run_type' => $runType ?: null,
            'process_role' => $role ?: null,
            'runtime_config_path' => $runtimeConfigPath,
            'status_path' => $statusPath,
            'process_identity' => $identity ?: null,
            'workflow_context' => $workflowContext ?: null,
            'person_id' => $personId > 0 ? $personId : null,
            'heartbeat_at' => $heartbeatAt,
            'heartbeat_age_seconds' => $heartbeatAt ? (int) $heartbeatAt->diffInSeconds(now()) : null,
            'status_state' => trim((string) data_get($status, 'state')) ?: null,
            'last_stage' => trim((string) data_get($status, 'stage')) ?: null,
            'last_message' => trim((string) data_get($status, 'message')) ?: null,
        ];
    }

    private function runTypeFromRuntimePath(?string $runtimeConfigPath): ?string
    {
        $path = Str::of((string) $runtimeConfigPath)->replace('\\', '/')->lower()->toString();

        return match (true) {
            str_contains($path, '/mail-registration/') => 'mail-registration',
            str_contains($path, '/webmail-session/') => 'webmail-session',
            str_contains($path, '/workflow-task-runs/') => 'workflow-task',
            default => null,
        };
    }

    private function readJsonFile(string $path): array
    {
        try {
            if (! File::exists($path)) {
                return [];
            }

            $decoded = json_decode(File::get($path), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function parseTimestamp(mixed $value): ?Carbon
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

    private function managedProcessHasColumn(string $column): bool
    {
        static $columns = null;

        if ($columns === null) {
            $columns = Schema::hasTable('managed_processes')
                ? array_flip(Schema::getColumnListing('managed_processes'))
                : [];
        }

        return isset($columns[$column]);
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
