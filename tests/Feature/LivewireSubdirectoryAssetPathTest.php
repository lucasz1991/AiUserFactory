<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Livewire baut `/livewire/livewire.js` und `/livewire/update` ab Domainwurzel.
 * Unter einer Unterverzeichnis-Installation (lokal `http://localhost/followflow/
 * AiUserFactory/public`) zeigen beide ins Leere: `livewire.js` liefert 404,
 * damit startet Alpine nicht, und jede Seite mit `x-show`-Panels klappt
 * auseinander. `AppServiceProvider` haengt darum den Basispfad der Anfrage davor.
 *
 * Die Provider booten im Test nur einmal beim Aufbau der Anwendung; deshalb
 * wird die Anpassung hier gezielt mit einer passenden Anfrage aufgerufen statt
 * ueber `$this->get()`.
 */
class LivewireSubdirectoryAssetPathTest extends TestCase
{
    public function test_asset_url_stays_untouched_without_a_subdirectory(): void
    {
        config(['livewire.asset_url' => null]);

        $this->applyWithRequestFor('http://localhost/login', '/index.php');

        $this->assertNull(config('livewire.asset_url'));
    }

    public function test_asset_url_is_prefixed_when_the_app_runs_in_a_subdirectory(): void
    {
        config(['livewire.asset_url' => null]);

        $this->applyWithRequestFor(
            'http://localhost/followflow/AiUserFactory/public/login',
            '/followflow/AiUserFactory/public/index.php',
        );

        $this->assertSame(
            '/followflow/AiUserFactory/public/livewire/livewire.js',
            config('livewire.asset_url')
        );
    }

    public function test_the_update_route_is_registered_under_the_same_prefix(): void
    {
        config(['livewire.asset_url' => null]);

        $this->applyWithRequestFor(
            'http://localhost/followflow/AiUserFactory/public/login',
            '/followflow/AiUserFactory/public/index.php',
        );

        $uris = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->filter(fn (string $uri): bool => str_contains($uri, 'livewire/update'))
            ->values()
            ->all();

        $this->assertContains('followflow/AiUserFactory/public/livewire/update', $uris);
    }

    /**
     * Prueft nebenbei, dass eine echte Anfrage unter diesem Skriptpfad genau den
     * erwarteten Basispfad liefert, und wendet ihn dann an. Der Provider selbst
     * steigt in der Testumgebung ueber `runningInConsole()` aus, deshalb wird
     * hier die reine Anwendung aufgerufen.
     */
    protected function applyWithRequestFor(string $url, string $scriptName): void
    {
        $request = Request::create($url, 'GET', [], [], [], [
            'SCRIPT_NAME' => $scriptName,
            'SCRIPT_FILENAME' => $scriptName,
            'PHP_SELF' => $scriptName,
        ]);

        $basePath = rtrim($request->getBaseUrl(), '/');

        $this->assertSame(
            rtrim(str_replace('/index.php', '', $scriptName), '/'),
            $basePath,
            'Symfony leitet den Basispfad nicht wie erwartet aus SCRIPT_NAME ab.'
        );

        $provider = new AppServiceProvider(app());
        $method = new \ReflectionMethod($provider, 'applyLivewireBasePath');
        $method->setAccessible(true);
        $method->invoke($provider, $basePath);
    }
}
