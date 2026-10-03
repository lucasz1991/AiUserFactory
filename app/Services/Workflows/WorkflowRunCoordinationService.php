<?php

namespace App\Services\Workflows;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Serializes short orchestration operations, not the lifetime of the browser.
 * Claims are database-backed so different workers and cache stores agree.
 */
class WorkflowRunCoordinationService
{
    public function acquire(int $runId, string $operation, int $ttlSeconds = 180): ?string
    {
        $token = (string) Str::uuid();
        $expiresAt = now()->addSeconds(max(30, $ttlSeconds));

        DB::table('workflow_run_leases')->insertOrIgnore([
            'workflow_run_id' => $runId,
            'token' => null,
            'operation' => null,
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $claimed = DB::table('workflow_run_leases')
            ->where('workflow_run_id', $runId)
            ->where(fn ($query) => $query->whereNull('token')->orWhere('expires_at', '<=', now()))
            ->update([
                'token' => $token,
                'operation' => Str::limit($operation, 40, ''),
                'expires_at' => $expiresAt,
                'updated_at' => now(),
            ]);

        return $claimed === 1 ? $token : null;
    }

    public function owns(int $runId, string $token): bool
    {
        return DB::table('workflow_run_leases')
            ->where('workflow_run_id', $runId)
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function release(int $runId, string $token): void
    {
        DB::table('workflow_run_leases')
            ->where('workflow_run_id', $runId)
            ->where('token', $token)
            ->update([
                'token' => null,
                'operation' => null,
                'expires_at' => null,
                'updated_at' => now(),
            ]);
    }
}
