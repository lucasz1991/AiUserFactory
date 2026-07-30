<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Pwa\PwaIcon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Spur W. Der Frontend-Teil faellt ohne Fehlermeldung aus, wenn eine der
 * Verdrahtungen fehlt — deshalb pinnt dieser Test genau diese Zeilen.
 */
class PwaFrontendTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_icon_route_is_public_and_serves_png(): void
    {
        $response = $this->get('/pwa-icons/pwa-192.png');

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_the_icon_route_refuses_anything_undeclared(): void
    {
        $this->get('/pwa-icons/beliebig.png')->assertNotFound();
        $this->get('/pwa-icons/pwa-192.png.bak')->assertNotFound();
    }

    public function test_the_manifest_is_valid_and_only_references_known_icons(): void
    {
        $path = public_path('manifest.webmanifest');

        $this->assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('./', $manifest['scope']);
        $this->assertNotEmpty($manifest['icons']);

        foreach ($manifest['icons'] as $icon) {
            $name = basename($icon['src']);

            $this->assertTrue(
                PwaIcon::supports($name),
                $name.' steht im Manifest, aber nicht in PwaIcon::DIMENSIONS — die Icon-Route liefert dafuer 404.',
            );
            $this->assertStringStartsWith('pwa-icons/', $icon['src'], 'Manifest-Icons muessen relativ zum Scope bleiben.');
        }

        $this->assertContains(
            'maskable',
            array_column($manifest['icons'], 'purpose'),
            'Ohne maskable-Icon beschneidet Android das Startsymbol.',
        );
    }

    public function test_the_service_worker_exists_and_handles_push_and_clicks(): void
    {
        $path = public_path('service-worker.js');

        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);

        $this->assertStringContainsString("addEventListener('push'", $source);
        $this->assertStringContainsString("addEventListener('notificationclick'", $source);
        $this->assertStringContainsString('isInsideRegistrationScope', $source);
    }

    public function test_the_head_partial_wires_manifest_service_worker_and_account_binding(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->withoutVite()
            ->blade('@include("layouts.pwa-head")');

        $html->assertSee('rel="manifest"', false);
        $html->assertSee('manifest.webmanifest', false);
        $html->assertSee('name="ff-service-worker-url"', false);
        $html->assertSee('service-worker.js', false);
        $html->assertSee('name="ff-push-account-binding"', false);
        $html->assertSee('name="theme-color"', false);
        $html->assertSee('apple-mobile-web-app-capable', false);
    }

    public function test_a_guest_gets_no_account_binding(): void
    {
        $this->blade('@include("layouts.pwa-head")')
            ->assertDontSee('ff-push-account-binding', false);
    }

    /**
     * Beide Layouts binden das Partial ein. Faellt eine Einbindung beim
     * App-Shell-Umbau weg, ist Push tot — ohne Fehlermeldung.
     */
    public function test_both_layouts_include_the_pwa_head_partial(): void
    {
        foreach (['master', 'master-without-nav'] as $layout) {
            $source = (string) file_get_contents(
                resource_path('views/layouts/'.$layout.'.blade.php'),
            );

            $this->assertStringContainsString(
                "@include('layouts.pwa-head')",
                $source,
                'layouts/'.$layout.'.blade.php bindet layouts.pwa-head nicht mehr ein.',
            );
        }
    }

    /**
     * Ohne diese Registrierung gaebe es keinen Service Worker und keine der
     * beiden Alpine-Komponenten.
     */
    public function test_the_javascript_entrypoint_registers_the_pwa_module(): void
    {
        $source = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("from './pwa'", $source);
        $this->assertStringContainsString('setupFactoryPwa()', $source);
        $this->assertStringContainsString('registerFactoryPushSettings(window.Alpine)', $source);
        $this->assertStringContainsString('registerFactoryPwaInstall(window.Alpine)', $source);
    }

    public function test_the_settings_page_renders_the_alpine_components(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withoutVite()
            ->get('/app-installation')
            ->assertOk()
            ->assertSee('factoryPushSettings(', false)
            ->assertSee('factoryPwaInstall(', false)
            ->assertSee('App installieren');
    }

    public function test_the_blade_of_the_push_panel_compiles(): void
    {
        $this->assertIsString(Blade::compileString(
            (string) file_get_contents(
                resource_path('views/livewire/settings/push-settings.blade.php'),
            ),
        ));

        $this->assertIsString(Blade::compileString(
            (string) file_get_contents(
                resource_path('views/livewire/settings/push-settings-page.blade.php'),
            ),
        ));
    }
}
