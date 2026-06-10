<?php

use Illuminate\Support\Facades\Route;

use App\Livewire\AdminDashboard;
use App\Livewire\AdminConfig;
use App\Livewire\Admin\Config\PersonDetail;
use App\Livewire\Admin\Config\ScraperProfileSyncSettings;
use App\Livewire\Admin\Config\SettingsPage;
use App\Livewire\Admin\Network\ActionsPage;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::middleware(['auth:sanctum', config('jetstream.auth_session')])->group(function () {
    Route::middleware(['role:admin'])->group(function () {
        Route::get('/', AdminDashboard::class)->name('admin.index');
        Route::get('/dashboard', AdminDashboard::class)->name('admin.dashboard');
        Route::get('/personen', AdminConfig::class)->name('persons.index');
        Route::get('/personen/{profileId}', PersonDetail::class)->name('persons.show');
        Route::get('/netzwerk/aktionen', ActionsPage::class)->name('network.actions');
        Route::get('/einstellungen/{tab?}', SettingsPage::class)->name('admin.settings');
        Route::redirect('/scraper-profile-transfer', '/einstellungen/scraper-transfer')->name('scraper.profile.transfer');
        Route::redirect('/scraper-profile-factory', '/einstellungen/scraper-transfer')->name('scraper.factory');
        Route::redirect('/config', '/personen')->name('admin.config');
    });
});
