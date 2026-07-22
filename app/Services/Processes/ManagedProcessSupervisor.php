<?php

namespace App\Services\Processes;

use App\Models\ManagedProcess;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ManagedProcessSupervisor
{
    private const OBSOLETE_FINAL_GRACE_SECONDS = 90;

    private const ORPHAN_RUNNING_GRACE_SECONDS = 180;

    // Obergrenze, wie lange ein geparkter Keep-Alive-Browser-Prozess ohne
    // Fortschritt (keine StepRun-Aktivitaet, kein Status-Update) geschuetzt
    // bleibt. Danach behandelt der Reaper ihn als verwaist und beendet ihn.
    // Bewusst laenger als das node-seitige Selbst-Aufraeum-Limit (Default
    // 15 Min), damit der Prozess sich normalerweise selbst beendet und der
    // Reaper nur der Rueckfall fuer haengende/verwaiste Faelle ist.
    private const KEEP_ALIVE_MAX_IDLE_SECONDS = 1800;

    public function __construct(
        protected ManagedProcessInventory $inventory,
    ) {}

    public function supervise(?string $runId = null, bool $force = false): array
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
        $terminated = 0;

        foreach ($query->latest('last_seen_at')->limit(50)->get() as $process) {
            $checked++;

            if (! $this->shouldRestart($process, $force)) {
                $process->forceFill([
                    'supervisor_checked_at' => now(),
                ])->save();

                continue;
            }

            if ($this->restart($process)) {
                $restarted++;
            }
        }

        if ($checked === 0 && $force && $runId !== null && trim($runId) !== '') {
            $checked++;

            if ($this->restartMissingRun(trim($runId))) {
                $restarted++;
            }
        }

        $terminated = $this->cleanupObsoleteProcesses($runId);

        return [
            'checked' => $checked,
            'restarted' => $restarted,
            'terminated' => $terminated,
            'message' => $restarted > 0
                ? $restarted.' Prozess(e) wurden neu gestartet.'
                : ($terminated > 0
                    ? $terminated.' nicht aktuelle Prozessfamilie(n) wurden beendet.'
                    : 'Keine haengenden Prozesse gefunden.'),
        ];
    }

    protected function cleanupObsoleteProcesses(?string $runId = null): int
    {
        $query = ManagedProcess::query()
            ->where('is_root', true)
            ->whereIn('status', ['running', 'terminate_requested', 'kill_requested'])
            ->whereIn('run_type', ['mail-registration', 'webmail-session', 'workflow-task'])
            ->whereNotNull('run_id');

        if ($runId !== null && trim($runId) !== '') {
            $query->where('run_id', trim($runId));
        }

        $terminated = 0;

        foreach ($query->latest('last_seen_at')->limit(100)->get() as $process) {
            if (! $this->shouldTerminateObsoleteProcess($process)) {
                $process->forceFill([
                    'supervisor_checked_at' => now(),
                ])->save();

                continue;
            }

            $this->terminateProcessFamily($process, true, 'Prozessfamilie ist keinem aktuellen Workflow-Task mehr zugeordnet.');
            $terminated++;
        }

        return $terminated;
    }

    protected function shouldTerminateObsoleteProcess(ManagedProcess $process): bool
    {
        $runType = trim((string) $process->run_type);
        $externalRunId = trim((string) $process->run_id);

        if ($runType !== 'workflow-task' || $externalRunId === '') {
            $status = $this->readJsonFile((string) $process->status_path);
            $state = trim((string) ($status['state'] ?? $status['status'] ?? ''));

            return in_array($runType, ['mail-registration', 'webmail-session'], true)
                && in_array($state, ['completed', 'failed', 'cancelled'], true)
                && $this->statusAgeSeconds($process, $status) >= self::OBSOLETE_FINAL_GRACE_SECONDS;
        }

        $status = $this->readJsonFile((string) $process->status_path);

        // A browser-owning process belongs to the complete workflow run. It
        // must not be treated as an orphan merely because its originating
        // step/list has already completed or an embedded workflow is active.
        if ($this->isKeepingBrowserForActiveWorkflow($process, $status)) {
            return false;
        }

        $activeStepRun = WorkflowStepRun::query()
            ->with('workflowRun')
            ->where('external_run_type', 'workflow-task')
            ->where('external_run_id', $externalRunId)
            ->whereIn('status', ['running', 'waiting'])
            ->first();

        if (! $activeStepRun) {
            $state = trim((string) ($status['state'] ?? $status['status'] ?? ''));

            if (in_array($state, ['completed', 'failed', 'cancelled'], true)) {
                return $this->statusAgeSeconds($process, $status) >= self::OBSOLETE_FINAL_GRACE_SECONDS;
            }

            return (int) $process->elapsed_seconds >= self::ORPHAN_RUNNING_GRACE_SECONDS;
        }

        if (
            $activeStepRun->workflowRun
            && in_array((string) $activeStepRun->workflowRun->status, ['queued', 'running', 'waiting', 'stop_requested', 'unreachable'], true)
        ) {
            return false;
        }

        return $this->statusAgeSeconds($process, $status) >= self::OBSOLETE_FINAL_GRACE_SECONDS;
    }

    protected function isKeepingBrowserForActiveWorkflow(ManagedProcess $process, array $status): bool
    {
        $state = trim((string) ($status['state'] ?? $status['status'] ?? ''));
        $stage = trim((string) ($status['stage'] ?? ''));

        if (! in_array($state, ['completed', 'running'], true) || ! str_contains($stage, 'browser-kept-active')) {
            return false;
        }

        $workflowRunId = (int) (
            data_get($status, 'workflow.workflowRunId')
            ?: data_get($status, 'workflowRunId')
            ?: data_get($process->metadata, 'workflow_context.workflowRunId')
            ?: data_get($process->metadata, 'process_identity.workflowRunId')
            ?: 0
        );

        if ($workflowRunId <= 0) {
            return false;
        }

        $run = WorkflowRun::query()->find($workflowRunId);

        if (! $run || in_array((string) $run->status, ['completed', 'failed', 'cancelled', 'timed_out', 'lost'], true)) {
            return false;
        }

        $hasWindows = false;

        foreach ([
            data_get($run->context_json, 'browser_windows'),
            data_get($run->context_json, 'browserWindows'),
            data_get($status, 'browserWindows'),
            data_get($status, 'result.browserWindows'),
        ] as $windows) {
            if (is_array($windows) && $windows !== []) {
                $hasWindows = true;

                break;
            }
        }

        if (! $hasWindows) {
            return false;
        }

        // Idle-TTL: Ein geparkter Browser bleibt nur geschuetzt, solange der Lauf
        // in den letzten KEEP_ALIVE_MAX_IDLE_SECONDS Fortschritt hatte. Ohne diese
        // Grenze lebten Keep-Alive-Prozesse wartender/pausierter Laeufe unbegrenzt
        // weiter und fuellten den Server-RAM.
        return $this->keepAliveIdleSeconds($process, $status, $run) < self::KEEP_ALIVE_MAX_IDLE_SECONDS;
    }

    /**
     * Sekunden seit dem juengsten Aktivitaetssignal des Laufs (Status-Update,
     * Prozess-Heartbeat, letzte StepRun-Aenderung). Je kleiner, desto frischer.
     */
    protected function keepAliveIdleSeconds(ManagedProcess $process, array $status, WorkflowRun $run): int
    {
        $now = now();
        $newest = null;

        $consider = static function (mixed $timestamp) use (&$newest): void {
            if (! $timestamp) {
                return;
            }

            $carbon = $timestamp instanceof Carbon ? $timestamp : Carbon::parse($timestamp);

            if ($newest === null || $carbon->greaterThan($newest)) {
                $newest = $carbon;
            }
        };

        $consider($this->parseStatusTimestamp($status['finishedAt'] ?? $status['finished_at'] ?? $status['at'] ?? null));
        $consider($process->heartbeat_at);
        $consider($process->last_seen_at);
        $consider($run->updated_at);
        $consider(
            WorkflowStepRun::query()
                ->where('workflow_run_id', $run->id)
                ->max('updated_at')
        );

        if ($newest === null) {
            $consider($process->started_at);
        }

        if ($newest === null) {
            return self::KEEP_ALIVE_MAX_IDLE_SECONDS;
        }

        return max(0, $newest->diffInSeconds($now));
    }

    protected function statusAgeSeconds(ManagedProcess $process, array $status): int
    {
        $timestamp = $this->parseStatusTimestamp($status['finishedAt'] ?? $status['finished_at'] ?? $status['at'] ?? null);

        if (! $timestamp && $process->heartbeat_at) {
            $timestamp = $process->heartbeat_at;
        }

        if (! $timestamp && $process->started_at) {
            $timestamp = $process->started_at;
        }

        return $timestamp ? max(0, (int) $timestamp->diffInSeconds(now())) : (int) $process->elapsed_seconds;
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

    protected function shouldRestart(ManagedProcess $process, bool $force = false): bool
    {
        if ($this->hasActiveSiblingForRun($process)) {
            return false;
        }

        $runtime = $this->readJsonFile((string) $process->runtime_config_path);
        $status = $this->readJsonFile((string) $process->status_path);
        $runState = trim((string) ($status['state'] ?? ''));
        $profileLockFailure = $this->isBrowserProfileLockFailure($status);

        if (! in_array($runState, ['queued', 'starting', 'running'], true)) {
            if (! ($force && $profileLockFailure)) {
                return false;
            }
        }

        if (($runtime['supervisor']['enabled'] ?? true) === false) {
            return false;
        }

        $maxRestarts = max(0, (int) data_get($runtime, 'supervisor.maxRestarts', 2));

        if ((int) $process->restart_count >= $maxRestarts) {
            return false;
        }

        if ($force || $profileLockFailure) {
            return true;
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

    protected function hasActiveSiblingForRun(ManagedProcess $process): bool
    {
        if (! $process->run_id) {
            return false;
        }

        return ManagedProcess::query()
            ->where('id', '!=', $process->id)
            ->where('run_id', $process->run_id)
            ->where('is_root', true)
            ->whereIn('status', ['running', 'terminate_requested', 'kill_requested'])
            ->exists();
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

            $profileRotation = $this->rotateLockedBrowserProfile($runtimeConfigPath, $runtime, (string) $process->status_path);
            $this->writeSupervisorStatus((string) $process->status_path, $process, $profileRotation);

            $runDirectory = dirname($runtimeConfigPath);
            $timestamp = now()->format('YmdHis');
            $stdoutPath = $runDirectory.DIRECTORY_SEPARATOR.'supervisor-'.$timestamp.'.stdout.log';
            $stderrPath = $runDirectory.DIRECTORY_SEPARATOR.'supervisor-'.$timestamp.'.stderr.log';
            $pid = $this->spawnDetachedProcess([
                $this->resolveNodeBinary(),
                $scriptPath,
                $runtimeConfigPath,
            ], base_path(), $stdoutPath, $stderrPath, $this->nodeProcessEnvironment($runtime));
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

    protected function writeSupervisorStatus(string $statusPath, ManagedProcess $process, array $profileRotation = []): void
    {
        if ($statusPath === '') {
            return;
        }

        $status = $this->readJsonFile($statusPath);
        $profileRotated = (bool) ($profileRotation['rotated'] ?? false);
        $message = $profileRotated
            ? 'Supervisor startet den Node-Prozess mit neuem Browser-Profilordner neu.'
            : 'Supervisor startet den Node-Prozess neu.';
        $events = is_array($status['events'] ?? null) ? $status['events'] : [];
        $events[] = [
            'at' => now()->toIso8601String(),
            'stage' => 'supervisor-restarting',
            'message' => $message,
            'previousPid' => $process->pid,
            'restartCount' => ((int) $process->restart_count) + 1,
            'previousBrowserProfilePath' => $profileRotation['previousBrowserProfilePath'] ?? null,
            'browserProfilePath' => $profileRotation['browserProfilePath'] ?? null,
        ];

        if (count($events) > 80) {
            $events = array_slice($events, -80);
        }

        $status['state'] = 'starting';
        $status['stage'] = 'supervisor-restarting';
        $status['message'] = $message;
        $status['at'] = now()->toIso8601String();
        $status['heartbeatAt'] = now()->toIso8601String();
        $status['previousBrowserProfilePath'] = $profileRotation['previousBrowserProfilePath'] ?? ($status['previousBrowserProfilePath'] ?? null);
        $status['browserProfilePath'] = $profileRotation['browserProfilePath'] ?? ($status['browserProfilePath'] ?? null);
        $status['events'] = $events;

        $this->writeJsonFile($statusPath, $status);
    }

    protected function restartMissingRun(string $runId): bool
    {
        foreach ($this->runtimeCandidatesForRun($runId) as $runType => $runtimeConfigPath) {
            if (! File::exists($runtimeConfigPath)) {
                continue;
            }

            $runtime = $this->readJsonFile($runtimeConfigPath);
            $statusPath = trim((string) ($runtime['statusPath'] ?? dirname($runtimeConfigPath).DIRECTORY_SEPARATOR.'status.json'));
            $status = $this->readJsonFile($statusPath);

            if (! $this->isBrowserProfileLockFailure($status)) {
                continue;
            }

            $maxRestarts = max(0, (int) data_get($runtime, 'supervisor.maxRestarts', 2));
            $restartCount = (int) ($status['supervisorRestartCount'] ?? 0);

            if ($restartCount >= $maxRestarts) {
                continue;
            }

            $scriptPath = $this->resolveScriptPathForRunType($runType, $runtime);

            if ($scriptPath === null || ! File::exists($scriptPath)) {
                continue;
            }

            try {
                $profileRotation = $this->rotateLockedBrowserProfile($runtimeConfigPath, $runtime, $statusPath);
                $this->writeMissingRunSupervisorStatus($statusPath, $status, $profileRotation);

                $runDirectory = dirname($runtimeConfigPath);
                $timestamp = now()->format('YmdHis');
                $stdoutPath = $runDirectory.DIRECTORY_SEPARATOR.'supervisor-'.$timestamp.'.stdout.log';
                $stderrPath = $runDirectory.DIRECTORY_SEPARATOR.'supervisor-'.$timestamp.'.stderr.log';
                $pid = $this->spawnDetachedProcess([
                    $this->resolveNodeBinary(),
                    $scriptPath,
                    $runtimeConfigPath,
                ], base_path(), $stdoutPath, $stderrPath, $this->nodeProcessEnvironment($runtime));

                $latestStatus = $this->readJsonFile($statusPath);
                $latestStatus['pid'] = $pid;
                $latestStatus['supervisorRestartCount'] = $restartCount + 1;
                $latestStatus['supervisorRestartedAt'] = now()->toIso8601String();
                $this->writeJsonFile($statusPath, $latestStatus);

                return true;
            } catch (\Throwable $exception) {
                $status = $this->readJsonFile($statusPath);
                $status['state'] = 'failed';
                $status['stage'] = 'supervisor-restart-failed';
                $status['message'] = 'Supervisor-Restart ohne Prozessdatensatz fehlgeschlagen: '.$exception->getMessage();
                $status['at'] = now()->toIso8601String();
                $this->writeJsonFile($statusPath, $status);

                return false;
            }
        }

        return false;
    }

    protected function runtimeCandidatesForRun(string $runId): array
    {
        return [
            'mail-registration' => storage_path('app/mail-registration/runs/'.$runId.'/runtime.json'),
            'webmail-session' => storage_path('app/webmail-session/runs/'.$runId.'/runtime.json'),
        ];
    }

    protected function writeMissingRunSupervisorStatus(string $statusPath, array $status, array $profileRotation = []): void
    {
        $profileRotated = (bool) ($profileRotation['rotated'] ?? false);
        $message = $profileRotated
            ? 'Supervisor startet den Run ohne aktiven Prozessdatensatz mit neuem Browser-Profilordner neu.'
            : 'Supervisor startet den Run ohne aktiven Prozessdatensatz neu.';
        $events = is_array($status['events'] ?? null) ? $status['events'] : [];
        $events[] = [
            'at' => now()->toIso8601String(),
            'stage' => 'supervisor-restarting-missing-run',
            'message' => $message,
            'previousBrowserProfilePath' => $profileRotation['previousBrowserProfilePath'] ?? null,
            'browserProfilePath' => $profileRotation['browserProfilePath'] ?? null,
        ];

        if (count($events) > 80) {
            $events = array_slice($events, -80);
        }

        $status['state'] = 'starting';
        $status['stage'] = 'supervisor-restarting-missing-run';
        $status['message'] = $message;
        $status['at'] = now()->toIso8601String();
        $status['heartbeatAt'] = now()->toIso8601String();
        $status['previousBrowserProfilePath'] = $profileRotation['previousBrowserProfilePath'] ?? ($status['previousBrowserProfilePath'] ?? null);
        $status['browserProfilePath'] = $profileRotation['browserProfilePath'] ?? ($status['browserProfilePath'] ?? null);
        $status['events'] = $events;

        $this->writeJsonFile($statusPath, $status);
    }

    protected function resolveScriptPath(ManagedProcess $process, array $runtime): ?string
    {
        return $this->resolveScriptPathForRunType((string) $process->run_type, $runtime);
    }

    protected function resolveScriptPathForRunType(string $runType, array $runtime): ?string
    {
        return match ($runType) {
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

    protected function rotateLockedBrowserProfile(string $runtimeConfigPath, array $runtime, string $statusPath): array
    {
        $profilePath = trim((string) ($runtime['browserProfilePath'] ?? ''));

        if ($profilePath === '') {
            return ['rotated' => false];
        }

        $status = $this->readJsonFile($statusPath);

        if (! $this->profileHasSingletonLock($profilePath) && ! $this->isBrowserProfileLockFailure($status)) {
            return ['rotated' => false];
        }

        $runDirectory = dirname($runtimeConfigPath);
        $nextProfilePath = $runDirectory.DIRECTORY_SEPARATOR.'browser-profile-restart-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6));

        $runtime['previousBrowserProfilePath'] = $profilePath;
        $runtime['browserProfilePath'] = $nextProfilePath;
        $runtime['browserProfileRestartedAt'] = now()->toIso8601String();
        $runtime['browserProfileRestartReason'] = 'browser-profile-lock';
        $runtime['browserProfileRetryCount'] = ((int) ($runtime['browserProfileRetryCount'] ?? 0)) + 1;

        File::ensureDirectoryExists($nextProfilePath);
        $this->writeJsonFile($runtimeConfigPath, $runtime);

        return [
            'rotated' => true,
            'previousBrowserProfilePath' => $profilePath,
            'browserProfilePath' => $nextProfilePath,
        ];
    }

    protected function profileHasSingletonLock(string $profilePath): bool
    {
        if ($profilePath === '' || ! File::isDirectory($profilePath)) {
            return false;
        }

        foreach (['SingletonLock', 'SingletonCookie', 'SingletonSocket'] as $lockFile) {
            if (File::exists($profilePath.DIRECTORY_SEPARATOR.$lockFile)) {
                return true;
            }
        }

        return false;
    }

    protected function isBrowserProfileLockFailure(array $status): bool
    {
        $state = Str::lower(trim((string) ($status['state'] ?? '')));
        $stage = Str::lower(trim((string) ($status['stage'] ?? '')));
        $message = Str::lower(trim((string) ($status['message'] ?? '')));

        if ($state !== 'failed' && ! str_contains($stage, 'failed') && ! str_contains($message, 'failed to launch')) {
            return false;
        }

        $events = is_array($status['events'] ?? null) ? $status['events'] : [];
        $latestEvent = $events === [] ? null : end($events);
        $text = Str::lower(json_encode([
            'stage' => $status['stage'] ?? null,
            'message' => $status['message'] ?? null,
            'latestEvent' => is_array($latestEvent) ? $latestEvent : null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return str_contains($text, 'singletonlock')
            || str_contains($text, 'processsingleton')
            || str_contains($text, 'process singleton')
            || str_contains($text, 'profile directory')
            || str_contains($text, 'profile is in use')
            || str_contains($text, 'user data directory');
    }

    protected function stopProcessTree(int $pid): void
    {
        if ($pid <= 1) {
            return;
        }

        // Auf Linux zusaetzlich die gesamte Prozessgruppe beenden (kill -KILL
        // -PGID). Da run_step.cjs per setsid gestartet wird, ist der Node-Root
        // der Gruppenfuehrer — so werden auch die nicht als managed erfassten
        // Chromium-Kindprozesse (Renderer, GPU, Zygote) mitgenommen statt als
        // RAM-Waisen zurueckzubleiben.
        $result = PHP_OS_FAMILY === 'Windows'
            ? Process::timeout(10)->run(['taskkill', '/PID', (string) $pid, '/T', '/F'])
            : Process::timeout(10)->run(['sh', '-lc', sprintf(
                'kill -KILL -%1$d 2>/dev/null; pkill -KILL -P %1$d 2>/dev/null; kill -KILL %1$d 2>/dev/null; true',
                $pid,
            )]);

        if (! $result->successful()) {
            Log::warning('Managed process supervisor could not stop stale process.', [
                'pid' => $pid,
                'message' => trim($result->errorOutput() ?: $result->output()),
            ]);
        }
    }

    protected function terminateProcessFamily(ManagedProcess $rootProcess, bool $force, string $message): void
    {
        $rootPid = (int) ($rootProcess->family_root_pid ?: $rootProcess->pid);

        if ($rootPid <= 1) {
            return;
        }

        $family = ManagedProcess::query()
            ->where(function ($query) use ($rootPid): void {
                $query->where('family_root_pid', $rootPid)
                    ->orWhere('pid', $rootPid);
            })
            ->whereIn('status', ['running', 'terminate_requested', 'kill_requested'])
            ->orderByRaw('CASE WHEN pid = ? THEN 1 ELSE 0 END', [$rootPid])
            ->get();

        $pids = $family
            ->pluck('pid')
            ->map(fn (mixed $pid): int => (int) $pid)
            ->filter(fn (int $pid): bool => $pid > 1)
            ->unique()
            ->values();

        if ($pids->isEmpty()) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            Process::timeout(10)->run(array_values(array_filter([
                'taskkill',
                '/PID',
                (string) $rootPid,
                '/T',
                $force ? '/F' : null,
            ])));
        } else {
            $signal = $force ? 'KILL' : 'TERM';

            foreach ($pids as $pid) {
                Process::timeout(5)->run(['kill', '-'.$signal, (string) $pid]);
            }

            // Prozessgruppe des Roots (setsid -> Node-Root ist PGID) mitnehmen,
            // damit auch die Chromium-Kindprozesse beendet werden.
            Process::timeout(5)->run(['sh', '-lc', sprintf(
                'kill -%1$s -%2$d 2>/dev/null; pkill -%1$s -P %2$d 2>/dev/null; true',
                $signal,
                $rootPid,
            )]);
        }

        ManagedProcess::query()
            ->whereIn('pid', $pids->all())
            ->update([
                'status' => $force ? 'killed' : 'terminated',
                'exited_at' => now(),
                'last_action_at' => now(),
                'action_message' => $message,
            ]);
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

    protected function spawnDetachedProcess(array $command, string $workingDirectory, string $stdoutPath, string $stderrPath, array $environment = []): ?int
    {
        File::ensureDirectoryExists(dirname($stdoutPath));
        File::ensureDirectoryExists(dirname($stderrPath));

        if (PHP_OS_FAMILY === 'Windows') {
            $environmentScript = $this->powershellEnvironmentScript($environment);
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
                $environmentScript.$script,
            ]);
        } else {
            $environmentPrefix = $this->shellEnvironmentPrefix($environment);
            $commandLine = implode(' ', array_map('escapeshellarg', $command));
            $shellCommand = sprintf(
                'cd %s && %s nohup %s > %s 2> %s < /dev/null & echo $!',
                escapeshellarg($workingDirectory),
                $environmentPrefix,
                $commandLine,
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

    protected function nodeProcessEnvironment(array $runtime = []): array
    {
        $timezone = $this->runtimeTimezone($runtime);

        return [
            'TZ' => $timezone,
            'APP_TIMEZONE' => $timezone,
        ];
    }

    protected function runtimeTimezone(array $runtime = []): string
    {
        $timezone = trim((string) (
            $runtime['timezone']
            ?? $runtime['timeZone']
            ?? data_get($runtime, 'subject.timezone')
            ?? data_get($runtime, 'workflow.person.timezone')
            ?? data_get($runtime, 'workflow.person.person_timezone')
            ?? (getenv('APP_TIMEZONE') ?: null)
            ?? (getenv('TZ') ?: null)
            ?? config('app.timezone', 'Europe/Berlin')
        ));

        if ($timezone !== '') {
            try {
                new \DateTimeZone($timezone);

                return $timezone;
            } catch (\Throwable) {
                // Fall through to the stable default.
            }
        }

        return 'Europe/Berlin';
    }

    protected function shellEnvironmentPrefix(array $environment): string
    {
        return collect($environment)
            ->filter(fn (mixed $value, string $key): bool => trim((string) $key) !== '' && trim((string) $value) !== '')
            ->map(fn (mixed $value, string $key): string => $key.'='.escapeshellarg((string) $value))
            ->implode(' ');
    }

    protected function powershellEnvironmentScript(array $environment): string
    {
        return collect($environment)
            ->filter(fn (mixed $value, string $key): bool => trim((string) $key) !== '' && trim((string) $value) !== '')
            ->map(fn (mixed $value, string $key): string => '$env:'.$key.' = '.$this->powershellQuote((string) $value).';')
            ->implode(' ');
    }

    protected function powershellQuote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
