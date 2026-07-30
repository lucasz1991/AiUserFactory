<?php

namespace App\Notifications;

use App\Support\Push\PushCategory;
use App\Support\Push\WebPushConfiguration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Portiert aus RailTime (`RailtimeWebPushNotification`).
 *
 * `ShouldQueueAfterCommit` ist bewusst: eine Benachrichtigung darf erst raus,
 * wenn die ausloesende Transaktion committet ist — sonst zeigt der Push auf
 * einen Datensatz, den es noch nicht gibt.
 */
class FactoryWebPushNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 4;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly string $notificationId,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly PushCategory $category,
        public readonly bool $bypassPreferences = false,
        public readonly ?int $targetSubscriptionId = null,
        public readonly ?int $ttlOverride = null,
        public readonly ?int $badgeCount = null,
    ) {
        $this->onQueue(config('webpush.queue', 'default'));
    }

    /**
     * @return array<int, class-string>
     */
    public function via(object $notifiable): array
    {
        return $this->shouldSend($notifiable, WebPushChannel::class)
            ? [WebPushChannel::class]
            : [];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($channel !== WebPushChannel::class) {
            return true;
        }

        $subscriptions = $notifiable->pushSubscriptions()->whereNull('revoked_at');

        if ($this->targetSubscriptionId !== null) {
            $subscriptions->whereKey($this->targetSubscriptionId);
        }

        if (! config('webpush.enabled')
            || ! WebPushConfiguration::isConfigured()
            || $subscriptions->doesntExist()) {
            return false;
        }

        return $this->bypassPreferences || $notifiable->wantsWebPush($this->category);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('pwa-icons/pwa-192.png')
            ->badge('pwa-icons/push-badge-96.png')
            ->tag($this->notificationId)
            ->data([
                'notification_id' => $this->notificationId,
                'url' => $this->url,
                'category' => $this->category->value,
                'badge_count' => $this->badgeCount,
            ])
            ->options([
                'TTL' => $this->ttlOverride ?? config('webpush.default_ttl', 3600),
                'urgency' => $this->category->isTimeCritical() ? 'high' : 'normal',
            ]);
    }
}
