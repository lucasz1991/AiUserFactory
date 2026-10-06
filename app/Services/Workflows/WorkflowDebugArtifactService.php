<?php

namespace App\Services\Workflows;

use App\Models\WorkflowRunArtifact;
use App\Models\WorkflowStepRun;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WorkflowDebugArtifactService
{
    public function ingestManifest(WorkflowStepRun $stepRun, array $manifest): void
    {
        $stepRun->loadMissing(['workflowRun.workflow', 'workflowStep']);
        $run = $stepRun->workflowRun;

        if (! $run) {
            return;
        }

        $artifacts = is_array($manifest['artifacts'] ?? null)
            ? $manifest['artifacts']
            : (array_is_list($manifest) ? $manifest : []);

        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }

            $phase = $this->cleanSegment($artifact['phase'] ?? '', 20);
            $type = $this->cleanSegment($artifact['artifact_type'] ?? $artifact['artifactType'] ?? '', 40);

            if ($phase === '' || $type === '') {
                continue;
            }

            $storagePath = trim((string) ($artifact['storage_path'] ?? $artifact['storagePath'] ?? ''));
            $browserWindow = $this->stringOrNull($artifact['browser_window'] ?? $artifact['browserWindow'] ?? 'main') ?: 'main';
            $status = $this->cleanSegment($artifact['status'] ?? 'success', 40) ?: 'success';
            $disk = $this->stringOrNull($artifact['storage_disk'] ?? $artifact['storageDisk'] ?? 'local', 80) ?: 'local';
            // These are identities, not display labels. Truncation would merge
            // different nested invocations or make their files unresolvable.
            $taskCardKey = $this->stringOrNull($artifact['task_card_key'] ?? $artifact['taskCardKey'] ?? null, null);

            try {
                WorkflowRunArtifact::query()->updateOrCreate(
                    [
                        'workflow_step_run_id' => $stepRun->id,
                        'phase' => $phase,
                        'artifact_type' => $type,
                        'browser_window' => $browserWindow,
                        'task_card_key' => $taskCardKey,
                        'storage_path' => $storagePath !== '' ? $storagePath : null,
                    ],
                    [
                        'workflow_id' => $run->workflow_id,
                        'workflow_run_id' => $run->id,
                        'workflow_step_id' => $stepRun->workflow_step_id,
                        'step_position' => $this->intOrNull($artifact['step_position'] ?? $artifact['stepPosition'] ?? $stepRun->workflowStep?->position),
                        'step_action_key' => $this->stringOrNull($artifact['step_action_key'] ?? $artifact['stepActionKey'] ?? $stepRun->workflowStep?->action_key),
                        'task_card_key' => $taskCardKey,
                        'current_url' => $this->stringOrNull($artifact['current_url'] ?? $artifact['currentUrl'] ?? $artifact['url'] ?? null, 4096),
                        'title' => $this->stringOrNull($artifact['title'] ?? null),
                        'storage_disk' => $disk,
                        'status' => $status,
                        'error_message' => $this->stringOrNull($artifact['error_message'] ?? $artifact['errorMessage'] ?? null, 8000),
                        'metadata_json' => $this->metadata($artifact),
                    ],
                );
            } catch (QueryException $exception) {
                // Diagnostic attachments must not block control or the next
                // artifact. Never log SQL, bindings, paths or raw exceptions.
                $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
                $driverCode = $exception->errorInfo[1] ?? null;
                Log::warning('Workflow debug artifact persistence failed.', [
                    'workflow_run_id' => (int) $run->id,
                    'workflow_step_run_id' => (int) $stepRun->id,
                    'sql_state' => preg_match('/\A[0-9A-Z]{5}\z/D', $sqlState) === 1 ? $sqlState : null,
                    'driver_code' => is_int($driverCode) || (is_string($driverCode) && ctype_digit($driverCode)) ? (int) $driverCode : null,
                ]);
            }
        }
    }

    public function artifactUrl(WorkflowRunArtifact $artifact, bool $download = false): string
    {
        $route = $download ? 'workflow-run-artifacts.download' : 'workflow-run-artifacts.show';

        if (! Route::has($route)) {
            return '#';
        }

        return route($route, [
            'run' => $artifact->workflow_run_id,
            'artifact' => $artifact->id,
        ]);
    }

    public function absolutePath(WorkflowRunArtifact $artifact): ?string
    {
        $path = trim((string) $artifact->storage_path);

        if ($path === '') {
            return null;
        }

        $disk = Storage::disk($artifact->storage_disk ?: 'local');

        if (! $disk->exists($path)) {
            return null;
        }

        return method_exists($disk, 'path') ? $disk->path($path) : storage_path('app/'.$path);
    }

    public function downloadName(WorkflowRunArtifact $artifact): string
    {
        $extension = match ($artifact->artifact_type) {
            'screenshot' => 'png',
            'dom' => 'html',
            default => pathinfo((string) $artifact->storage_path, PATHINFO_EXTENSION) ?: 'txt',
        };

        $name = implode('-', array_filter([
            'run',
            (string) $artifact->workflow_run_id,
            'step',
            (string) ($artifact->step_position ?? $artifact->workflow_step_id ?? 'x'),
            $artifact->step_action_key ?: null,
            $artifact->browser_window ?: null,
            $artifact->phase,
            $artifact->artifact_type,
        ]));

        return (Str::slug($name) ?: 'workflow-artifact').'.'.$extension;
    }

    public function mimeType(WorkflowRunArtifact $artifact): string
    {
        return match ($artifact->artifact_type) {
            'screenshot' => 'image/png',
            'dom' => 'text/html; charset=UTF-8',
            default => ($path = $this->absolutePath($artifact)) ? (File::mimeType($path) ?: 'application/octet-stream') : 'application/octet-stream',
        };
    }

    protected function metadata(array $artifact): array
    {
        $metadata = is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : [];

        foreach ([
            'created_at',
            'createdAt',
            'readyState',
            'ready_state',
            'visibleTextExcerpt',
            'visible_text_excerpt',
            'uiState',
            'ui_state',
            'selectorSuggestions',
            'selector_suggestions',
            'task_index',
            'task_type',
            'task_title',
            'embedded_workflow_id',
            'embedded_workflow_name',
            'embedded_workflow_frame_key',
            'parent_task_key',
            'storage_path',
            'storagePath',
        ] as $key) {
            if (array_key_exists($key, $artifact)) {
                $metadata[$key] = $artifact[$key];
            }
        }

        return $metadata;
    }

    protected function cleanSegment(mixed $value, int $limit = 80): string
    {
        return Str::of((string) $value)
            ->lower()
            ->replaceMatches('/[^a-z0-9_-]+/', '_')
            ->trim('_')
            ->substr(0, $limit)
            ->toString();
    }

    protected function stringOrNull(mixed $value, ?int $limit = 191): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $limit === null ? $value : Str::limit($value, max(1, $limit), '');
    }

    protected function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
