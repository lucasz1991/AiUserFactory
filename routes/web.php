<?php

use App\Http\Controllers\Admin\ClientController\DeviceController as ClientControllerDeviceController;
use App\Http\Controllers\Admin\ClientController\NetworkJobController as ClientControllerNetworkJobController;
use App\Http\Controllers\Admin\ClientController\NetworkTargetController as ClientControllerNetworkTargetController;
use App\Http\Controllers\Admin\ClientController\NodeController as ClientControllerNodeController;
use App\Http\Controllers\Ai\AssistantAudioInputTranscriptionController;
use App\Http\Controllers\Ai\AssistantAudioOutputStreamController;
use App\Http\Controllers\Workflows\WorkflowRunArtifactController;
use App\Livewire\Admin\ClientController\Dashboard as ClientControllerDashboard;
use App\Livewire\Admin\ClientController\NodeDetail as ClientControllerNodeDetail;
use App\Livewire\Admin\ClientController\NodeIndex as ClientControllerNodeIndex;
use App\Livewire\Admin\Config\PersonDetail;
use App\Livewire\Admin\Config\SettingsPage;
use App\Livewire\Admin\Network\ActionsPage;
use App\Livewire\Admin\Network\WorkflowManager;
use App\Livewire\Admin\Network\WorkflowsIndex;
use App\Livewire\Admin\Network\WorkflowStudio;
use App\Livewire\Admin\Processes\ProcessMonitor;
use App\Livewire\AdminConfig;
use App\Livewire\AdminDashboard;
use Illuminate\Support\Facades\Route;

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
        Route::get('/netzwerk/workflows', WorkflowsIndex::class)->name('network.workflows');
        Route::get('/netzwerk/workflows/{workflow}/studio', WorkflowStudio::class)->name('network.workflows.studio');
        Route::get('/netzwerk/workflows/{workflow}', WorkflowManager::class)->name('network.workflows.manage');
        Route::get('/workflow-runs/{run}/artifacts/{artifact}', [WorkflowRunArtifactController::class, 'show'])->name('workflow-run-artifacts.show');
        Route::get('/workflow-runs/{run}/artifacts/{artifact}/download', [WorkflowRunArtifactController::class, 'download'])->name('workflow-run-artifacts.download');
        Route::post('/assistant/audio-input/transcribe', AssistantAudioInputTranscriptionController::class)->name('assistant.audio-input.transcribe');
        Route::post('/assistant/audio-output/stream', AssistantAudioOutputStreamController::class)->name('assistant.audio-output.stream');
        Route::get('/prozesse', ProcessMonitor::class)->name('processes.index');
        Route::get('/einstellungen/{tab?}', SettingsPage::class)->name('admin.settings');

        Route::prefix('client-controller')->name('client-controller.')->group(function (): void {
            Route::get('/', ClientControllerDashboard::class)->name('dashboard');

            Route::get('/nodes', ClientControllerNodeIndex::class)->name('nodes.index');
            Route::post('/nodes', [ClientControllerNodeController::class, 'store'])->name('nodes.store');
            Route::get('/nodes/{node}', ClientControllerNodeDetail::class)->name('nodes.show');
            Route::put('/nodes/{node}', [ClientControllerNodeController::class, 'update'])->name('nodes.update');
            Route::post('/nodes/{node}/regenerate-api-key', [ClientControllerNodeController::class, 'regenerateApiKey'])->name('nodes.regenerate-api-key');
            Route::delete('/nodes/{node}', [ClientControllerNodeController::class, 'destroy'])->name('nodes.destroy');

            Route::get('/devices', [ClientControllerDeviceController::class, 'index'])->name('devices.index');
            Route::post('/devices', [ClientControllerDeviceController::class, 'store'])->name('devices.store');
            Route::put('/devices/{device}', [ClientControllerDeviceController::class, 'update'])->name('devices.update');
            Route::delete('/devices/{device}', [ClientControllerDeviceController::class, 'destroy'])->name('devices.destroy');

            Route::get('/targets', [ClientControllerNetworkTargetController::class, 'index'])->name('targets.index');
            Route::post('/targets', [ClientControllerNetworkTargetController::class, 'store'])->name('targets.store');
            Route::put('/targets/{target}', [ClientControllerNetworkTargetController::class, 'update'])->name('targets.update');
            Route::delete('/targets/{target}', [ClientControllerNetworkTargetController::class, 'destroy'])->name('targets.destroy');

            Route::get('/jobs', [ClientControllerNetworkJobController::class, 'index'])->name('jobs.index');
            Route::post('/jobs', [ClientControllerNetworkJobController::class, 'store'])->name('jobs.store');
            Route::post('/jobs/{job}/cancel', [ClientControllerNetworkJobController::class, 'cancel'])->name('jobs.cancel');
        });

        Route::redirect('/scraper-profile-transfer', '/einstellungen/scraper-transfer')->name('scraper.profile.transfer');
        Route::redirect('/scraper-profile-factory', '/einstellungen/scraper-transfer')->name('scraper.factory');
        Route::redirect('/config', '/personen')->name('admin.config');
    });
});

/*
| Self-Service-Registrierung ist deaktiviert (siehe config/fortify.php). Fortify
| registriert ohne Features::registration() keine /register-Route. Diese oeffentliche
| Umleitung faengt direkte Aufrufe von /register sauber ab und fuehrt zum Login.
| Bewusst unbenannt, damit route('register') nicht wieder aufgeloest werden kann.
*/
Route::redirect('/register', '/login');
