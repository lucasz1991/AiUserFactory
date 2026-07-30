<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\FactoryWebPushNotification;
use App\Support\Push\PushCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

/**
 * Spur W. Was hier zusammengebaut wird, liest der Service Worker in
 * public/service-worker.js wieder aus — die Schluesselnamen sind ein Vertrag
 * zwischen beiden Seiten und duerfen nicht auseinanderlaufen.
 */
class FactoryWebPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function notification(PushCategory $category = PushCategory::Workflows): FactoryWebPushNotification
    {
        return new FactoryWebPushNotification(
            notificationId: 'workflow-run:42',
            title: 'Workflow fertig',
            body: 'GMX-Registrierung abgeschlossen.',
            url: 'netzwerk/workflows/7',
            category: $category,
        );
    }

    public function test_the_payload_carries_everything_the_service_worker_reads(): void
    {
        $user = User::factory()->create();
        $notification = $this->notification();

        $payload = $notification->toWebPush($user, $notification)->toArray();

        $this->assertSame('Workflow fertig', $payload['title']);
        $this->assertSame('GMX-Registrierung abgeschlossen.', $payload['body']);
        $this->assertSame('workflow-run:42', $payload['tag']);
        $this->assertSame('pwa-icons/pwa-192.png', $payload['icon']);
        $this->assertSame('pwa-icons/push-badge-96.png', $payload['badge']);

        $this->assertSame([
            'notification_id' => 'workflow-run:42',
            'url' => 'netzwerk/workflows/7',
            'category' => 'workflows',
            'badge_count' => null,
        ], $payload['data']);
    }

    /**
     * Icon und Badge muessen relativ bleiben: der Service Worker loest sie
     * gegen seinen Scope auf und verwirft alles ausserhalb.
     */
    public function test_icon_and_badge_stay_inside_the_service_worker_scope(): void
    {
        $user = User::factory()->create();
        $notification = $this->notification();
        $payload = $notification->toWebPush($user, $notification)->toArray();

        foreach (['icon', 'badge'] as $key) {
            $this->assertStringStartsWith('pwa-icons/', $payload[$key]);
            $this->assertStringNotContainsString('://', $payload[$key]);
        }
    }

    public function test_a_waiting_copilot_checkpoint_is_delivered_with_high_urgency(): void
    {
        $user = User::factory()->create();
        $notification = $this->notification(PushCategory::Copilot);

        $this->assertSame('high', $notification->toWebPush($user, $notification)->getOptions()['urgency']);

        $routine = $this->notification(PushCategory::Workflows);

        $this->assertSame('normal', $routine->toWebPush($user, $routine)->getOptions()['urgency']);
    }

    public function test_nothing_is_sent_without_a_device_even_when_the_category_is_enabled(): void
    {
        config(['webpush.enabled' => true]);

        $user = User::factory()->create();
        $user->enableDefaultPushPreferences();

        $this->assertSame([], $this->notification()->via($user));
    }

    public function test_nothing_is_sent_when_the_user_switched_the_category_off(): void
    {
        config([
            'webpush.enabled' => true,
            'webpush.auto_provision' => false,
            'webpush.vapid.subject' => 'mailto:betrieb@follow-flow.de',
            'webpush.vapid.public_key' => 'BFnRnMKRs1PnHWo8Sy4c2Q4YFbcSXjLlRi_yBEUZk6iPBw04sFbCVGnBWCEd3vFF1FIvmqMhH0BEeIBGGCA1a9M',
            'webpush.vapid.private_key' => 'sYAEeaSjGaHrhCsLGwlOgWFOHl1YS_gJvOd_Xrf1KTs',
        ]);

        $user = User::factory()->create();
        $user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/abc',
            'key',
            'token',
            'aes128gcm',
        );

        $this->assertSame([], $this->notification()->via($user));

        $user->enableDefaultPushPreferences();

        $this->assertSame([WebPushChannel::class], $this->notification()->via($user->fresh()));
    }

    /**
     * Ein Testversand muss auch dann durchgehen, wenn der Benutzer alle
     * Kategorien abgeschaltet hat — sonst laesst sich nicht pruefen, ob die
     * Zustellung technisch funktioniert.
     */
    public function test_a_test_push_bypasses_the_category_preferences(): void
    {
        config([
            'webpush.enabled' => true,
            'webpush.auto_provision' => false,
            'webpush.vapid.subject' => 'mailto:betrieb@follow-flow.de',
            'webpush.vapid.public_key' => 'BFnRnMKRs1PnHWo8Sy4c2Q4YFbcSXjLlRi_yBEUZk6iPBw04sFbCVGnBWCEd3vFF1FIvmqMhH0BEeIBGGCA1a9M',
            'webpush.vapid.private_key' => 'sYAEeaSjGaHrhCsLGwlOgWFOHl1YS_gJvOd_Xrf1KTs',
        ]);

        $user = User::factory()->create();
        $user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/abc',
            'key',
            'token',
            'aes128gcm',
        );

        $notification = new FactoryWebPushNotification(
            notificationId: 'test:1',
            title: 'Test',
            body: 'Test',
            url: 'app-installation',
            category: PushCategory::System,
            bypassPreferences: true,
        );

        $this->assertSame([WebPushChannel::class], $notification->via($user));
    }
}
