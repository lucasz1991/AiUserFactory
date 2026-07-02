<?php

namespace App\Services\Workflows;

use App\Models\WorkflowRun;
use App\Models\WorkflowRunArtifact;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class WorkflowRunDebugPackageService
{
    public function __construct(
        protected WorkflowTransferService $transferService,
        protected WorkflowDebugArtifactService $artifactService,
    ) {}

    public function make(WorkflowRun $run): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP-Zip-Erweiterung ist nicht verfuegbar.');
        }

        $run->loadMissing([
            'artifacts.workflowStep',
            'workflow.steps' => fn ($query) => $query->ordered(),
            'stepRuns.workflowStep',
        ]);

        if (! $run->workflow) {
            throw new RuntimeException('Workflow zum Testlauf wurde nicht gefunden.');
        }

        $directory = storage_path('app/private/workflow-debug-packages');
        File::ensureDirectoryExists($directory);

        $slug = Str::slug($run->workflow->slug ?: $run->workflow->name) ?: 'workflow';
        $filename = 'workflow-debug-'.$slug.'-run-'.$run->id.'-'.now()->format('Y-m-d-His').'.zip';
        $path = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.zip';
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Debug-ZIP konnte nicht erzeugt werden.');
        }

        $manifest = [
            'generatedAt' => now()->toIso8601String(),
            'workflowId' => $run->workflow_id,
            'workflowSlug' => $run->workflow->slug,
            'workflowName' => $run->workflow->name,
            'workflowRunId' => $run->id,
            'workflowRunUuid' => $run->run_uuid,
            'workflowRunStatus' => $run->status,
            'files' => [],
            'skipped' => [],
        ];
        $usedNames = [];
        $addedFileSources = [];

        try {
            $this->addDebugString(
                $zip,
                $manifest,
                $usedNames,
                'workflow-export/workflows.csv',
                $this->transferService->csv([$run->workflow]),
                'workflow csv export',
            );

            $runPayload = $this->runPayload($run);
            $this->addDebugString(
                $zip,
                $manifest,
                $usedNames,
                'run/workflow-run-'.$run->id.'.json',
                $this->debugJson($runPayload),
                'database workflow run snapshot',
            );

            foreach ($this->externalPayloads($run) as $entry) {
                $this->addDebugString(
                    $zip,
                    $manifest,
                    $usedNames,
                    $entry['name'],
                    $this->debugJson($entry['payload']),
                    $entry['source'],
                );
            }

            foreach ($this->fileCandidates($run, $runPayload) as $candidate) {
                $realPath = realpath($candidate['path']) ?: $candidate['path'];

                if (isset($addedFileSources[$realPath])) {
                    continue;
                }

                if ($this->addDebugFile($zip, $manifest, $usedNames, $candidate)) {
                    $addedFileSources[$realPath] = true;
                }
            }

            $this->addDebugString(
                $zip,
                $manifest,
                $usedNames,
                'README.txt',
                $this->readme($run),
                'debug package readme',
            );
            $this->addDebugString(
                $zip,
                $manifest,
                $usedNames,
                'manifest.json',
                $this->debugJson($manifest),
                'debug package manifest',
            );
        } catch (\Throwable $exception) {
            $zip->close();
            File::delete($path);

            throw $exception;
        }

        $zip->close();

        return ['path' => $path, 'filename' => $filename];
    }

    protected function runPayload(WorkflowRun $run): array
    {
        $workflow = $run->workflow;

        $payload = [
            'exportedAt' => now()->toIso8601String(),
            'workflow' => $workflow ? [
                'id' => $workflow->id,
                'slug' => $workflow->slug,
                'name' => $workflow->name,
                'description' => $workflow->description,
                'category' => $workflow->category,
                'subcategory' => $workflow->subcategory,
                'is_active' => (bool) $workflow->is_active,
                'is_locked' => (bool) $workflow->is_locked,
                'trigger_type' => $workflow->trigger_type,
                'settings_json' => $workflow->settings_json,
                'steps' => $workflow->steps
                    ->map(fn (WorkflowStep $step): array => [
                        'id' => $step->id,
                        'name' => $step->name,
                        'type' => $step->type,
                        'action_key' => $step->action_key,
                        'position' => $step->position,
                        'is_enabled' => (bool) $step->is_enabled,
                        'config_json' => $step->config_json,
                        'retry_attempts' => $step->retry_attempts,
                        'wait_after_seconds' => $step->wait_after_seconds,
                    ])
                    ->values()
                    ->all(),
            ] : null,
            'run' => [
                'id' => $run->id,
                'run_uuid' => $run->run_uuid,
                'workflow_id' => $run->workflow_id,
                'current_workflow_step_id' => $run->current_workflow_step_id,
                'status' => $run->status,
                'requested_by' => $run->requested_by,
                'queued_at' => $run->queued_at?->toIso8601String(),
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'duration_ms' => $run->duration_ms,
                'context_json' => $run->context_json,
                'result_json' => $run->result_json,
                'error_message' => $run->error_message,
                'created_at' => $run->created_at?->toIso8601String(),
                'updated_at' => $run->updated_at?->toIso8601String(),
            ],
            'stepRuns' => $run->stepRuns
                ->map(fn (WorkflowStepRun $stepRun): array => [
                    'id' => $stepRun->id,
                    'workflow_step_id' => $stepRun->workflow_step_id,
                    'workflow_step' => $stepRun->workflowStep ? [
                        'id' => $stepRun->workflowStep->id,
                        'name' => $stepRun->workflowStep->name,
                        'type' => $stepRun->workflowStep->type,
                        'action_key' => $stepRun->workflowStep->action_key,
                        'position' => $stepRun->workflowStep->position,
                        'config_json' => $stepRun->workflowStep->config_json,
                    ] : null,
                    'status' => $stepRun->status,
                    'external_run_type' => $stepRun->external_run_type,
                    'external_run_id' => $stepRun->external_run_id,
                    'started_at' => $stepRun->started_at?->toIso8601String(),
                    'finished_at' => $stepRun->finished_at?->toIso8601String(),
                    'duration_ms' => $stepRun->duration_ms,
                    'logs_json' => $stepRun->logs_json,
                    'result_json' => $stepRun->result_json,
                    'error_message' => $stepRun->error_message,
                    'created_at' => $stepRun->created_at?->toIso8601String(),
                    'updated_at' => $stepRun->updated_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'artifacts' => $run->artifacts
                ->map(fn (WorkflowRunArtifact $artifact): array => [
                    'id' => $artifact->id,
                    'workflow_id' => $artifact->workflow_id,
                    'workflow_run_id' => $artifact->workflow_run_id,
                    'workflow_step_id' => $artifact->workflow_step_id,
                    'workflow_step_run_id' => $artifact->workflow_step_run_id,
                    'step_position' => $artifact->step_position,
                    'step_action_key' => $artifact->step_action_key,
                    'task_card_key' => $artifact->task_card_key,
                    'phase' => $artifact->phase,
                    'artifact_type' => $artifact->artifact_type,
                    'browser_window' => $artifact->browser_window,
                    'current_url' => $artifact->current_url,
                    'title' => $artifact->title,
                    'storage_disk' => $artifact->storage_disk,
                    'storage_path' => $artifact->storage_path,
                    'status' => $artifact->status,
                    'error_message' => $artifact->error_message,
                    'metadata_json' => $artifact->metadata_json,
                    'created_at' => $artifact->created_at?->toIso8601String(),
                    'updated_at' => $artifact->updated_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];

        return $this->sanitize($payload);
    }

    protected function externalPayloads(WorkflowRun $run): array
    {
        $payloads = [];

        foreach ($run->stepRuns as $stepRun) {
            $runDirectory = $this->externalRunDirectory($stepRun);

            if ($runDirectory === null || ! is_dir($runDirectory)) {
                continue;
            }

            $folder = $this->externalRunFolder($stepRun);

            foreach (['status.json', 'result.json'] as $filename) {
                $payload = $this->readJsonFile($runDirectory.DIRECTORY_SEPARATOR.$filename);

                if ($payload === null) {
                    continue;
                }

                $payloads[] = [
                    'name' => $folder.'/'.$filename,
                    'source' => $stepRun->external_run_type.' '.$stepRun->external_run_id.' '.$filename,
                    'payload' => $payload,
                ];
            }
        }

        return $payloads;
    }

    protected function fileCandidates(WorkflowRun $run, array $payload): array
    {
        $candidates = [];

        foreach ($this->fileReferences($payload) as $reference) {
            $path = $this->resolveFileReference($reference['value']);

            if ($path === null) {
                continue;
            }

            $basename = basename((string) parse_url($reference['value'], PHP_URL_PATH)) ?: basename($path);
            $candidates[] = [
                'path' => $path,
                'name' => 'dom/referenced/'.$this->safeSegment($reference['path']).'-'.$basename,
                'source' => 'payload '.$reference['path'],
            ];
        }

        foreach ($run->artifacts as $artifact) {
            if ($artifact->status !== 'success') {
                continue;
            }

            $path = $this->artifactService->absolutePath($artifact);

            if ($path === null) {
                continue;
            }

            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $filename = collect([
                'step',
                $artifact->step_position ?: $artifact->workflow_step_run_id ?: $artifact->workflow_step_id ?: 'run',
                $artifact->step_action_key ?: 'workflow',
                $artifact->phase ?: 'artifact',
                $artifact->artifact_type ?: 'file',
                $artifact->browser_window ?: 'main',
            ])->map(fn ($part) => $this->safeSegment((string) $part))->implode('-');
            $filename .= $extension !== '' ? '.'.$extension : '';

            $candidates[] = [
                'path' => $path,
                'name' => 'debug-artifacts/'.$filename,
                'source' => 'workflow_run_artifacts #'.$artifact->id,
            ];
        }

        foreach ($run->stepRuns as $stepRun) {
            foreach ($this->externalPublicRunDirectories($stepRun) as $directory) {
                if (! is_dir($directory)) {
                    continue;
                }

                foreach (File::allFiles($directory) as $file) {
                    if (! $this->isDebugArtifact($file->getFilename())) {
                        continue;
                    }

                    $relativePath = str_replace('\\', '/', $file->getRelativePathname());
                    $candidates[] = [
                        'path' => $file->getPathname(),
                        'name' => $this->externalRunFolder($stepRun).'/artifacts/'.$relativePath,
                        'source' => 'public artifacts '.$stepRun->external_run_type.' '.$stepRun->external_run_id,
                    ];
                }
            }
        }

        return $candidates;
    }

    protected function fileReferences(mixed $payload, string $path = ''): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $references = [];

        foreach ($payload as $key => $value) {
            $keyPath = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($value) && $this->looksLikeFileReference((string) $key, $value)) {
                $references[] = ['path' => $keyPath, 'value' => $value];
            }

            if (is_array($value)) {
                array_push($references, ...$this->fileReferences($value, $keyPath));
            }
        }

        return $references;
    }

    protected function looksLikeFileReference(string $key, string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        $normalizedKey = Str::lower(str_replace(['-', '_'], '', $key));
        $normalizedValue = Str::lower($value);

        return str_contains($normalizedKey, 'debugdom')
            || (str_contains($normalizedKey, 'dom') && (str_contains($normalizedKey, 'path') || str_contains($normalizedKey, 'url')))
            || str_contains($normalizedValue, 'debug-dom')
            || str_contains($normalizedValue, 'debug_dom')
            || str_contains($normalizedValue, 'dom.json');
    }

    protected function resolveFileReference(string $reference): ?string
    {
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        $path = parse_url($reference, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : strtok($reference, '?');
        $path = is_string($path) ? urldecode($path) : '';

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, storage_path())) {
            return $path;
        }

        if (str_contains($path, '/storage/')) {
            return storage_path('app/public/'.ltrim(Str::after($path, '/storage/'), '/'));
        }

        if (str_starts_with($path, '/storage/')) {
            return storage_path('app/public/'.ltrim(Str::after($path, '/storage/'), '/'));
        }

        if (str_starts_with($path, 'storage/')) {
            return storage_path('app/public/'.ltrim(Str::after($path, 'storage/'), '/'));
        }

        foreach (['workflow-task-runs/', 'mail-registration/runs/', 'webmail-session/runs/'] as $publicPrefix) {
            if (str_starts_with(ltrim($path, '/'), $publicPrefix)) {
                return storage_path('app/public/'.ltrim($path, '/'));
            }
        }

        return null;
    }

    protected function externalRunDirectory(WorkflowStepRun $stepRun): ?string
    {
        $runId = trim((string) $stepRun->external_run_id);

        if ($runId === '') {
            return null;
        }

        return match ($stepRun->external_run_type) {
            'mail-registration' => storage_path('app/mail-registration/runs/'.$runId),
            'webmail-session' => storage_path('app/webmail-session/runs/'.$runId),
            'workflow-task' => storage_path('app/workflow-task-runs/'.$runId),
            default => null,
        };
    }

    protected function externalPublicRunDirectories(WorkflowStepRun $stepRun): array
    {
        $runId = trim((string) $stepRun->external_run_id);

        if ($runId === '') {
            return [];
        }

        return match ($stepRun->external_run_type) {
            'mail-registration' => [storage_path('app/public/mail-registration/runs/'.$runId)],
            'webmail-session' => [storage_path('app/public/webmail-session/runs/'.$runId)],
            'workflow-task' => [storage_path('app/public/workflow-task-runs/'.$runId)],
            default => [],
        };
    }

    protected function externalRunFolder(WorkflowStepRun $stepRun): string
    {
        return 'external-runs/step-'.$stepRun->id.'-'
            .$this->safeSegment((string) $stepRun->external_run_type).'-'
            .$this->safeSegment((string) $stepRun->external_run_id);
    }

    protected function isDebugArtifact(string $filename): bool
    {
        $filename = Str::lower($filename);

        return str_contains($filename, 'debug-dom')
            || str_contains($filename, 'debug_dom')
            || str_contains($filename, 'dom.json')
            || in_array($filename, ['live.png', 'live-webmail.png'], true);
    }

    protected function readJsonFile(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return null;
        }

        return $this->sanitize($decoded);
    }

    protected function sanitize(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $sanitized = [];

        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[redacted]';

                continue;
            }

            $sanitized[$key] = $this->sanitize($value);
        }

        return $sanitized;
    }

    protected function isSensitiveKey(string $key): bool
    {
        $key = Str::lower(str_replace(['-', '_', ' '], '', $key));

        return str_contains($key, 'password')
            || str_contains($key, 'secret')
            || str_contains($key, 'token')
            || str_contains($key, 'sessionpayload')
            || str_contains($key, 'sessionfilepath')
            || str_contains($key, 'payloadencrypted')
            || str_contains($key, 'browserwsendpoint')
            || str_contains($key, 'websocketendpoint')
            || in_array($key, ['webmailsession', 'webmailsessiondata', 'browsersession', 'browsersessiondata'], true)
            || $key === 'wsendpoint';
    }

    protected function addDebugString(
        ZipArchive $zip,
        array &$manifest,
        array &$usedNames,
        string $name,
        string $contents,
        string $source,
    ): void {
        $zipName = $this->uniqueZipName($usedNames, $name);

        if ($zip->addFromString($zipName, $contents) === true) {
            $manifest['files'][] = [
                'path' => $zipName,
                'source' => $source,
                'bytes' => strlen($contents),
            ];

            return;
        }

        $manifest['skipped'][] = [
            'path' => $zipName,
            'source' => $source,
            'reason' => 'zip addFromString failed',
        ];
    }

    protected function addDebugFile(ZipArchive $zip, array &$manifest, array &$usedNames, array $candidate): bool
    {
        $path = (string) ($candidate['path'] ?? '');
        $source = (string) ($candidate['source'] ?? 'debug file');
        $zipName = $this->uniqueZipName($usedNames, (string) ($candidate['name'] ?? basename($path)));

        if (! is_file($path) || ! is_readable($path)) {
            $manifest['skipped'][] = [
                'path' => $zipName,
                'source' => $source,
                'reason' => 'file missing or unreadable',
            ];

            return false;
        }

        if ($zip->addFile($path, $zipName) !== true) {
            $manifest['skipped'][] = [
                'path' => $zipName,
                'source' => $source,
                'reason' => 'zip addFile failed',
            ];

            return false;
        }

        $manifest['files'][] = [
            'path' => $zipName,
            'source' => $source,
            'bytes' => filesize($path) ?: 0,
        ];

        return true;
    }

    protected function uniqueZipName(array &$usedNames, string $name): string
    {
        $name = trim(str_replace('\\', '/', $name), '/');
        $name = $name !== '' ? $name : 'debug-file';
        $original = $name;
        $counter = 2;

        while (isset($usedNames[$name])) {
            $extension = pathinfo($original, PATHINFO_EXTENSION);
            $base = $extension !== ''
                ? substr($original, 0, -1 * (strlen($extension) + 1))
                : $original;
            $name = $base.'-'.$counter.($extension !== '' ? '.'.$extension : '');
            $counter++;
        }

        $usedNames[$name] = true;

        return $name;
    }

    protected function safeSegment(string $value): string
    {
        $segment = Str::slug(str_replace(['.', '_'], '-', $value));

        return $segment !== '' ? substr($segment, 0, 80) : 'item';
    }

    protected function debugJson(mixed $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return ($json === false ? '{}' : $json).PHP_EOL;
    }

    protected function readme(WorkflowRun $run): string
    {
        return implode(PHP_EOL, [
            'Workflow Debug Package',
            'Generated: '.now()->toIso8601String(),
            'Workflow: '.($run->workflow?->name ?? 'unknown').' (#'.$run->workflow_id.')',
            'Run: '.$run->run_uuid.' (#'.$run->id.')',
            'Status: '.$run->status,
            '',
            'Contents:',
            '- workflow-export/workflows.csv: importable workflow CSV export.',
            '- run/workflow-run-'.$run->id.'.json: sanitized run, context, step runs and task results.',
            '- debug-artifacts/*: private Dev-Debug DOM snapshots and screenshots captured by the workflow runtime.',
            '- external-runs/*/status.json and result.json: sanitized runner snapshots where available.',
            '- dom/* and external-runs/*/artifacts/*: legacy DOM snapshots and live screenshots found for the test.',
            '',
            'Common passwords, session payloads, tokens and websocket endpoints are redacted.',
            '',
        ]);
    }
}
