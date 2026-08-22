<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NetworkNode;
use App\Services\ClientController\NetworkJobDoorbellService;
use App\Services\ClientController\NetworkNodeCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientControllerRealtimeController extends Controller
{
    public function __construct(
        private readonly NetworkNodeCredentialService $credentials,
        private readonly NetworkJobDoorbellService $doorbells,
    ) {}

    public function config(Request $request): JsonResponse
    {
        $node = $this->authenticate($request);

        if (! $node) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        if (! $this->doorbells->enabled()) {
            return response()->json([
                'enabled' => false,
                'watchdog_seconds' => 30,
            ]);
        }

        return response()->json([
            'enabled' => true,
            'websocket_url' => $this->websocketUrl($request),
            'channel' => 'private-'.$this->doorbells->channelName($node),
            'event' => 'client-controller.job-poll',
            'activity_timeout_seconds' => (int) config('client_controller.realtime.activity_timeout_seconds', 30),
            'watchdog_seconds' => 30,
        ]);
    }

    public function auth(Request $request): JsonResponse
    {
        $node = $this->authenticate($request);

        if (! $node || ! $this->doorbells->enabled()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/', 'max:80'],
            'channel' => ['required', 'string', 'max:191'],
        ]);
        $expectedChannel = 'private-'.$this->doorbells->channelName($node);

        if (! hash_equals($expectedChannel, (string) $validated['channel'])) {
            return response()->json(['success' => false, 'message' => 'Unauthorized channel.'], 403);
        }

        $key = (string) config('client_controller.realtime.app_key');
        $secret = (string) config('client_controller.realtime.app_secret');
        $signature = hash_hmac('sha256', $validated['socket_id'].':'.$expectedChannel, $secret);

        return response()->json(['auth' => $key.':'.$signature]);
    }

    private function authenticate(Request $request): ?NetworkNode
    {
        return $this->credentials->authenticate($request->header('X-NODE-API-KEY'));
    }

    private function websocketUrl(Request $request): string
    {
        $httpScheme = strtolower((string) config('client_controller.realtime.scheme', 'https'));
        $scheme = $httpScheme === 'http' ? 'ws' : 'wss';
        $host = trim((string) config('client_controller.realtime.host')) ?: $request->getHost();
        $port = max(1, (int) config('client_controller.realtime.port', $scheme === 'wss' ? 443 : 80));
        $portSuffix = ($scheme === 'wss' && $port === 443) || ($scheme === 'ws' && $port === 80) ? '' : ':'.$port;
        $path = trim((string) config('client_controller.realtime.path'), '/');
        $prefix = $path === '' ? '' : '/'.$path;
        $key = rawurlencode((string) config('client_controller.realtime.app_key'));

        return $scheme.'://'.$host.$portSuffix.$prefix.'/app/'.$key
            .'?protocol=7&client=followflow-client-controller&version=1.0&flash=false';
    }
}
