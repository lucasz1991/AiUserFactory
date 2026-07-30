<?php

namespace Tests\Feature;

use App\Events\RealtimePing;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Spur X. Der Echtzeit-Transport faellt still aus, wenn eine der
 * Verdrahtungen fehlt — deshalb pinnt dieser Test genau diese Stellen.
 */
class RealtimeBroadcastWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reverb_connection_is_configured(): void
    {
        $connection = config('broadcasting.connections.reverb');

        $this->assertIsArray($connection, 'config/broadcasting.php kennt keine reverb-Verbindung.');
        $this->assertSame('reverb', $connection['driver']);
        $this->assertArrayHasKey('host', $connection['options']);
        $this->assertArrayHasKey('port', $connection['options']);
    }

    /**
     * Ohne den BroadcastServiceProvider gibt es keine /broadcasting/auth-Route,
     * und jede Anmeldung an einem privaten Kanal scheitert.
     */
    public function test_the_broadcast_service_provider_is_registered(): void
    {
        $this->assertContains(
            \App\Providers\BroadcastServiceProvider::class,
            config('app.providers'),
            'BroadcastServiceProvider ist in config/app.php nicht aktiviert.',
        );

        $this->assertTrue(
            $this->app['router']->has('broadcasting.auth')
                || collect($this->app['router']->getRoutes())->contains(
                    fn ($route): bool => $route->uri() === 'broadcasting/auth',
                ),
            'Die Route broadcasting/auth fehlt.',
        );
    }

    /**
     * Wichtig: ohne den echten Treiber laeuft der Test gegen den
     * `null`-Broadcaster, und der beantwortet **jede** Anfrage mit 200. Der
     * Test wuerde dann gruen sein, ohne irgendetwas zu pruefen.
     */
    private function useReverbBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'testkey',
            'broadcasting.connections.reverb.secret' => 'testsecret',
            'broadcasting.connections.reverb.app_id' => 'testapp',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8081,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        // `Broadcast::channel()` registriert die Kanaele auf der Treiber-
        // Instanz, die beim Booten aufgeloest wurde. Nach dem Wechsel des
        // Treibers entsteht eine neue Instanz ohne Kanaele — deshalb hier
        // dasselbe tun wie der BroadcastServiceProvider.
        require base_path('routes/channels.php');
    }

    public function test_a_user_may_listen_on_the_own_channel(): void
    {
        $this->useReverbBroadcaster();

        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-App.Models.User.'.$owner->getKey(),
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_a_foreign_user_channel_is_refused(): void
    {
        $this->useReverbBroadcaster();

        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($owner)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-App.Models.User.'.$stranger->getKey(),
            ])
            ->assertForbidden();
    }

    /**
     * Laravel legt auf `broadcasting/auth` keine `auth`-Middleware; der
     * Broadcaster selbst weist einen fehlenden Benutzer auf einem gesicherten
     * Kanal mit 403 ab. Deshalb hier bewusst 403 und nicht 401.
     */
    public function test_a_guest_is_refused(): void
    {
        $this->useReverbBroadcaster();

        $owner = User::factory()->create();

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-App.Models.User.'.$owner->getKey(),
        ])->assertForbidden();
    }

    public function test_the_diagnostic_event_broadcasts_immediately_on_a_private_user_channel(): void
    {
        $event = new RealtimePing(7, '2026-07-30T07:00:00+02:00', 'test');

        $this->assertInstanceOf(
            ShouldBroadcastNow::class,
            $event,
            'Das Diagnose-Ereignis muss ohne Queue senden, sonst misst es den Worker statt den Transport.',
        );

        $channel = $event->broadcastOn();

        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame('private-App.Models.User.7', (string) $channel);
        $this->assertSame('realtime.ping', $event->broadcastAs());
        $this->assertSame(
            ['sent_at' => '2026-07-30T07:00:00+02:00', 'note' => 'test'],
            $event->broadcastWith(),
        );
    }

    public function test_the_ping_command_dispatches_the_event(): void
    {
        Event::fake([RealtimePing::class]);

        $user = User::factory()->create(['status' => true]);

        $this->artisan('realtime:ping', ['user' => $user->email])
            ->assertSuccessful();

        Event::assertDispatched(
            RealtimePing::class,
            fn (RealtimePing $event): bool => $event->userId === (int) $user->getKey(),
        );
    }

    /**
     * Der Echo-Client wird nur angelegt, wenn ein Schluessel konfiguriert ist —
     * sonst laeuft der Browser in endlose Reconnect-Versuche.
     */
    public function test_the_javascript_client_is_wired_and_guarded(): void
    {
        $source = (string) file_get_contents(resource_path('js/bootstrap.js'));

        $this->assertStringContainsString("import Echo from 'laravel-echo'", $source);
        $this->assertStringContainsString("broadcaster: 'reverb'", $source);
        $this->assertStringContainsString('VITE_REVERB_APP_KEY', $source);
        $this->assertStringContainsString('if (reverbKey)', $source);
    }
}
