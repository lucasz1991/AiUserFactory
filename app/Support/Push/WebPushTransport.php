<?php

namespace App\Support\Push;

use App\Models\PushSubscription;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushTransport
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     */
    public function send(PushSubscription $subscription, array $payload, array $options): MessageSentReport
    {
        $webPush = (new WebPush(
            $this->authentication(),
            [],
            30,
            config('webpush.client_options', []),
        ))
            ->setReuseVAPIDHeaders(true)
            ->setAutomaticPadding(config('webpush.automatic_padding'));

        return $webPush->sendOneNotification(
            new Subscription(
                $subscription->endpoint,
                $subscription->public_key,
                $subscription->auth_token,
                $subscription->content_encoding,
            ),
            json_encode($payload, JSON_THROW_ON_ERROR),
            $options,
        );
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected function authentication(): array
    {
        return [
            'VAPID' => [
                'subject' => (string) config('webpush.vapid.subject'),
                'publicKey' => (string) config('webpush.vapid.public_key'),
                'privateKey' => (string) config('webpush.vapid.private_key'),
            ],
        ];
    }
}
