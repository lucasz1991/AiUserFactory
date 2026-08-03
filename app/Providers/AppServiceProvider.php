<?php

namespace App\Providers;

use App\Services\Ai\WorkflowCopilotAiUsageTracker;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WorkflowCopilotAiUsageTracker::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
        Schema::defaultStringLength(191);

        $this->fixLivewireAssetPathsForSubdirectoryInstalls();
    }

    /**
     * Livewire baut seine beiden Frontend-URLs ab Domainwurzel:
     * `/livewire/livewire.js` und `/livewire/update` (FrontendAssets::js() und
     * HandleRequests::getUpdateUri(), letzteres bewusst mit `absolute: false`).
     *
     * Laeuft die Anwendung unter einem Unterverzeichnis — lokal typischerweise
     * `http://localhost/followflow/AiUserFactory/public` unter XAMPP — zeigen
     * beide URLs ins Leere. Folge: `livewire.js` liefert 404, damit startet
     * auch Alpine nicht (Alpine kommt in diesem Projekt mit Livewire), und
     * jede Seite mit `x-show`-Panels zeigt schlagartig alle Panels
     * uebereinander. Genau so sah das Personen-Profil "zerschossen" aus.
     *
     * Der Fix haengt den echten Basispfad der Anfrage davor. Auf einer Domain
     * ohne Unterverzeichnis (Produktion, `artisan serve`) ist `getBaseUrl()`
     * leer und diese Methode aendert nichts.
     */
    protected function fixLivewireAssetPathsForSubdirectoryInstalls(): void
    {
        if ($this->app->runningInConsole() || ! $this->app->bound('request')) {
            return;
        }

        $basePath = rtrim((string) $this->app['request']->getBaseUrl(), '/');

        if ($basePath === '') {
            return;
        }

        config(['livewire.asset_url' => $basePath.'/livewire/livewire.js']);

        Livewire::setUpdateRoute(
            fn ($handle) => Route::post($basePath.'/livewire/update', $handle)
        );
    }
}
