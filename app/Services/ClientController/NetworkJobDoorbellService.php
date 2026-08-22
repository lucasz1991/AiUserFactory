<?php

namespace App\Services\ClientController;

use App\Events\NetworkJobDoorbell;
use App\Models\NetworkJob;
use App\Models\NetworkNode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class NetworkJobDoorbellService
{
    public function enabled(): bool
    {
        return (bool) config('client_controller.realtime.enabled', false)
            && config('broadcasting.default') === 'reverb'
            && filled(config('client_controller.realtime.app_key'))
            && filled(config('client_controller.realtime.app_secret'));
    }

    public function channelName(NetworkNode $node): string
    {
        return 'client-controller.node.'.substr(hash('sha256', (string) $node->node_uuid), 0, 40);
    }

    public function signal(NetworkJob $job, string $reason = 'job_available'): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $node = $job->relationLoaded('networkNode') ? $job->networkNode : $job->networkNode()->first();

        if (! $node) {
            return false;
        }

        $signaledAt = now();

        if (Schema::hasColumn('network_jobs', 'signaled_at')) {
            $job->forceFill(['signaled_at' => $signaledAt])->saveQuietly();
        }

        try {
            broadcast(new NetworkJobDoorbell(
                $this->channelName($node),
                (string) $node->node_uuid,
                $signaledAt->toIso8601String(),
                in_array($reason, ['job_available', 'control_requested'], true) ? $reason : 'job_available',
            ));

            return true;
        } catch (Throwable $exception) {
            Log::warning('ClientController-Doorbell konnte nicht gesendet werden; der Polling-Watchdog bleibt aktiv.', [
                'network_node_id' => (int) $node->id,
                'network_job_id' => (int) $job->id,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }
}
