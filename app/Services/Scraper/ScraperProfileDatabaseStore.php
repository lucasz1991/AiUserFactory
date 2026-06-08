<?php

namespace App\Services\Scraper;

use App\Models\Person;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ScraperProfileDatabaseStore
{
    private ?array $scraperProfileColumns = null;

    public function isAvailable(): bool
    {
        return Schema::hasTable('persons');
    }

    public function importLegacyCollectionIfMissing(array $collection, ?string $storageRoot = null): void
    {
        if (! $this->isAvailable() || Person::query()->where('platform', 'instagram')->exists()) {
            return;
        }

        $this->persistProfileCollection($collection, $storageRoot);
    }

    public function loadProfileCollection(?array $fallbackCollection = null): ?array
    {
        if (! $this->isAvailable()) {
            return $fallbackCollection;
        }

        $profiles = Person::query()
            ->where('platform', 'instagram')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($profiles->isEmpty()) {
            return $fallbackCollection;
        }

        $activeProfileId = optional($profiles->firstWhere('is_primary', true))->profile_key
            ?: optional($profiles->firstWhere('is_active', true))->profile_key
            ?: $profiles->first()->profile_key;
        $activeProfileIds = $profiles
            ->where('is_active', true)
            ->pluck('profile_key')
            ->values()
            ->all();

        if ($activeProfileIds === []) {
            $activeProfileIds = [$activeProfileId];
        }

        return [
            'active_profile_id' => $activeProfileId,
            'active_profile_ids' => $activeProfileIds,
            'profiles' => $profiles
                ->map(fn (Person $profile): array => $this->profileArray($profile))
                ->values()
                ->all(),
            'updated_at' => optional($profiles->sortByDesc('updated_at')->first()?->updated_at)->toIso8601String() ?: now()->toIso8601String(),
        ];
    }

    public function persistProfileCollection(array $collection, ?string $storageRoot = null): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        $profiles = array_values(array_filter(
            $collection['profiles'] ?? [],
            static fn ($profile): bool => is_array($profile) && trim((string) ($profile['id'] ?? '')) !== '',
        ));
        $activeProfileId = trim((string) ($collection['active_profile_id'] ?? ($profiles[0]['id'] ?? 'default')));
        $activeProfileIds = array_values(array_unique(array_map(
            static fn ($profileId): string => trim((string) $profileId),
            array_filter($collection['active_profile_ids'] ?? [], 'is_scalar'),
        )));
        $profileKeys = [];

        foreach ($profiles as $index => $profile) {
            $profileKey = trim((string) ($profile['id'] ?? ''));

            if ($profileKey === '') {
                continue;
            }

            $profileKeys[] = $profileKey;
            $record = Person::withTrashed()->firstOrNew([
                'platform' => 'instagram',
                'profile_key' => $profileKey,
            ]);

            if ($record->exists && $record->trashed()) {
                $record->restore();
            }

            $metadata = is_array($record->metadata) ? $record->metadata : [];
            $factoryPersona = $this->factoryPersonaPayload($profile);
            $socialAccounts = $this->socialAccountsPayload($profile);

            $record->forceFill($this->existingColumnValues([
                'profile_label' => $this->stringValue($profile['profile_label'] ?? 'instagram-default', 'instagram-default'),
                ...$factoryPersona,
                'social_accounts' => $socialAccounts,
                'browser_profile_path' => $this->nullableString($profile['browser_profile_path'] ?? null),
                'cookie_file_path' => $this->nullableString($profile['cookie_file_path'] ?? null),
                'persistent_profile_enabled' => (bool) ($profile['persistent_profile_enabled'] ?? true),
                'headless_enabled' => (bool) ($profile['headless_enabled'] ?? true),
                'auto_login_enabled' => (bool) ($profile['auto_login_enabled'] ?? false),
                'login_username' => $this->nullableString($profile['login_username'] ?? null),
                'login_password_encrypted' => $this->nullableString($profile['login_password_encrypted'] ?? null),
                'login_password_base_encrypted' => $this->nullableString($profile['login_password_base_encrypted'] ?? null),
                'navigation_timeout_seconds' => max(30, (int) ($profile['navigation_timeout_seconds'] ?? 120)),
                'post_login_wait_ms' => max(500, (int) ($profile['post_login_wait_ms'] ?? 2500)),
                'typing_delay_ms' => max(0, (int) ($profile['typing_delay_ms'] ?? 35)),
                'relationship_list_process_timeout_seconds' => max(60, (int) ($profile['relationship_list_process_timeout_seconds'] ?? 14400)),
                'relationship_list_max_scroll_rounds' => max(20, (int) ($profile['relationship_list_max_scroll_rounds'] ?? 100000)),
                'follower_list_max_items' => max(0, (int) ($profile['follower_list_max_items'] ?? 0)),
                'following_list_max_items' => max(0, (int) ($profile['following_list_max_items'] ?? 0)),
                'is_primary' => $profileKey === $activeProfileId,
                'is_active' => in_array($profileKey, $activeProfileIds, true),
                'sort_order' => $index,
                'metadata' => [
                    ...$metadata,
                    'factory_persona' => $factoryPersona,
                    'social_accounts' => $socialAccounts,
                    'legacy_imported_at' => $record->exists ? data_get($metadata, 'legacy_imported_at') : now()->toIso8601String(),
                ],
            ]))->save();

            $this->syncCookiePayloadFromProfileFile($record, $profile, $storageRoot);
        }

        Person::query()
            ->where('platform', 'instagram')
            ->whereNotIn('profile_key', $profileKeys)
            ->delete();
    }

    public function hydrateCookieFilesFromCollection(array $collection, ?string $storageRoot = null): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        foreach ($collection['profiles'] ?? [] as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $record = Person::query()
                ->where('platform', 'instagram')
                ->where('profile_key', (string) ($profile['id'] ?? ''))
                ->first();

            if (! $record || ! is_string($record->cookie_payload) || trim($record->cookie_payload) === '') {
                continue;
            }

            $cookieFilePath = $this->resolveCookieFilePath($profile, $storageRoot);

            if (! $cookieFilePath) {
                continue;
            }

            File::ensureDirectoryExists(dirname($cookieFilePath));
            File::put($cookieFilePath, $record->cookie_payload);
        }
    }

    public function hydrateCookieFilesFromRuntimeConfig(array $runtimeConfig): void
    {
        $this->hydrateCookieFilesFromRuntimeProfiles($this->runtimeProfiles($runtimeConfig));
    }

    public function syncCookiePayloadsFromRuntimeConfigFile(?string $runtimeConfigPath): void
    {
        if (! $runtimeConfigPath || ! File::exists($runtimeConfigPath)) {
            return;
        }

        try {
            $runtimeConfig = json_decode(File::get($runtimeConfigPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }

        if (is_array($runtimeConfig)) {
            $this->syncCookiePayloadsFromRuntimeConfig($runtimeConfig);
        }
    }

    public function syncCookiePayloadsFromRuntimeConfig(array $runtimeConfig): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        foreach ($this->runtimeProfiles($runtimeConfig) as $runtimeProfile) {
            $profileKey = trim((string) ($runtimeProfile['profileId'] ?? ''));
            $cookieFilePath = trim((string) ($runtimeProfile['cookieFilePath'] ?? ''));

            if ($profileKey === '' || $cookieFilePath === '') {
                continue;
            }

            $record = Person::query()
                ->where('platform', 'instagram')
                ->where('profile_key', $profileKey)
                ->first();

            if ($record) {
                $this->syncCookiePayloadFromFilePath($record, $cookieFilePath);
            }
        }
    }

    public function blockProfileForInstagramLimit(
        string $profileKey,
        ?string $reason = null,
        int $seconds = 3600,
        array $context = [],
    ): void {
        $profileKey = trim($profileKey);

        if (! $this->hasScrapeBlockColumns() || $profileKey === '') {
            return;
        }

        $record = Person::query()
            ->where('platform', 'instagram')
            ->where('profile_key', $profileKey)
            ->first();

        if (! $record) {
            return;
        }

        $blockedAt = now('UTC');
        $blockedUntil = $blockedAt->copy()->addSeconds(max(60, $seconds));
        $metadata = is_array($record->metadata) ? $record->metadata : [];

        $record->forceFill([
            'scrape_blocked_at' => $blockedAt,
            'scrape_blocked_until' => $blockedUntil,
            'scrape_blocked_reason' => $this->nullableString($reason) ?: 'instagram-rate-limit',
            'metadata' => [
                ...$metadata,
                'last_scrape_block' => [
                    'blocked_at' => $blockedAt->toIso8601String(),
                    'blocked_until' => $blockedUntil->toIso8601String(),
                    'reason' => $this->nullableString($reason) ?: 'instagram-rate-limit',
                    'context' => $context,
                ],
            ],
        ])->save();
    }

    public function clearScrapeBlock(string $profileKey): void
    {
        $profileKey = trim($profileKey);

        if (! $this->hasScrapeBlockColumns() || $profileKey === '') {
            return;
        }

        Person::query()
            ->where('platform', 'instagram')
            ->where('profile_key', $profileKey)
            ->update([
                'scrape_blocked_at' => null,
                'scrape_blocked_until' => null,
                'scrape_blocked_reason' => null,
            ]);
    }

    private function hydrateCookieFilesFromRuntimeProfiles(array $runtimeProfiles): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        foreach ($runtimeProfiles as $runtimeProfile) {
            $profileKey = trim((string) ($runtimeProfile['profileId'] ?? ''));
            $cookieFilePath = trim((string) ($runtimeProfile['cookieFilePath'] ?? ''));

            if ($profileKey === '' || $cookieFilePath === '') {
                continue;
            }

            $record = Person::query()
                ->where('platform', 'instagram')
                ->where('profile_key', $profileKey)
                ->first();

            if (! $record || ! is_string($record->cookie_payload) || trim($record->cookie_payload) === '') {
                continue;
            }

            File::ensureDirectoryExists(dirname($cookieFilePath));
            File::put($cookieFilePath, $record->cookie_payload);
        }
    }

    private function syncCookiePayloadFromProfileFile(Person $record, array $profile, ?string $storageRoot = null): void
    {
        $cookieFilePath = $this->resolveCookieFilePath($profile, $storageRoot);

        if ($cookieFilePath) {
            $this->syncCookiePayloadFromFilePath($record, $cookieFilePath);
        }
    }

    private function syncCookiePayloadFromFilePath(Person $record, string $cookieFilePath): void
    {
        if (! File::exists($cookieFilePath)) {
            return;
        }

        $payload = trim(File::get($cookieFilePath));

        if ($payload === '') {
            return;
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }

        $cookies = $this->extractCookieArray($decoded);
        $normalizedPayload = json_encode($cookies, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($normalizedPayload) || $normalizedPayload === '') {
            return;
        }

        $record->forceFill([
            'cookie_payload' => $normalizedPayload,
            'cookie_payload_hash' => hash('sha256', $normalizedPayload),
            'cookie_count' => count($cookies),
            'session_cookie_present' => collect($cookies)->contains(fn ($cookie): bool => is_array($cookie) && ($cookie['name'] ?? null) === 'sessionid'),
            'cookies_synced_at' => now('UTC'),
        ])->save();
    }

    private function profileArray(Person $profile): array
    {
        $blockedUntil = $this->hasScrapeBlockColumns() ? $profile->scrape_blocked_until : null;
        $isBlocked = $blockedUntil !== null && $blockedUntil->isFuture();
        $factoryPersona = is_array(data_get($profile->metadata, 'factory_persona'))
            ? data_get($profile->metadata, 'factory_persona')
            : [];
        $socialAccounts = $this->profileSocialAccounts($profile);
        $dateOfBirth = $this->profileValue($profile, $factoryPersona, 'person_date_of_birth');

        if ($dateOfBirth instanceof \DateTimeInterface) {
            $dateOfBirth = $dateOfBirth->format('Y-m-d');
        }

        return [
            'id' => $profile->profile_key,
            'profile_label' => $profile->profile_label,
            'person_first_name' => (string) $this->profileValue($profile, $factoryPersona, 'person_first_name'),
            'person_last_name' => (string) $this->profileValue($profile, $factoryPersona, 'person_last_name'),
            'person_alias' => (string) $this->profileValue($profile, $factoryPersona, 'person_alias'),
            'person_date_of_birth' => (string) $dateOfBirth,
            'person_gender' => (string) $this->profileValue($profile, $factoryPersona, 'person_gender'),
            'person_email' => (string) $this->profileValue($profile, $factoryPersona, 'person_email'),
            'person_phone' => (string) $this->profileValue($profile, $factoryPersona, 'person_phone'),
            'person_address_line1' => (string) $this->profileValue($profile, $factoryPersona, 'person_address_line1'),
            'person_address_line2' => (string) $this->profileValue($profile, $factoryPersona, 'person_address_line2'),
            'person_postal_code' => (string) $this->profileValue($profile, $factoryPersona, 'person_postal_code'),
            'person_state' => (string) $this->profileValue($profile, $factoryPersona, 'person_state'),
            'person_country' => (string) $this->profileValue($profile, $factoryPersona, 'person_country'),
            'person_city' => (string) $this->profileValue($profile, $factoryPersona, 'person_city'),
            'person_timezone' => (string) $this->profileValue($profile, $factoryPersona, 'person_timezone'),
            'person_notes' => (string) $this->profileValue($profile, $factoryPersona, 'person_notes'),
            'avatar_path' => (string) $this->profileValue($profile, $factoryPersona, 'avatar_path'),
            'identity_profile' => $this->profileArrayValue($profile, $factoryPersona, 'identity_profile'),
            'bot_profile' => $this->profileArrayValue($profile, $factoryPersona, 'bot_profile'),
            'bot_status' => (string) ($this->profileValue($profile, $factoryPersona, 'bot_status') ?: 'manual'),
            'social_accounts' => $socialAccounts,
            'persistent_profile_enabled' => (bool) $profile->persistent_profile_enabled,
            'browser_profile_path' => $profile->browser_profile_path ?: 'browser-profiles/instagram/default',
            'cookie_file_path' => $profile->cookie_file_path ?: 'cookies/instagram-cookies.json',
            'headless_enabled' => (bool) $profile->headless_enabled,
            'auto_login_enabled' => (bool) $profile->auto_login_enabled,
            'login_username' => (string) $profile->login_username,
            'login_password_encrypted' => $profile->login_password_encrypted,
            'login_password_base_encrypted' => $profile->login_password_base_encrypted,
            'navigation_timeout_seconds' => (int) $profile->navigation_timeout_seconds,
            'post_login_wait_ms' => (int) $profile->post_login_wait_ms,
            'typing_delay_ms' => (int) $profile->typing_delay_ms,
            'relationship_list_process_timeout_seconds' => (int) $profile->relationship_list_process_timeout_seconds,
            'relationship_list_max_scroll_rounds' => (int) $profile->relationship_list_max_scroll_rounds,
            'follower_list_max_items' => (int) $profile->follower_list_max_items,
            'following_list_max_items' => (int) $profile->following_list_max_items,
            'cookie_payload' => (string) ($profile->cookie_payload ?? ''),
            'cookie_payload_hash' => (string) ($profile->cookie_payload_hash ?? ''),
            'cookie_count' => (int) $profile->cookie_count,
            'session_cookie_present' => (bool) $profile->session_cookie_present,
            'cookies_synced_at' => optional($profile->cookies_synced_at)->toIso8601String(),
            'scrape_blocked_at' => $this->hasScrapeBlockColumns()
                ? optional($profile->scrape_blocked_at)->toIso8601String()
                : null,
            'scrape_blocked_until' => $blockedUntil?->toIso8601String(),
            'scrape_blocked_reason' => $this->hasScrapeBlockColumns()
                ? $this->nullableString($profile->scrape_blocked_reason)
                : null,
            'is_scrape_blocked' => $isBlocked,
            'scrape_block_remaining_seconds' => $isBlocked
                ? max(0, now('UTC')->diffInSeconds($blockedUntil, false))
                : 0,
            'base_sync_status' => (string) ($profile->base_sync_status ?: 'pending'),
            'base_synced_at' => optional($profile->base_synced_at)->toIso8601String(),
            'base_sync_error' => (string) ($profile->base_sync_error ?? ''),
            'updated_at' => optional($profile->updated_at)->toIso8601String() ?: now()->toIso8601String(),
        ];
    }

    private function resolveCookieFilePath(array $profile, ?string $storageRoot = null): ?string
    {
        $cookieFilePath = trim((string) ($profile['cookie_file_path'] ?? ''));

        if ($cookieFilePath === '') {
            return null;
        }

        if ($this->isAbsolutePath($cookieFilePath)) {
            return $cookieFilePath;
        }

        $storageRoot = $storageRoot ?: storage_path('app');

        return rtrim($storageRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $cookieFilePath), DIRECTORY_SEPARATOR);
    }

    private function extractCookieArray(mixed $decoded): array
    {
        $cookies = is_array($decoded) && array_is_list($decoded)
            ? $decoded
            : (is_array($decoded) && is_array($decoded['cookies'] ?? null) ? $decoded['cookies'] : []);

        return array_values(array_filter($cookies, static fn ($cookie): bool => is_array($cookie) && filled($cookie['name'] ?? null)));
    }

    private function runtimeProfiles(array $runtimeConfig): array
    {
        $profiles = [$runtimeConfig];

        if (is_array($runtimeConfig['accountPool'] ?? null)) {
            foreach ($runtimeConfig['accountPool'] as $account) {
                if (is_array($account)) {
                    $profiles[] = $account;
                }
            }
        }

        $seen = [];

        return array_values(array_filter($profiles, function (array $profile) use (&$seen): bool {
            $key = trim((string) ($profile['profileId'] ?? ''));

            if ($key === '' || isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;

            return true;
        }));
    }

    private function stringValue(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : $fallback;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function nullableDate(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function nullableArray(mixed $value): ?array
    {
        return is_array($value) && $value !== [] ? $value : null;
    }

    private function factoryPersonaPayload(array $profile): array
    {
        return [
            'person_first_name' => $this->nullableString($profile['person_first_name'] ?? null),
            'person_last_name' => $this->nullableString($profile['person_last_name'] ?? null),
            'person_alias' => $this->nullableString($profile['person_alias'] ?? null),
            'person_date_of_birth' => $this->nullableDate($profile['person_date_of_birth'] ?? null),
            'person_gender' => $this->nullableString($profile['person_gender'] ?? null),
            'person_email' => $this->nullableString($profile['person_email'] ?? null),
            'person_phone' => $this->nullableString($profile['person_phone'] ?? null),
            'person_address_line1' => $this->nullableString($profile['person_address_line1'] ?? null),
            'person_address_line2' => $this->nullableString($profile['person_address_line2'] ?? null),
            'person_postal_code' => $this->nullableString($profile['person_postal_code'] ?? null),
            'person_state' => $this->nullableString($profile['person_state'] ?? null),
            'person_country' => $this->nullableString($profile['person_country'] ?? null),
            'person_city' => $this->nullableString($profile['person_city'] ?? null),
            'person_timezone' => $this->nullableString($profile['person_timezone'] ?? null),
            'person_notes' => $this->nullableString($profile['person_notes'] ?? null),
            'avatar_path' => $this->nullableString($profile['avatar_path'] ?? null),
            'identity_profile' => $this->nullableArray($profile['identity_profile'] ?? null),
            'bot_profile' => $this->nullableArray($profile['bot_profile'] ?? null),
            'bot_status' => $this->stringValue($profile['bot_status'] ?? 'manual', 'manual'),
        ];
    }

    private function socialAccountsPayload(array $profile): array
    {
        $accounts = is_array($profile['social_accounts'] ?? null) ? $profile['social_accounts'] : [];
        $username = $this->nullableString($profile['login_username'] ?? null);

        if ($username === null) {
            unset($accounts['instagram']);

            return $accounts;
        }

        $username = ltrim($username, '@');
        $accounts['instagram'] = [
            'platform' => 'instagram',
            'username' => $username,
            'handle' => '@'.$username,
            'managed' => true,
            'login_enabled' => (bool) ($profile['auto_login_enabled'] ?? false),
        ];

        return $accounts;
    }

    private function profileSocialAccounts(Person $profile): array
    {
        $value = $this->hasColumn('social_accounts') ? $profile->getAttribute('social_accounts') : null;

        if (is_array($value)) {
            return $value;
        }

        $metadataValue = data_get($profile->metadata, 'social_accounts');

        return is_array($metadataValue) ? $metadataValue : [];
    }

    private function existingColumnValues(array $values): array
    {
        return array_filter(
            $values,
            fn (mixed $value, string $column): bool => $this->hasColumn($column),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function profileValue(Person $profile, array $factoryPersona, string $key): mixed
    {
        $value = $this->hasColumn($key) ? $profile->getAttribute($key) : null;

        if ($value !== null && $value !== '') {
            return $value;
        }

        return $factoryPersona[$key] ?? '';
    }

    private function profileArrayValue(Person $profile, array $factoryPersona, string $key): array
    {
        $value = $this->hasColumn($key) ? $profile->getAttribute($key) : null;

        if (is_array($value)) {
            return $value;
        }

        return is_array($factoryPersona[$key] ?? null) ? $factoryPersona[$key] : [];
    }

    private function hasColumn(string $column): bool
    {
        return in_array($column, $this->scraperProfileColumns(), true);
    }

    private function scraperProfileColumns(): array
    {
        if ($this->scraperProfileColumns !== null) {
            return $this->scraperProfileColumns;
        }

        if (! Schema::hasTable('persons')) {
            return $this->scraperProfileColumns = [];
        }

        return $this->scraperProfileColumns = Schema::getColumnListing('persons');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:\\\\/', $path) === 1
            || preg_match('/^[A-Za-z]:\//', $path) === 1;
    }

    private function hasScrapeBlockColumns(): bool
    {
        return $this->isAvailable()
            && $this->hasColumn('scrape_blocked_at')
            && $this->hasColumn('scrape_blocked_until')
            && $this->hasColumn('scrape_blocked_reason');
    }
}
