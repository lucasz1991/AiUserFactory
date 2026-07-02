<?php

namespace App\Services\ClientController;

use App\Models\NetworkJob;
use App\Models\NetworkNode;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ClientControllerReleaseService
{
    public const DEFAULT_REPOSITORY = 'lucasz1991/ClientController';

    public function settings(): array
    {
        $settings = Setting::getValue('client_controller', 'updates');
        $settings = is_array($settings) ? $settings : [];
        $repository = trim((string) ($settings['github_repository'] ?? self::DEFAULT_REPOSITORY));

        return [
            'github_repository' => $repository ?: self::DEFAULT_REPOSITORY,
            'manifest_url' => trim((string) ($settings['manifest_url'] ?? $this->defaultManifestUrl($repository))),
            'updater_public_key' => trim((string) ($settings['updater_public_key'] ?? '')),
            'check_interval_minutes' => max(1, min(1440, (int) ($settings['check_interval_minutes'] ?? 15))),
        ];
    }

    public function latestRelease(bool $refresh = false): array
    {
        $settings = $this->settings();
        $repository = $this->normalizeRepository($settings['github_repository']);
        $cacheKey = 'client-controller-release.'.sha1($repository);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember(
            $cacheKey,
            now()->addMinutes($settings['check_interval_minutes']),
            function () use ($repository, $settings): array {
                $request = Http::acceptJson()
                    ->withHeaders([
                        'X-GitHub-Api-Version' => '2022-11-28',
                        'User-Agent' => 'FollowFlow-AiUserFactory',
                    ])
                    ->timeout(15);

                $token = trim((string) config('services.github.token', ''));
                if ($token !== '') {
                    $request = $request->withToken($token);
                }

                $response = $request->get("https://api.github.com/repos/{$repository}/releases/latest");

                if ($response->status() === 404) {
                    throw new RuntimeException('Im GitHub-Repository ist noch kein Release veröffentlicht.');
                }

                if (! $response->successful()) {
                    throw new RuntimeException('GitHub-Release konnte nicht geladen werden (HTTP '.$response->status().').');
                }

                $release = $response->json();
                $version = $this->normalizeVersion((string) data_get($release, 'tag_name'));

                if ($version === '') {
                    throw new RuntimeException('Das neueste GitHub-Release besitzt keine gültige Versionsnummer.');
                }

                $manifestAsset = collect(data_get($release, 'assets', []))
                    ->first(fn (array $asset): bool => strtolower((string) ($asset['name'] ?? '')) === 'latest.json');

                return [
                    'version' => $version,
                    'tag' => (string) data_get($release, 'tag_name'),
                    'name' => (string) (data_get($release, 'name') ?: data_get($release, 'tag_name')),
                    'url' => (string) data_get($release, 'html_url'),
                    'published_at' => data_get($release, 'published_at'),
                    'notes' => Str::limit(trim((string) data_get($release, 'body')), 1000),
                    'manifest_url' => (string) data_get($manifestAsset, 'browser_download_url', $settings['manifest_url']),
                    'has_manifest' => is_array($manifestAsset),
                    'repository' => $repository,
                ];
            }
        );
    }

    public function updateAvailable(?string $installedVersion, string $latestVersion): bool
    {
        $installed = $this->normalizeVersion((string) $installedVersion);
        $latest = $this->normalizeVersion($latestVersion);

        return $installed !== '' && $latest !== '' && version_compare($latest, $installed, '>');
    }

    public function queueUpdate(NetworkNode $node, ?string $requestedBy = null): NetworkJob
    {
        $release = $this->latestRelease();
        $settings = $this->settings();

        if (! $this->updateAvailable($node->version, $release['version'])) {
            throw new RuntimeException('Für diesen Node ist kein neueres Release verfügbar.');
        }

        if ($settings['updater_public_key'] === '') {
            throw new RuntimeException('In den ClientController-Update-Einstellungen fehlt der öffentliche Tauri-Schlüssel.');
        }

        $manifestUrl = trim((string) ($release['manifest_url'] ?: $settings['manifest_url']));
        if (! str_starts_with(strtolower($manifestUrl), 'https://')) {
            throw new RuntimeException('Die Updater-Manifest-URL muss HTTPS verwenden.');
        }

        $existing = $node->jobs()
            ->where('type', 'node_update')
            ->whereIn('status', ['pending', 'dispatched'])
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $job = app(NetworkJobDispatcher::class)->dispatch(
            node: $node,
            type: 'node_update',
            payload: [
                'command' => 'node_update',
                'target_version' => $release['version'],
                'manifest_url' => $manifestUrl,
                'updater_public_key' => $settings['updater_public_key'],
                'release_url' => $release['url'],
                'requested_at' => now()->toIso8601String(),
            ],
            requestedBy: $requestedBy,
            expiresAt: now()->addHours(6),
        );

        $node->update([
            'update_status' => 'pending',
            'update_target_version' => $release['version'],
            'update_requested_at' => now(),
            'update_error' => null,
        ]);

        return $job;
    }

    public function normalizeVersion(string $version): string
    {
        $version = trim($version);
        $version = preg_replace('/^[vV]/', '', $version) ?? '';

        return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) ? $version : '';
    }

    protected function normalizeRepository(string $repository): string
    {
        $repository = trim($repository, " \t\n\r\0\x0B/");

        if (! preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)) {
            throw new RuntimeException('Das GitHub-Repository muss im Format owner/repository angegeben werden.');
        }

        return $repository;
    }

    protected function defaultManifestUrl(string $repository): string
    {
        return 'https://github.com/'.trim($repository, '/').'/releases/latest/download/latest.json';
    }
}
