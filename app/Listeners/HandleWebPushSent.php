<?php

namespace App\Listeners;

use NotificationChannels\WebPush\Events\NotificationSent;

class HandleWebPushSent
{
    public function handle(NotificationSent $event): void
    {
        if (! $event->subscription->exists) {
            return;
        }

        $event->subscription->forceFill([
            'last_success_at' => now(),
            'last_seen_at' => now(),
            'failure_count' => 0,
        ])->save();
    }
}
