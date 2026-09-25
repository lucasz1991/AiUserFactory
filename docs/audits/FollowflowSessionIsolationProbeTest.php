<?php

use App\Models\Person;
use App\Models\PersonEmailAccount;
use App\Services\Workflows\Tasks\PersistBrowserSessionTask;
use App\Services\Workflows\Tasks\PersistWebmailSessionTask;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/** Isolated regression probe: no Laravel bootstrap, dotenv, or real database. */
final class FollowflowSessionIsolationProbeTest extends TestCase
{
    private Capsule $database;
    private Encrypter $encrypter;

    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        Container::setInstance($container);
        $syntheticKey = str_repeat('s', 32);
        $container->instance('config', new Repository([
            'app' => [
                'env' => 'testing',
                'timezone' => 'UTC',
                'key' => 'base64:'.base64_encode($syntheticKey),
                'cipher' => 'AES-256-CBC',
            ],
        ]));
        $this->encrypter = new Encrypter($syntheticKey, 'AES-256-CBC');
        $container->instance('encrypter', $this->encrypter);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $this->database = new Capsule($container);
        $this->database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $container->instance('db', $this->database->getDatabaseManager());
        self::assertSame(':memory:', $this->database->getConnection()->getConfig('database'));
        $this->database->schema()->create('persons', function (Blueprint $table): void {
            $table->id();
            $table->string('person_email')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $this->database->schema()->create('person_email_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('person_id');
            $table->string('email');
            $table->boolean('is_primary')->default(false);
            $table->json('webmail_session')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_two_stale_person_instances_preserve_both_session_updates(): void
    {
        $person = Person::query()->create(['metadata' => ['browser_sessions' => []]]);
        $firstWriter = Person::query()->findOrFail($person->id);
        $secondWriter = Person::query()->findOrFail($person->id);
        self::assertNotSame($firstWriter, $secondWriter);
        $task = new PersistBrowserSessionTask;
        $firstResult = $task->handle($firstWriter, [
            'encryptedBrowserSessionPayload' => $this->encrypter->encryptString('{"sentinel":"synthetic-a"}'),
            'sessionKey' => 'session-a',
            'domain' => 'a.example.test',
        ]);
        $keysAfterFirst = array_keys($person->fresh()->metadata['browser_sessions']);
        $secondResult = $task->handle($secondWriter, [
            'encryptedBrowserSessionPayload' => $this->encrypter->encryptString('{"sentinel":"synthetic-b"}'),
            'sessionKey' => 'session-b',
            'domain' => 'b.example.test',
        ]);
        $keysAfterSecond = array_keys($person->fresh()->metadata['browser_sessions']);
        self::assertTrue($firstResult['ok']);
        self::assertTrue($secondResult['ok']);
        self::assertSame(['session-a'], $keysAfterFirst);
        self::assertSame(['session-a', 'session-b'], $keysAfterSecond);
        fwrite(STDOUT, json_encode([
            'probe' => 'lost-update',
            'database' => ':memory:',
            'first_write_ok' => $firstResult['ok'],
            'second_write_ok' => $secondResult['ok'],
            'keys_after_first' => $keysAfterFirst,
            'keys_after_second' => $keysAfterSecond,
            'lost_first_update' => ! in_array('session-a', $keysAfterSecond, true),
        ], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function test_webmail_persistence_updates_primary_email_account_and_metadata_mirror(): void
    {
        $oldSession = ['payload_encrypted' => $this->encrypter->encryptString('{"sentinel":"old"}')];
        $person = Person::query()->create([
            'person_email' => 'synthetic@example.test',
            'metadata' => ['email_account' => [
                'email' => 'synthetic@example.test',
                'webmail_session' => $oldSession,
            ]],
        ]);
        $primary = PersonEmailAccount::query()->create([
            'person_id' => $person->id,
            'email' => 'synthetic@example.test',
            'is_primary' => true,
            'webmail_session' => $oldSession,
        ]);
        $newPayload = $this->encrypter->encryptString('{"sentinel":"new"}');
        $result = (new PersistWebmailSessionTask)->handle($person, [
            'encryptedSessionPayload' => $newPayload,
            'sessionPayloadHash' => 'synthetic-new-hash',
            'domain' => 'mail.example.test',
        ]);
        $metadataPayload = data_get($person->fresh()->metadata, 'email_account.webmail_session.payload_encrypted');
        $canonicalPayload = data_get($primary->fresh()->webmail_session, 'payload_encrypted');
        self::assertTrue($result['ok']);
        self::assertSame($newPayload, $metadataPayload);
        self::assertSame($newPayload, $canonicalPayload);
        self::assertSame($metadataPayload, $canonicalPayload);
        fwrite(STDOUT, json_encode([
            'probe' => 'webmail-canonical-mirror',
            'database' => ':memory:',
            'write_ok' => $result['ok'],
            'metadata_updated' => $metadataPayload === $newPayload,
            'canonical_row_updated' => $canonicalPayload === $newPayload,
            'canonical_and_mirror_match' => $canonicalPayload === $metadataPayload,
        ], JSON_THROW_ON_ERROR).PHP_EOL);
    }
}
