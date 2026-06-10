<?php

namespace App\Jobs;

use App\Services\Simulation\PersonaNetworkPlanningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PlanPersonaNetworkActivities implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $days = 7,
        public string $intensity = 'balanced',
        public string $reason = 'scheduled',
    ) {
        $this->days = max(1, min(14, $this->days));
        $this->intensity = in_array($this->intensity, ['quiet', 'balanced', 'active', 'creator'], true)
            ? $this->intensity
            : 'balanced';
    }

    public function handle(PersonaNetworkPlanningService $planning): void
    {
        $summary = $planning->planActiveNetwork(
            days: $this->days,
            intensity: $this->intensity,
            reason: $this->reason,
        );

        Log::info('Persona network activity planning completed.', $summary);
    }
}
