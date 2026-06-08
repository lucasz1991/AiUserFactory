<?php

namespace App\Services\Base;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

class ScraperProfileSyncClient
{
    public function syncCollection(array $collection): array
    {
        [$url, $password] = $this->resolveBaseApiCredentials();

        if ($url === '' || $password === '') {
            throw new \RuntimeException('Base-API fuer Scraper-Profil-Sync ist nicht konfiguriert.');
        }

        $profiles = array_values(array_map(
            fn (array $profile, int $index): array => $this->technicalProfilePayload($profile, $index),
            array_values($collection['profiles'] ?? []),
            array_keys(array_values($collection['profiles'] ?? [])),
        ));

        if ($profiles === []) {
            throw new \RuntimeException('Es sind keine Personenprofile zum Senden vorhanden.');
        }

        $response = Http::acceptJson()
            ->timeout(60)
            ->post($url, [
                'password' => $password,
                'active_profile_id' => $collection['active_profile_id'] ?? null,
                'active_profile_ids' => $collection['active_profile_ids'] ?? [],
                'replace' => true,
                'profiles' => $profiles,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Base-API hat den Scraper-Profil-Sync abgelehnt: HTTP '.$response->status().' '.$response->body()
            );
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : ['ok' => true, 'synced' => count($profiles)];
    }

    private function resolveBaseApiCredentials(): array
    {
        $settings = Setting::getValue('services', 'webaidetective_base');
        $settings = is_array($settings) ? $settings : [];

        $url = trim((string) ($settings['scraper_profile_sync_url'] ?? config('services.webaidetective_base.scraper_profile_sync_url')));
        $password = trim((string) ($settings['scraper_profile_sync_password'] ?? $settings['scraper_profile_sync_token'] ?? config('services.webaidetective_base.scraper_profile_sync_password')));

        return [$url, $password];
    }

    private function technicalProfilePayload(array $profile, int $index): array
    {
        $cookiePayload = $this->nullableString($profile['cookie_payload'] ?? null);
        $cookiePayloadHash = $this->nullableString($profile['cookie_payload_hash'] ?? null);

        if ($cookiePayload !== null && $cookiePayloadHash === null) {
            $cookiePayloadHash = hash('sha256', $cookiePayload);
        }

        return array_filter([
            'profile_key' => (string) ($profile['id'] ?? ''),
            'profile_label' => (string) ($profile['profile_label'] ?? 'instagram-default'),
            'social_accounts' => is_array($profile['social_accounts'] ?? null) ? $profile['social_accounts'] : [],
            'browser_profile_path' => $this->nullableString($profile['browser_profile_path'] ?? null),
            'cookie_file_path' => $this->nullableString($profile['cookie_file_path'] ?? null),
            'persistent_profile_enabled' => (bool) ($profile['persistent_profile_enabled'] ?? true),
            'headless_enabled' => (bool) ($profile['headless_enabled'] ?? true),
            'auto_login_enabled' => (bool) ($profile['auto_login_enabled'] ?? false),
            'login_username' => $this->nullableString($profile['login_username'] ?? null),
            'login_password_base_encrypted' => $this->nullableString($profile['login_password_base_encrypted'] ?? null),
            'navigation_timeout_seconds' => max(30, (int) ($profile['navigation_timeout_seconds'] ?? 120)),
            'post_login_wait_ms' => max(500, (int) ($profile['post_login_wait_ms'] ?? 2500)),
            'typing_delay_ms' => max(0, (int) ($profile['typing_delay_ms'] ?? 35)),
            'relationship_list_process_timeout_seconds' => max(60, (int) ($profile['relationship_list_process_timeout_seconds'] ?? 14400)),
            'relationship_list_max_scroll_rounds' => max(20, (int) ($profile['relationship_list_max_scroll_rounds'] ?? 100000)),
            'follower_list_max_items' => max(0, (int) ($profile['follower_list_max_items'] ?? 0)),
            'following_list_max_items' => max(0, (int) ($profile['following_list_max_items'] ?? 0)),
            'is_active' => (bool) ($profile['is_active'] ?? true),
            'sort_order' => $index,
            'cookie_payload' => $cookiePayload,
            'cookie_payload_hash' => $cookiePayloadHash,
            'cookie_count' => max(0, (int) ($profile['cookie_count'] ?? 0)),
            'session_cookie_present' => (bool) ($profile['session_cookie_present'] ?? false),
            'cookies_synced_at' => $this->nullableString($profile['cookies_synced_at'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
