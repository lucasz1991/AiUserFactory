<?php

use App\Http\Controllers\Ai\AiConnectionController;
use App\Http\Controllers\Api\ClientControllerApiController;
use App\Http\Controllers\Api\ClientControllerRealtimeController;
use App\Http\Controllers\Api\WorkflowRuntimeCallbackController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::prefix('ai')->middleware(['auth:sanctum', 'throttle:ai-proxy'])->group(function () {
    Route::post('/text', [AiConnectionController::class, 'text']);
    Route::post('/json', [AiConnectionController::class, 'json']);
    Route::post('/image-generation', [AiConnectionController::class, 'imageGeneration']);
});

Route::prefix('client-controller')->group(function (): void {
    Route::post('/register-node', [ClientControllerApiController::class, 'registerNode'])
        ->middleware('throttle:client-controller-enrollment');

    Route::middleware('throttle:client-controller-node')->group(function (): void {
        Route::post('/heartbeat', [ClientControllerApiController::class, 'heartbeat']);
        Route::post('/sync-devices', [ClientControllerApiController::class, 'syncDevices']);
        Route::post('/pull-jobs', [ClientControllerApiController::class, 'pullJobs']);
        Route::post('/job-progress', [ClientControllerApiController::class, 'reportJobProgress']);
        Route::post('/job-result', [ClientControllerApiController::class, 'reportJobResult']);
        Route::post('/rebind', [ClientControllerApiController::class, 'rebind']);
        Route::get('/realtime/config', [ClientControllerRealtimeController::class, 'config']);
        Route::post('/realtime/auth', [ClientControllerRealtimeController::class, 'auth']);
    });
});

Route::post(
    '/workflow-runtime/step-runs/{workflowStepRun}/{externalRunId}/completed',
    [WorkflowRuntimeCallbackController::class, 'completed'],
)->middleware('signed:relative')->name('workflow-runtime.completed');

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
