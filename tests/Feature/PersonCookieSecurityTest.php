<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Services\Scraper\ScraperProfileDatabaseStore;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PersonCookieSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cookie_payload_is_encrypted_at_rest_and_decrypted_by_the_model(): void
    {
        $payload = '[{"name":"sessionid","value":"secret-session"}]';
        $person = Person::query()->create([
            'platform' => 'instagram',
            'profile_key' => 'encrypted-cookie-profile',
            'profile_label' => 'Encrypted cookie profile',
            'cookie_payload' => $payload,
        ]);

        $stored = (string) DB::table('persons')->where('id', $person->id)->value('cookie_payload');

        $this->assertNotSame($payload, $stored);
        $this->assertSame($payload, Crypt::decryptString($stored));
        $this->assertSame($payload, $person->fresh()->cookie_payload);
    }

    public function test_hydration_writes_cookie_file_but_rejects_relative_path_traversal(): void
    {
        Storage::fake('local');
        $storageRoot = Storage::disk('local')->path('cookie-security');
        $payload = '[{"name":"sessionid","value":"secret-session"}]';

        Person::query()->create([
            'platform' => 'instagram',
            'profile_key' => 'safe-cookie-profile',
            'profile_label' => 'Safe cookie profile',
            'cookie_payload' => $payload,
        ]);
        Person::query()->create([
            'platform' => 'instagram',
            'profile_key' => 'traversal-cookie-profile',
            'profile_label' => 'Traversal cookie profile',
            'cookie_payload' => $payload,
        ]);
        Person::query()->create([
            'platform' => 'instagram',
            'profile_key' => 'absolute-escape-cookie-profile',
            'profile_label' => 'Absolute escape cookie profile',
            'cookie_payload' => $payload,
        ]);
        $absoluteEscapePath = dirname($storageRoot).DIRECTORY_SEPARATOR.'absolute-escape.json';

        app(ScraperProfileDatabaseStore::class)->hydrateCookieFilesFromCollection([
            'profiles' => [
                [
                    'id' => 'safe-cookie-profile',
                    'cookie_file_path' => 'cookies/safe.json',
                ],
                [
                    'id' => 'traversal-cookie-profile',
                    'cookie_file_path' => '../escape.json',
                ],
                [
                    'id' => 'absolute-escape-cookie-profile',
                    'cookie_file_path' => $absoluteEscapePath,
                ],
            ],
        ], $storageRoot);

        $safePath = $storageRoot.DIRECTORY_SEPARATOR.'cookies'.DIRECTORY_SEPARATOR.'safe.json';
        $escapedPath = dirname($storageRoot).DIRECTORY_SEPARATOR.'escape.json';

        $this->assertFileExists($safePath);
        $this->assertSame($payload, file_get_contents($safePath));
        $this->assertFileDoesNotExist($escapedPath);
        $this->assertFileDoesNotExist($absoluteEscapePath);

        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->assertSame(0600, fileperms($safePath) & 0777);
            $this->assertSame(0700, fileperms(dirname($safePath)) & 0777);
        }
    }

    public function test_retention_command_removes_stale_payload_and_private_cookie_file(): void
    {
        Cache::forget('operations.cookie_prune.latest');
        $root = storage_path('framework/testing/cookie-retention-'.str()->uuid());
        $path = $root.DIRECTORY_SEPARATOR.'cookies'.DIRECTORY_SEPARATOR.'stale.json';
        config(['security.cookie_files.allowed_roots' => [$root]]);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '[{"name":"sessionid","value":"stale"}]');
        touch($path, now()->subDays(40)->getTimestamp());

        try {
            $person = Person::query()->create([
                'platform' => 'instagram',
                'profile_key' => 'stale-cookie-profile',
                'profile_label' => 'Stale cookie profile',
                'cookie_file_path' => $path,
                'cookie_payload' => '[{"name":"sessionid","value":"stale"}]',
                'cookie_payload_hash' => hash('sha256', 'stale'),
                'cookie_count' => 1,
                'session_cookie_present' => true,
                'cookies_synced_at' => now()->subDays(40),
            ]);

            $this->artisan('security:prune-cookie-sessions', ['--days' => 30])
                ->assertExitCode(0);

            $this->assertFileDoesNotExist($path);
            $this->assertNull(DB::table('persons')->where('id', $person->id)->value('cookie_payload'));
            $this->assertSame(0, (int) $person->fresh()->cookie_count);
            $this->assertNotNull(Cache::get('operations.cookie_prune.latest'));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_cookie_rotation_uses_previous_key_and_reencrypts_with_current_key(): void
    {
        Cache::forget('operations.cookie_key_rotation.latest');
        $oldKey = random_bytes(32);
        $newKey = random_bytes(32);
        $oldEncrypter = new Encrypter($oldKey, 'AES-256-CBC');
        $newEncrypter = new Encrypter($newKey, 'AES-256-CBC');
        $payload = '[{"name":"sessionid","value":"rotation-secret"}]';
        $person = Person::query()->create([
            'platform' => 'instagram',
            'profile_key' => 'rotated-cookie-profile',
            'profile_label' => 'Rotated cookie profile',
        ]);
        DB::table('persons')->where('id', $person->id)->update([
            'cookie_payload' => $oldEncrypter->encryptString($payload),
        ]);

        config([
            'app.key' => 'base64:'.base64_encode($newKey),
            'app.previous_keys' => ['base64:'.base64_encode($oldKey)],
        ]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $this->artisan('security:rotate-cookie-encryption')->assertExitCode(0);

        $rotated = (string) DB::table('persons')->where('id', $person->id)->value('cookie_payload');

        $this->assertSame($payload, $newEncrypter->decryptString($rotated));
        $oldKeyStillDecrypts = true;

        try {
            $oldEncrypter->decryptString($rotated);
        } catch (\Throwable) {
            $oldKeyStillDecrypts = false;
        }

        $this->assertFalse($oldKeyStillDecrypts);
        $this->assertNotNull(Cache::get('operations.cookie_key_rotation.latest'));
    }
}
