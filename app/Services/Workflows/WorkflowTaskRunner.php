<?php

namespace App\Services\Workflows;

use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class WorkflowTaskRunner
{
    public function start(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun, array $runtimeContext = []): array
    {
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

        $runtime = [
            'runId' => $runId,
            'workflow' => $runtimeContext,
            'workflowRunId' => $run->id,
            'workflowRunUuid' => $run->run_uuid,
            'workflowStepId' => $step->id,
            'workflowStepRunId' => $stepRun->id,
            'workflowStepName' => $step->name,
            'workflowStepType' => $step->type,
            'tasks' => $step->task_cards,
            'statusPath' => $statusPath,
            'resultPath' => $resultPath,
            'livePreviewEnabled' => true,
            'livePreviewIntervalSeconds' => 3,
            'livePreviewIntervalMs' => 3000,
            'livePreviewPollIntervalSeconds' => 3,
            'livePreviewPath' => $publicRunDirectory.DIRECTORY_SEPARATOR.'live.png',
            'livePreviewRelativePath' => $this->publicScreenshotRelativePath($runId),
            'browserProfilePath' => $runDirectory.DIRECTORY_SEPARATOR.'browser-profile',
            'headlessEnabled' => false,
            'navigationTimeoutMs' => 120000,
            'observationTimeoutMs' => 90000,
            'scriptName' => 'run_step.cjs',
        ];

        $this->writeJsonFile($statusPath, [
            'runId' => $runId,
            'workflow' => $runtimeContext,
            'state' => 'queued',
            'stage' => 'queued',
            'message' => 'Workflow-Task-Lauf ist eingeplant.',
            'scriptName' => 'run_step.cjs',
            'livePreviewEnabled' => true,
            'livePreviewIntervalSeconds' => 3,
            'livePreviewPollIntervalSeconds' => 3,
            'tasks' => $this->configuredTasks($step),
            'events' => [],
            'browserWindows' => [],
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
                'workflow' => $runtimeContext,
                'state' => 'failed',
                'stage' => 'process-start-failed',
                'message' => $exception->getMessage(),
                'tasks' => $this->configuredTasks($step),
                'events' => [],
                'browserWindows' => [],
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

        return $status;
    }

    public function readResult(?string $runId): ?array
    {
        $runId = trim((string) $runId);

        if ($runId === '') {
            return null;
        }

        return $this->readJsonFile($this->runDirectory($runId).DIRECTORY_SEPARATOR.'result.json');
    }

    protected function configuredTasks(WorkflowStep $step): array
    {
        return collect($step->task_cards)
            ->map(fn (array $task): array => array_replace($task, ['status' => 'configured']))
            ->values()
            ->toArray();
    }

    protected function runDirectory(string $runId): string
    {
        return storage_path('app/workflow-task-runs/'.$runId);
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

    protected function powershellQuote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
