<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

class OperationalHeartbeatService
{
    public const SCHEDULER_KEY = 'operations.heartbeat.scheduler';

    public const WORKER_KEY = 'operations.heartbeat.worker';

    public const ARTIFACT_PRUNE_KEY = 'operations.artifact_prune.latest';

    public const COOKIE_PRUNE_KEY = 'operations.cookie_prune.latest';

    public const COOKIE_KEY_ROTATION_KEY = 'operations.cookie_key_rotation.latest';

    public function recordScheduler(): array
    {
        return $this->record(self::SCHEDULER_KEY, ['source' => 'schedule']);
    }

    public function recordWorker(): array
    {
        return $this->record(self::WORKER_KEY, ['source' => 'queued_probe']);
    }

    /** @param array<string, int> $removed */
    public function recordArtifactPrune(array $removed): array
    {
        return $this->record(self::ARTIFACT_PRUNE_KEY, [
            'removed' => $removed,
            'storage_bytes' => $this->workflowStorageBytes(),
        ]);
    }

    /** @param array<string, int> $result */
    public function recordCookiePrune(array $result): array
    {
        return $this->record(self::COOKIE_PRUNE_KEY, ['result' => $result]);
    }

    /** @param array<string, int> $result */
    public function recordCookieKeyRotation(array $result): array
    {
        return $this->record(self::COOKIE_KEY_ROTATION_KEY, ['result' => $result]);
    }

    /** @return array<string, mixed>|null */
    public function latest(string $key): ?array
    {
        $value = Cache::get($key);

        return is_array($value) ? $value : null;
    }

    public function workflowStorageBytes(): int
    {
        $bytes = 0;

        foreach ([
            storage_path('app/workflow-task-runs'),
            storage_path('app/public/workflow-task-runs'),
            storage_path('app/workflow-runs'),
            storage_path('app/browser-profiles/workflows'),
        ] as $directory) {
            if (! File::isDirectory($directory)) {
                continue;
            }

            try {
                foreach (File::allFiles($directory) as $file) {
                    $bytes += max(0, (int) $file->getSize());
                }
            } catch (Throwable) {
                // Ein parallel geloeschtes Laufartefakt darf den Prune-Heartbeat
                // nicht verhindern. Der naechste Lauf misst erneut.
            }
        }

        return $bytes;
    }

    /** @param array<string, mixed> $extra */
    private function record(string $key, array $extra): array
    {
        $payload = [
            'recorded_at' => now()->toIso8601String(),
            'host' => gethostname() ?: 'unknown',
            ...$extra,
        ];
        Cache::forever($key, $payload);

        return $payload;
    }
}
