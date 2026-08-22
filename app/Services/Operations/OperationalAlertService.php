<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OperationalAlertService
{
    public function __construct(
        private readonly OperationalMetricsService $metrics,
    ) {}

    /** @return array{alerts:list<array<string,string>>,emitted:list<string>,suppressed:list<string>,critical:bool} */
    public function dispatch(): array
    {
        $alerts = collect($this->metrics->snapshot()['alerts'] ?? [])
            ->filter(fn (mixed $alert): bool => is_array($alert) && filled($alert['code'] ?? null))
            ->map(fn (array $alert): array => [
                'severity' => ($alert['severity'] ?? null) === 'critical' ? 'critical' : 'warning',
                'code' => preg_replace('/[^a-z0-9_.-]/i', '_', (string) $alert['code']) ?: 'unknown',
                'message' => mb_substr(trim((string) ($alert['message'] ?? 'Betriebsalarm')), 0, 500),
            ])
            ->values();
        $emitted = [];
        $suppressed = [];
        $cooldown = now()->addMinutes(
            (int) config('operations.thresholds.alert_cooldown_minutes', 30),
        );

        foreach ($alerts as $alert) {
            $fingerprint = hash('sha256', $alert['severity'].'|'.$alert['code']);

            if (! Cache::add('operations.alert.'.$fingerprint, true, $cooldown)) {
                $suppressed[] = $alert['code'];

                continue;
            }

            Log::log($alert['severity'], 'Followflow operations alert.', [
                'code' => $alert['code'],
                'message' => $alert['message'],
            ]);
            $emitted[] = $alert['code'];
        }

        return [
            'alerts' => $alerts->all(),
            'emitted' => $emitted,
            'suppressed' => $suppressed,
            'critical' => $alerts->contains(
                fn (array $alert): bool => $alert['severity'] === 'critical',
            ),
        ];
    }
}
