<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Push\PushCategory;
use App\Support\Push\WebPushConfiguration;
use App\Support\Push\WebPushReport;
use App\Support\Push\WebPushTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RetryWebPushDelivery implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [120, 600];

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly int $subscriptionId,
        public readonly array $payload,
        public readonly array $options,
    ) {
        $this->onQueue(config('webpush.queue', 'default'));
    }

    public function handle(WebPushTransport $transport): void
    {
        if (! config('webpush.enabled') || ! WebPushConfiguration::isConfigured()) {
            return;
        }

        $subscription = PushSubscription::query()
            ->whereKey($this->subscriptionId)
            ->whereNull('revoked_at')
            ->first();

        if (! $subscription) {
            return;
        }

        $recipient = $subscription->subscribable;
        $category = PushCategory::tryFrom(
            (string) ($this->payload['data']['category'] ?? ''),
        );

        // Zwischen dem Fehlversuch und dem Wiederholungslauf kann der Benutzer
        // die Kategorie abbestellt haben. Der Retry darf diese Entscheidung
        // nicht ueberholen.
        if (
            ! ($recipient instanceof User)
            || ! $category
            || ! $recipient->wantsWebPush($category)
        ) {
            return;
        }

        $report = $transport->send($subscription, $this->payload, $this->options);

        if ($report->isSuccess()) {
            $subscription->forceFill([
                'last_success_at' => now(),
                'last_seen_at' => now(),
                'failure_count' => 0,
            ])->save();

            return;
        }

        if ($report->isSubscriptionExpired()) {
            $subscription->delete();

            return;
        }

        $subscription->forceFill([
            'last_failure_at' => now(),
            'failure_count' => $subscription->failure_count + 1,
        ])->save();

        if (WebPushReport::isTransient($report)) {
            throw new RuntimeException('Transient web-push delivery failure.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Web-Push-Retry endgueltig fehlgeschlagen.', [
            'subscription_id' => $this->subscriptionId,
            'notification_id' => $this->payload['data']['notification_id'] ?? null,
            'error_class' => $exception ? $exception::class : null,
        ]);
    }
}
