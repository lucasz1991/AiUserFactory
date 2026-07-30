<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use App\Notifications\FactoryWebPushNotification;
use App\Rules\PushEndpoint;
use App\Support\Push\PushCategory;
use App\Support\Push\WebPushConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class PushSubscriptionController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $diagnostics = WebPushConfiguration::diagnostics();
        $preferences = collect(PushCategory::cases())
            ->mapWithKeys(fn (PushCategory $category): array => [
                $category->value => (bool) $request->user()
                    ->notificationPreferences()
                    ->where('category', $category->value)
                    ->value('web_push_enabled'),
            ]);

        return response()->json([
            'enabled' => $diagnostics['enabled'],
            'configured' => $diagnostics['configured'],
            'ready' => $diagnostics['ready'],
            'configuration_issues' => $diagnostics['issues'],
            'auto_provisioned' => $diagnostics['auto_provisioned'],
            'subscription_count' => $request->user()
                ->pushSubscriptions()
                ->whereNull('revoked_at')
                ->count(),
            'preferences' => $preferences,
        ]);
    }

    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        abort_unless(config('webpush.enabled'), 404);
        abort_unless(WebPushConfiguration::isConfigured(), 503);

        $data = $request->validated();
        $subscription = $request->user()->updatePushSubscription(
            endpoint: $data['endpoint'],
            key: $data['keys']['p256dh'],
            token: $data['keys']['auth'],
            contentEncoding: $data['contentEncoding'] ?? 'aes128gcm',
        );

        $subscription->forceFill([
            'device_name' => $data['deviceName'] ?? null,
            'platform' => $data['platform'] ?? 'unknown',
            'browser' => $data['browser'] ?? null,
            'user_agent_hash' => filled($request->userAgent())
                ? hash('sha256', $request->userAgent())
                : null,
            'last_seen_at' => now(),
            'revoked_at' => null,
        ])->save();

        $request->user()->enableDefaultPushPreferences();

        return response()->json([
            'subscribed' => true,
            'subscription_id' => $subscription->getKey(),
        ], 201);
    }

    public function destroy(Request $request): Response
    {
        $data = $request->validate([
            'subscription_id' => ['nullable', 'required_without:endpoint', 'integer', 'min:1'],
            'endpoint' => ['nullable', 'required_without:subscription_id', 'url', 'max:4096', new PushEndpoint],
        ]);

        $subscription = $request->user()
            ->pushSubscriptions()
            ->whereNull('revoked_at');

        if (filled($data['subscription_id'] ?? null)) {
            $subscription->whereKey((int) $data['subscription_id']);
        } else {
            $subscription->where(
                'endpoint_hash',
                PushSubscription::hashEndpoint($data['endpoint']),
            );
        }

        $subscription->update(['revoked_at' => now()]);

        return response()->noContent();
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $allowed = collect(PushCategory::cases())->pluck('value')->all();
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['required', 'boolean'],
        ]);

        foreach ($data['preferences'] as $category => $enabled) {
            abort_unless(in_array($category, $allowed, true), 422);

            $request->user()->notificationPreferences()->updateOrCreate(
                ['category' => $category],
                ['web_push_enabled' => $enabled],
            );
        }

        return response()->json(['saved' => true]);
    }

    public function test(Request $request): JsonResponse
    {
        abort_unless(config('webpush.enabled') && config('webpush.test_enabled'), 404);
        abort_unless(WebPushConfiguration::isConfigured(), 503);

        $data = $request->validate([
            'subscription_id' => ['required', 'integer', 'min:1'],
        ]);
        $subscription = $request->user()
            ->pushSubscriptions()
            ->whereNull('revoked_at')
            ->find($data['subscription_id']);

        abort_if(! $subscription, 422, 'Fuer dieses Geraet ist kein aktives Push-Abo hinterlegt.');

        $request->user()->notify(new FactoryWebPushNotification(
            notificationId: 'test:'.Str::uuid(),
            title: 'Testbenachrichtigung',
            body: 'Wenn du das liest, funktionieren Push-Benachrichtigungen auf diesem Geraet.',
            url: 'app-installation',
            category: PushCategory::System,
            bypassPreferences: true,
            targetSubscriptionId: $subscription->getKey(),
        ));

        return response()->json(['queued' => true], 202);
    }
}
