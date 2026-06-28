<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Mail\MailAccountRegistrationRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class WorkflowTaskRunner
{
    public function __construct(
        protected MailAccountRegistrationRunner $mailSettings,
    ) {}

    public function start(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun, array $runtimeContext = []): array
    {
        $settings = $this->mailSettings->settings();
        $runId = (string) Str::uuid();
        $runDirectory = $this->runDirectory($runId);
        $publicRunDirectory = storage_path('app/public/'.$this->publicRunRelativeDirectory($runId));
        $statusPath = $runDirectory.DIRECTORY_SEPARATOR.'status.json';
        $resultPath = $runDirectory.DIRECTORY_SEPARATOR.'result.json';
        $configPath = $runDirectory.DIRECTORY_SEPARATOR.'runtime.json';
        $stdoutPath = $runDirectory.DIRECTORY_SEPARATOR.'stdout.log';
        $stderrPath = $runDirectory.DIRECTORY_SEPARATOR.'stderr.log';

        File::ensureDirectoryExists($runDirectory);
        File::ensureDirectoryExists($publicRunDirectory);

        $tasks = $this->runtimeTasks(
            $step,
            trim((string) ($runtimeContext['nextTaskKey'] ?? $runtimeContext['next_task_key'] ?? '')) ?: null,
        );

        $runtime = [
            'runId' => $runId,
            'processIdentity' => $this->processIdentity($runId, 'main', $run, $step, $stepRun),
            'workflow' => $runtimeContext,
            'workflowRunId' => $run->id,
            'workflowRunUuid' => $run->run_uuid,
            'workflowStepId' => $step->id,
            'workflowStepRunId' => $stepRun->id,
            'workflowStepName' => $step->name,
            'workflowStepType' => $step->type,
            'tasks' => $tasks,
            'statusPath' => $statusPath,
            'resultPath' => $resultPath,
            'runDirectory' => $runDirectory,
            'livePreviewEnabled' => true,
            'livePreviewIntervalSeconds' => 3,
            'livePreviewIntervalMs' => 3000,
            'livePreviewPollIntervalSeconds' => 3,
            'livePreviewPath' => $publicRunDirectory.DIRECTORY_SEPARATOR.'live.png',
            'livePreviewRelativePath' => $this->publicScreenshotRelativePath($runId),
            'browserProfilePath' => $this->workflowBrowserProfilePath($run),
            'browserEngine' => $settings['browser_engine'] ?? 'cloak-with-chrome-fallback',
            'cloakHumanizeEnabled' => (bool) ($settings['cloak_humanize_enabled'] ?? false),
            'cloakHumanPreset' => $settings['cloak_human_preset'] ?? '',
            'headlessEnabled' => (bool) ($settings['headless_enabled'] ?? false),
            'navigationTimeoutMs' => ((int) ($settings['navigation_timeout_seconds'] ?? 120)) * 1000,
            'observationTimeoutMs' => min(180000, max(30000, ((int) ($settings['observation_timeout_seconds'] ?? 60)) * 1000)),
            'scriptName' => 'run_step.cjs',
        ];

        $initialBrowserWindows = $this->browserWindowsFromRuntimeContext($runtimeContext);

        $this->writeJsonFile($statusPath, [
            'runId' => $runId,
            'workflow' => $this->publicRuntimeContext($runtimeContext),
            'processKey' => 'workflow-task:'.$runId.':main',
            'processIdentity' => $runtime['processIdentity'],
            'state' => 'queued',
            'stage' => 'queued',
            'message' => 'Workflow-Task-Lauf ist eingeplant.',
            'scriptName' => 'run_step.cjs',
            'livePreviewEnabled' => true,
            'livePreviewIntervalSeconds' => 3,
            'livePreviewPollIntervalSeconds' => 3,
            'tasks' => $this->configuredTasks($tasks),
            'events' => [],
            'browserWindows' => $initialBrowserWindows,
            'at' => now()->toIso8601String(),
        ]);
        $this->writeJsonFile($configPath, $runtime);

        try {
            $pid = $this->spawnDetachedProcess([
                $this->resolveNodeBinary(),
                $this->resolveNodeScriptPath(),
                $configPath,
            ], base_path(), $stdoutPath, $stderrPath);

            $status = $this->readJsonFile($statusPath) ?: [];
            $status['pid'] = $pid;
            $status['state'] = 'starting';
            $status['stage'] = 'process-started';
            $status['message'] = 'Workflow-Task-Prozess wurde gestartet.';
            $status['at'] = now()->toIso8601String();
            $this->writeJsonFile($statusPath, $status);
        } catch (\Throwable $exception) {
            $this->writeJsonFile($statusPath, [
                'runId' => $runId,
                'workflow' => $this->publicRuntimeContext($runtimeContext),
                'processKey' => 'workflow-task:'.$runId.':main',
                'processIdentity' => $runtime['processIdentity'],
                'state' => 'failed',
                'stage' => 'process-start-failed',
                'message' => $exception->getMessage(),
                'tasks' => $this->configuredTasks($tasks),
                'events' => [],
                'browserWindows' => $initialBrowserWindows,
                'at' => now()->toIso8601String(),
            ]);

            throw $exception;
        }

        return $this->readRun($runId) ?? [
            'runId' => $runId,
            'state' => 'starting',
            'stage' => 'process-started',
            'message' => 'Workflow-Task-Prozess wurde gestartet.',
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

        if (is_array($result) && in_array((string) ($status['state'] ?? ''), ['queued', 'starting', 'running'], true)) {
            $status['state'] = ($result['ok'] ?? false) ? 'completed' : 'failed';
            $status['stage'] = $status['state'];
            $status['message'] = (string) ($result['statusMessage'] ?? $status['message'] ?? '');
            $this->writeJsonFile($statusPath, $status);
        }

        $status['runId'] = $runId;
        $status['isRunning'] = in_array((string) ($status['state'] ?? ''), ['queued', 'starting', 'running'], true);
        $status['livePreviewIntervalSeconds'] = (int) ($status['livePreviewIntervalSeconds'] ?? 3);
        $status['livePreviewPollIntervalSeconds'] = (int) ($status['livePreviewPollIntervalSeconds'] ?? $status['livePreviewIntervalSeconds']);
        $status['result'] = $result;

        return $this->publicRunStatus($status);
    }

    public function readResult(?string $runId): ?array
    {
        $runId = trim((string) $runId);

        if ($runId === '') {
            return null;
        }

        return $this->readJsonFile($this->runDirectory($runId).DIRECTORY_SEPARATOR.'result.json');
    }

    public function cancelRun(?string $runId, bool $force = true, string $message = 'Workflow-Task-Lauf wurde abgebrochen.'): array
    {
        $runId = trim((string) $runId);

        if ($runId === '') {
            return ['ok' => false, 'message' => 'Run-ID fehlt.'];
        }

        $statusPath = $this->runDirectory($runId).DIRECTORY_SEPARATOR.'status.json';
        $resultPath = $this->runDirectory($runId).DIRECTORY_SEPARATOR.'result.json';
        $status = $this->readJsonFile($statusPath) ?: [];
        $pid = (int) ($status['pid'] ?? 0);
        $browserWindows = $this->browserWindowsFromStatus($status);

        if ($pid > 1) {
            $this->stopProcess($pid, $force);
        }

        $result = [
            'ok' => false,
            'status' => 'cancelled',
            'statusMessage' => $message,
            'cancelledAt' => now()->toIso8601String(),
            'browserWindows' => $browserWindows,
        ];
        $events = is_array($status['events'] ?? null) ? $status['events'] : [];
        $events[] = [
            'at' => now()->toIso8601String(),
            'stage' => 'cancelled',
            'message' => $message,
            'pid' => $pid ?: null,
        ];

        $status = array_replace($status, [
            'runId' => $runId,
            'state' => 'cancelled',
            'stage' => 'cancelled',
            'message' => $message,
            'isRunning' => false,
            'cancelledAt' => now()->toIso8601String(),
            'at' => now()->toIso8601String(),
            'events' => array_slice($events, -80),
            'browserWindows' => $browserWindows,
        ]);

        $this->writeJsonFile($resultPath, $result);
        $this->writeJsonFile($statusPath, $status);

        return ['ok' => true, 'message' => $message, 'pid' => $pid ?: null];
    }

    protected function browserWindowsFromStatus(array $status): array
    {
        foreach ([
            $status['browserWindows'] ?? null,
            data_get($status, 'result.browserWindows'),
            data_get($status, 'workflow.browserWindows'),
            data_get($status, 'workflow.browser_windows'),
        ] as $candidate) {
            if (! is_array($candidate) || $candidate === []) {
                continue;
            }

            return collect($candidate)
                ->map(function (mixed $window, int|string $key): ?array {
                    if (! is_array($window)) {
                        return null;
                    }

                    if (! array_key_exists('key', $window) && ! is_int($key)) {
                        $window['key'] = (string) $key;
                    }

                    return $window;
                })
                ->filter()
                ->values()
                ->all();
        }

        return [];
    }

    protected function browserWindowsFromRuntimeContext(array $runtimeContext): array
    {
        foreach ([
            $runtimeContext['browserWindows'] ?? null,
            $runtimeContext['browser_windows'] ?? null,
        ] as $candidate) {
            if (! is_array($candidate) || $candidate === []) {
                continue;
            }

            return collect($candidate)
                ->map(function (mixed $window, int|string $key): ?array {
                    if (! is_array($window)) {
                        return null;
                    }

                    if (! array_key_exists('key', $window) && ! is_int($key)) {
                        $window['key'] = (string) $key;
                    }

                    $windowKey = trim((string) ($window['key'] ?? $window['name'] ?? ''));

                    if ($windowKey === '') {
                        return null;
                    }

                    $window['key'] = $windowKey;
                    $window['label'] = trim((string) ($window['label'] ?? $windowKey)) ?: $windowKey;

                    return $window;
                })
                ->filter()
                ->values()
                ->all();
        }

        return [];
    }

    protected function configuredTasks(array $tasks): array
    {
        return collect($tasks)
            ->map(fn (array $task): array => array_replace($task, ['status' => 'configured']))
            ->values()
            ->toArray();
    }

    protected function runtimeTasks(WorkflowStep $step, ?string $startTaskKey = null): array
    {
        $tasks = $step->task_cards;

        if ($startTaskKey !== null) {
            $startIndex = collect($tasks)->search(
                fn (array $task): bool => (string) ($task['key'] ?? '') === $startTaskKey,
            );

            if ($startIndex === false) {
                throw new \RuntimeException('Die Ziel-Task fuer den Ruecksprung wurde nicht gefunden: '.$startTaskKey);
            }

            $tasks = array_slice($tasks, (int) $startIndex);
        }

        return $this->expandRuntimeTasks(
            $tasks,
            [(int) $step->workflow_id],
        );
    }

    protected function expandRuntimeTasks(
        array $tasks,
        array $workflowStack,
        ?string $parentTaskKey = null,
        string $keyPrefix = '',
        ?string $inheritedMailboxSource = null,
    ): array {
        $expanded = [];

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }

            if ((string) ($task['runner'] ?? '') !== 'workflow') {
                $runtimeTask = $this->normalizeRuntimeTask($task);
                $mailboxSource = $this->normalizeMailboxSource($inheritedMailboxSource);

                if ($mailboxSource !== null) {
                    $runtimeTask['mailbox_source'] = $mailboxSource;
                    $runtimeTask['script_person_source'] = $mailboxSource;
                }

                if ($keyPrefix !== '') {
                    $originalKey = trim((string) ($runtimeTask['key'] ?? 'task')) ?: 'task';
                    $runtimeTask['key'] = Str::slug($keyPrefix.'-'.$originalKey);
                }

                if ($parentTaskKey !== null) {
                    $runtimeTask['parent_task_key'] = $parentTaskKey;
                }

                $expanded[] = $runtimeTask;

                continue;
            }

            $workflowId = (int) ($task['workflow_id'] ?? 0);
            $taskKey = trim((string) ($task['key'] ?? 'workflow')) ?: 'workflow';
            $rootTaskKey = $parentTaskKey ?? $taskKey;
            $workflowMailboxSource = $inheritedMailboxSource
                ?? $this->normalizeMailboxSource($task['script_person_source'] ?? $task['mailbox_source'] ?? null);

            if ($workflowId <= 0) {
                throw new \RuntimeException('Die Workflow-Task "'.$taskKey.'" hat keine gueltige Workflow-Referenz.');
            }

            if (in_array($workflowId, $workflowStack, true)) {
                throw new \RuntimeException('Zyklische Workflow-Einbindung erkannt (Workflow #'.$workflowId.').');
            }

            $workflow = Workflow::query()
                ->with(['steps' => fn ($query) => $query->ordered()])
                ->find($workflowId);

            if (! $workflow) {
                throw new \RuntimeException('Der eingebettete Workflow #'.$workflowId.' wurde nicht gefunden.');
            }

            if (! $workflow->is_active) {
                throw new \RuntimeException('Der eingebettete Workflow "'.$workflow->name.'" ist deaktiviert.');
            }

            $steps = $workflow->steps->where('is_enabled', true)->values();

            if ($steps->isEmpty()) {
                throw new \RuntimeException('Der eingebettete Workflow "'.$workflow->name.'" hat keine aktiven Listen.');
            }

            foreach ($steps as $nestedStep) {
                $nestedTasks = $nestedStep->task_cards;

                if ($nestedTasks === [] && $nestedStep->type === WorkflowStep::TYPE_WAIT) {
                    $nestedTasks = [app(WorkflowTaskCatalog::class)->cardFromDefinition('wait.seconds', [
                        'key' => 'warten',
                        'title' => $nestedStep->name,
                        'value' => max(0, (int) data_get($nestedStep->config_json, 'seconds', $nestedStep->wait_after_seconds)),
                    ])];
                }

                if ($nestedTasks === []) {
                    throw new \RuntimeException(
                        'Die Liste "'.$nestedStep->name.'" im eingebetteten Workflow "'.$workflow->name.'" enthaelt keine ausfuehrbare Task-Karte.'
                    );
                }

                $nestedPrefix = trim($keyPrefix.'-workflow-'.$workflow->id.'-'.$taskKey.'-step-'.$nestedStep->id, '-');
                $nestedExpanded = $this->expandRuntimeTasks(
                    $nestedTasks,
                    [...$workflowStack, $workflowId],
                    $rootTaskKey,
                    $nestedPrefix,
                    $workflowMailboxSource,
                );

                foreach ($nestedExpanded as $nestedTask) {
                    $nestedTask['embedded_workflow_id'] = $workflow->id;
                    $nestedTask['embedded_workflow_name'] = $workflow->name;
                    $expanded[] = $nestedTask;
                }

                if ($nestedStep->type !== WorkflowStep::TYPE_WAIT && $nestedStep->wait_after_seconds > 0) {
                    $waitTask = app(WorkflowTaskCatalog::class)->cardFromDefinition('wait.seconds', [
                        'key' => 'wartezeit-nach-liste',
                        'title' => 'Wartezeit nach '.$nestedStep->name,
                        'value' => $nestedStep->wait_after_seconds,
                    ]);
                    $waitTask['key'] = Str::slug($nestedPrefix.'-wartezeit-nach-liste');
                    $waitTask['parent_task_key'] = $rootTaskKey;
                    $waitTask['embedded_workflow_id'] = $workflow->id;
                    $waitTask['embedded_workflow_name'] = $workflow->name;
                    if ($workflowMailboxSource !== null) {
                        $waitTask['mailbox_source'] = $workflowMailboxSource;
                        $waitTask['script_person_source'] = $workflowMailboxSource;
                    }
                    $expanded[] = $waitTask;
                }
            }
        }

        return $expanded;
    }

    protected function normalizeMailboxSource(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        return in_array($value, ['verification', 'verification_mailbox', 'veri-account', 'veri_account', 'main', 'master'], true)
            ? 'verification'
            : 'person';
    }

    protected function normalizeRuntimeTask(array $task): array
    {
        $script = match ((string) ($task['task_key'] ?? '')) {
            'data.read_account_data' => 'node/workflows/tasks/data/read_account_data.cjs',
            'data.resolve_person' => 'node/workflows/tasks/data/resolve_person.cjs',
            'data.read_login_data' => 'node/workflows/tasks/data/read_login_data.cjs',
            'data.persist_mail_account' => 'node/workflows/tasks/data/persist_mail_account.cjs',
            'data.persist_webmail_session' => 'node/workflows/tasks/data/persist_webmail_session.cjs',
            default => null,
        };

        if ($script === null) {
            return $task;
        }

        $task['runner'] = 'node';
        $task['node_script'] = $script;

        return $task;
    }

    protected function runDirectory(string $runId): string
    {
        return storage_path('app/workflow-task-runs/'.$runId);
    }

    protected function workflowBrowserProfilePath(WorkflowRun $run): string
    {
        return storage_path('app/workflow-runs/'.$run->run_uuid.'/browser-profile');
    }

    protected function publicRuntimeContext(array $runtimeContext): array
    {
        $public = $runtimeContext;
        unset($public['browser'], $public['browser_runtime'], $public['browserWsEndpoint'], $public['browser_ws_endpoint']);

        foreach (['account', 'email_account', 'verificationMailbox', 'verification_mailbox', 'veri_account', 'veri-account'] as $key) {
            if (isset($public[$key]) && is_array($public[$key])) {
                unset($public[$key]['password'], $public[$key]['passwordEncrypted'], $public[$key]['password_encrypted'], $public[$key]['webmailSession'], $public[$key]['webmail_session']);
            }
        }

        if (isset($public['person']['emailAccount']) && is_array($public['person']['emailAccount'])) {
            unset($public['person']['emailAccount']['password'], $public['person']['emailAccount']['passwordEncrypted'], $public['person']['emailAccount']['password_encrypted'], $public['person']['emailAccount']['webmailSession'], $public['person']['emailAccount']['webmail_session']);
        }

        if (isset($public['person']) && is_array($public['person'])) {
            unset($public['person']['password'], $public['person']['passwordEncrypted'], $public['person']['password_encrypted']);
        }

        return $public;
    }

    protected function processIdentity(string $runId, string $role, WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): array
    {
        return [
            'processKey' => 'workflow-task:'.$runId.':'.$role,
            'runId' => $runId,
            'runType' => 'workflow-task',
            'role' => $role,
            'workflowRunId' => $run->id,
            'workflowRunUuid' => $run->run_uuid,
            'workflowStepId' => $step->id,
            'workflowStepRunId' => $stepRun->id,
        ];
    }

    protected function publicRunStatus(array $status): array
    {
        unset($status['browserWsEndpoint'], $status['browser_ws_endpoint']);

        if (isset($status['result']) && is_array($status['result'])) {
            unset($status['result']['browserWsEndpoint'], $status['result']['browser_ws_endpoint']);
        }

        return $status;
    }

    protected function publicRunRelativeDirectory(string $runId): string
    {
        return 'workflow-task-runs/'.$runId;
    }

    protected function publicScreenshotRelativePath(string $runId): string
    {
        return $this->publicRunRelativeDirectory($runId).'/live.png';
    }

    protected function resolveNodeScriptPath(): string
    {
        $script = base_path('node/workflows/run_step.cjs');

        if (! File::exists($script)) {
            throw new \RuntimeException('Der Workflow-Task-Runner wurde nicht gefunden: '.$script);
        }

        return $script;
    }

    protected function writeJsonFile(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function readJsonFile(string $path): ?array
    {
        try {
            if (! File::exists($path)) {
                return null;
            }

            $payload = json_decode(File::get($path), true);

            return is_array($payload) ? $payload : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function resolveNodeBinary(): string
    {
        $candidates = PHP_OS_FAMILY === 'Windows'
            ? ['C:\\Program Files\\nodejs\\node.exe', 'C:\\Program Files (x86)\\nodejs\\node.exe']
            : ['/usr/bin/node', '/usr/local/bin/node', '/bin/node', '/snap/bin/node', '/usr/bin/nodejs', '/usr/local/bin/nodejs'];

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

        throw new \RuntimeException('Node.js wurde fuer Workflow-Tasks nicht gefunden.');
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

            $result = Process::timeout(15)->run(['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', $script]);
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
            throw new \RuntimeException(trim($result->errorOutput()) ?: 'Der Workflow-Task-Prozess konnte nicht gestartet werden.');
        }

        $pid = (int) trim($result->output());

        return $pid > 0 ? $pid : null;
    }

    protected function stopProcess(int $pid, bool $force = true): void
    {
        if ($pid <= 1) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            Process::timeout(10)->run(array_values(array_filter([
                'taskkill',
                '/PID',
                (string) $pid,
                '/T',
                $force ? '/F' : null,
            ])));

            return;
        }

        Process::timeout(10)->run(['kill', '-'.($force ? 'KILL' : 'TERM'), (string) $pid]);
    }

    protected function powershellQuote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
