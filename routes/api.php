<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Ai\AiConnectionController;
use App\Http\Controllers\Api\ClientControllerApiController;

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

Route::prefix('ai')->group(function () {
    Route::post('/send', [AiConnectionController::class, 'send']);
    Route::post('/text', [AiConnectionController::class, 'text']);
    Route::post('/json', [AiConnectionController::class, 'json']);
    Route::post('/image-generation', [AiConnectionController::class, 'imageGeneration']);
    Route::post('/stream', [AiConnectionController::class, 'stream']);
});

Route::prefix('client-controller')->group(function (): void {
    Route::post('/register-node', [ClientControllerApiController::class, 'registerNode']);
    Route::post('/heartbeat', [ClientControllerApiController::class, 'heartbeat']);
    Route::post('/sync-devices', [ClientControllerApiController::class, 'syncDevices']);
    Route::post('/pull-jobs', [ClientControllerApiController::class, 'pullJobs']);
    Route::post('/job-result', [ClientControllerApiController::class, 'reportJobResult']);
    Route::post('/rebind', [ClientControllerApiController::class, 'rebind']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
