<?php

namespace Tests\Feature;

use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\FactoryWebPushNotification;
use App\Support\Push\PushCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Spur W. Deckt den vollstaendigen Lebenszyklus eines Geraete-Abos ab.
 */
class PushSubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'webpush.enabled' => true,
            'webpush.test_enabled' => true,
            // Feste Schluessel, damit der Test nicht von der
            // Auto-Provisionierung und dem Dateisystem abhaengt.
            'webpush.auto_provision' => false,
            'webpush.vapid.subject' => 'mailto:betrieb@follow-flow.de',
            'webpush.vapid.public_key' => self::VAPID_PUBLIC,
            'webpush.vapid.private_key' => self::VAPID_PRIVATE,
        ]);
    }

    private const VAPID_PUBLIC = 'BFnRnMKRs1PnHWo8Sy4c2Q4YFbcSXjLlRi_yBEUZk6iPBw04sFbCVGnBWCEd3vFF1FIvmqMhH0BEeIBGGCA1a9M';

    private const VAPID_PRIVATE = 'sYAEeaSjGaHrhCsLGwlOgWFOHl1YS_gJvOd_Xrf1KTs';

    /**
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}}
     */
    private function browserSubscription(string $suffix = 'abc123'): array
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $details = openssl_pkey_get_details($key);
        $point = "\x04".str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            .str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        return [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$suffix,
            'keys' => [
                'p256dh' => rtrim(strtr(base64_encode($point), '+/', '-_'), '='),
                'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
            ],
        ];
    }

    public function test_a_guest_cannot_reach_the_push_endpoints(): void
    {
        $this->getJson('/settings/push/status')->assertUnauthorized();
        $this->postJson('/settings/push/subscriptions', [])->assertUnauthorized();
    }

    public function test_status_reports_the_server_state_and_every_category(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/settings/push/status');

        $response->assertOk()
            ->assertJson([
                'enabled' => true,
                'configured' => true,
                'ready' => true,
                'subscription_count' => 0,
            ]);

        foreach (PushCategory::cases() as $category) {
            $response->assertJsonPath('preferences.'.$category->value, false);
        }
    }

    public function test_subscribing_stores_the_device_and_enables_every_category(): void
    {
        $user = User::factory()->create();
        $payload = $this->browserSubscription();

        $response = $this->actingAs($user)
            ->withHeader('User-Agent', 'Mozilla/5.0 Test')
            ->postJson('/settings/push/subscriptions', $payload + [
                'contentEncoding' => 'aes128gcm',
                'platform' => 'desktop',
                'browser' => 'Google Chrome',
                'deviceName' => 'Desktop',
            ]);

        $response->assertCreated()->assertJson(['subscribed' => true]);

        $subscription = PushSubscription::firstOrFail();

        $this->assertSame($payload['endpoint'], $subscription->endpoint);
        $this->assertSame(hash('sha256', $payload['endpoint']), $subscription->endpoint_hash);
        $this->assertSame('desktop', $subscription->platform);
        $this->assertSame('Google Chrome', $subscription->browser);
        $this->assertNotNull($subscription->last_seen_at);
        $this->assertNull($subscription->revoked_at);
        $this->assertSame($user->getKey(), $subscription->subscribable_id);

        $this->assertSame(
            count(PushCategory::cases()),
            NotificationPreference::where('user_id', $user->getKey())
                ->where('web_push_enabled', true)
                ->count(),
        );
    }

    /**
     * Der Endpunkt liegt verschluesselt in der Datenbank. Er darf dort also
     * nicht im Klartext auffindbar sein — sonst waere der Cast wirkungslos.
     */
    public function test_the_stored_endpoint_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $payload = $this->browserSubscription();

        $this->actingAs($user)->postJson('/settings/push/subscriptions', $payload)->assertCreated();

        $raw = (string) $this->app['db']
            ->table(config('webpush.table_name'))
            ->value('endpoint');

        $this->assertNotSame($payload['endpoint'], $raw);
        $this->assertStringNotContainsString('fcm.googleapis.com', $raw);
    }

    public function test_an_endpoint_outside_the_allow_list_is_rejected(): void
    {
        $user = User::factory()->create();
        $payload = $this->browserSubscription();
        $payload['endpoint'] = 'https://evil.example.com/collect';

        $this->actingAs($user)
            ->postJson('/settings/push/subscriptions', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('endpoint');

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_unsubscribing_revokes_the_device_without_deleting_the_row(): void
    {
        $user = User::factory()->create();
        $payload = $this->browserSubscription();

        $id = $this->actingAs($user)
            ->postJson('/settings/push/subscriptions', $payload)
            ->json('subscription_id');

        $this->actingAs($user)
            ->deleteJson('/settings/push/subscriptions', ['subscription_id' => $id])
            ->assertNoContent();

        $subscription = PushSubscription::findOrFail($id);

        $this->assertNotNull($subscription->revoked_at);
        $this->assertSame(
            0,
            $user->fresh()->pushSubscriptions()->whereNull('revoked_at')->count(),
        );
    }

    public function test_preferences_are_persisted_and_unknown_categories_are_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/settings/push/preferences', [
                'preferences' => [PushCategory::Workflows->value => true],
            ])
            ->assertOk()
            ->assertJson(['saved' => true]);

        $this->assertTrue($user->fresh()->wantsWebPush(PushCategory::Workflows));
        $this->assertFalse($user->fresh()->wantsWebPush(PushCategory::System));

        $this->actingAs($user)
            ->patchJson('/settings/push/preferences', [
                'preferences' => ['erfundene-kategorie' => true],
            ])
            ->assertStatus(422);
    }

    public function test_the_test_push_targets_only_the_requesting_device(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $first = $this->actingAs($user)
            ->postJson('/settings/push/subscriptions', $this->browserSubscription('first'))
            ->json('subscription_id');
        $this->actingAs($user)
            ->postJson('/settings/push/subscriptions', $this->browserSubscription('second'))
            ->assertCreated();

        $this->actingAs($user)
            ->postJson('/settings/push/test', ['subscription_id' => $first])
            ->assertStatus(202)
            ->assertJson(['queued' => true]);

        Notification::assertSentTo(
            $user,
            FactoryWebPushNotification::class,
            fn (FactoryWebPushNotification $notification): bool => $notification->targetSubscriptionId === $first
                && $notification->bypassPreferences === true,
        );
    }

    public function test_the_test_push_is_not_available_when_switched_off(): void
    {
        config(['webpush.test_enabled' => false]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/settings/push/test', ['subscription_id' => 1])
            ->assertNotFound();
    }

    public function test_subscribing_is_impossible_while_web_push_is_switched_off(): void
    {
        config(['webpush.enabled' => false]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/settings/push/subscriptions', $this->browserSubscription())
            ->assertNotFound();
    }
}
