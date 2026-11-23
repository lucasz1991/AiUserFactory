<?php

namespace App\Services\Workflows;

use App\Models\WorkflowAssistanceRequest;
use App\Services\Workflows\Concerns\SpawnsPrivateNodeDaemon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Private Live-Ansicht eines pausierten Workflow-Browsers fuer Admin-Aufgaben.
 *
 * Ein lokal lauschender Node-Daemon (node/workflows/assistance/live_view.cjs)
 * haengt sich an den geparkten Browser, streamt das Fenster und nimmt Maus- und
 * Tastatureingaben direkt entgegen. Port und Secret liegen verschluesselt in den
 * Metadaten der Aufgabe und erreichen nie Livewire oder den Browser des Admins.
 */
class WorkflowAssistanceLiveBrowser
{
    use SpawnsPrivateNodeDaemon;

    public const INPUT_TYPES = ['down', 'move', 'up', 'click', 'wheel', 'key', 'text'];

    public const KEYS = ['Enter', 'Tab', 'Backspace', 'Escape'];

    /**
     * @return array{bytes: string, sequence: int}
     */
    public function frame(WorkflowAssistanceRequest $request, int $afterSequence = 0, int $waitMs = 0): array
    {
        $response = $this->call($request, function (array $runtime) use ($afterSequence, $waitMs) {
            return $this->client($runtime, 3 + (int) ceil($waitMs / 1000))
                ->get($this->endpoint($runtime).'/frame', [
                    'after' => max(0, $afterSequence),
                    'wait' => max(0, min(2000, $waitMs)),
                ]);
        });

        if (! $response->successful() || ! str_starts_with($response->body(), "\xFF\xD8")) {
            throw new RuntimeException('Das Live-Browserbild ist derzeit nicht verfügbar.');
        }

        return [
            'bytes' => $response->body(),
            'sequence' => max(0, (int) $response->header('X-Frame-Sequence')),
        ];
    }

    public function input(WorkflowAssistanceRequest $request, array $command): array
    {
        $response = $this->call($request, fn (array $runtime) => $this->client($runtime, 10)
            ->acceptJson()
            ->post($this->endpoint($runtime).'/input', $command));
        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload)) {
            throw new RuntimeException('Die Eingabe konnte nicht an den Browser übertragen werden.');
        }

        return ['ok' => true, 'sequence' => (int) ($payload['sequence'] ?? 0)];
    }

    /**
     * Trennt die Live-Ansicht. Der Workflow-Browser selbst bleibt offen.
     */
    public function stop(WorkflowAssistanceRequest $request): void
    {
        $runtime = $this->runtime($request);
        if ($runtime !== null) {
            try {
                $this->client($runtime, 3)->post($this->endpoint($runtime).'/stop');
            } catch (\Throwable) {
                // Der Daemon beendet sich ohnehin nach seiner Leerlaufzeit.
            }
        }
        $this->forgetRuntime($request);
    }

    /**
     * Fuehrt eine Anfrage aus; ist der Daemon nicht (mehr) erreichbar, wird er
     * einmalig neu gestartet.
     */
    protected function call(WorkflowAssistanceRequest $request, callable $operation): \Illuminate\Http\Client\Response
    {
        $runtime = $this->runtime($request) ?? $this->start($request);

        try {
            $response = $operation($runtime);
            if ($response->status() !== 401) {
                return $response;
            }
        } catch (ConnectionException) {
            // Daemon beendet (Leerlauf/Neustart) - unten neu starten.
        }

        $this->forgetRuntime($request);

        return $operation($this->start($request));
    }

    protected function start(WorkflowAssistanceRequest $request): array
    {
        return Cache::lock('workflow-assistance-live:'.$request->getKey(), 30)->block(25, function () use ($request): array {
            $fresh = $request->fresh();
            if ($existing = $this->runtime($fresh)) {
                return $existing;
            }

            $run = $fresh->workflowRun()->first();
            $context = is_array($run?->context_json) ? $run->context_json : [];
            $wsEndpoint = trim((string) (data_get($context, 'browser_runtime.wsEndpoint') ?: data_get($context, 'browser_ws_endpoint', '')));
            if ($wsEndpoint === '' || ! preg_match('#^wss?://(127\.0\.0\.1|localhost|\[::1\])(:\d+)?/#i', $wsEndpoint)) {
                throw new RuntimeException('Für diesen Lauf ist kein geparkter Workflow-Browser verfügbar.');
            }
            $window = trim((string) $fresh->browser_window) ?: 'main';
            $targetId = trim((string) data_get($context, 'browser_windows.'.$window.'.targetId', ''));

            $directory = storage_path('app/private/workflow-assistance-live/'.$fresh->request_uuid.'/'.Str::uuid());
            File::ensureDirectoryExists($directory, 0700);
            $secret = bin2hex(random_bytes(32));
            $configPath = $directory.DIRECTORY_SEPARATOR.'config.json';
            File::put($configPath, json_encode([
                'secret' => $secret,
                'wsEndpoint' => $wsEndpoint,
                'targetId' => $targetId,
                'idleTimeoutMs' => 90_000,
                'maxLifetimeMs' => max(60, min(3600, (int) now()->diffInSeconds($fresh->expires_at ?? now()->addMinutes(30), false))) * 1000,
                'quality' => 70,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            @chmod($configPath, 0600);

            $this->spawn($this->nodeBinary(), base_path('node/workflows/assistance/live_view.cjs'), $configPath, $directory);

            $deadline = microtime(true) + 20;
            do {
                $descriptorPath = $directory.DIRECTORY_SEPARATOR.'descriptor.json';
                if (File::isFile($descriptorPath)) {
                    $descriptor = json_decode(File::get($descriptorPath), true);
                    $runtime = ['port' => (int) ($descriptor['port'] ?? 0), 'secret' => $secret];
                    $this->endpoint($runtime);
                    $this->storeRuntime($fresh, $runtime);

                    return $runtime;
                }
                if (File::isFile($directory.DIRECTORY_SEPARATOR.'startup-error.json')) {
                    $error = json_decode(File::get($directory.DIRECTORY_SEPARATOR.'startup-error.json'), true);
                    throw new RuntimeException((string) (data_get($error, 'error.message') ?: 'Die Live-Ansicht konnte nicht gestartet werden.'));
                }
                usleep(100_000);
            } while (microtime(true) < $deadline);

            throw new RuntimeException('Die Live-Ansicht antwortet nicht.');
        });
    }

    protected function runtime(WorkflowAssistanceRequest $request): ?array
    {
        $stored = data_get($request->metadata_json, 'live_view');
        if (! is_array($stored) || ! isset($stored['port'], $stored['secret'])) {
            return null;
        }

        try {
            $runtime = ['port' => (int) $stored['port'], 'secret' => Crypt::decryptString((string) $stored['secret'])];
            $this->endpoint($runtime);

            return $runtime;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function storeRuntime(WorkflowAssistanceRequest $request, array $runtime): void
    {
        DB::transaction(function () use ($request, $runtime): void {
            $locked = WorkflowAssistanceRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $metadata = is_array($locked->metadata_json) ? $locked->metadata_json : [];
            $metadata['live_view'] = [
                'port' => $runtime['port'],
                'secret' => Crypt::encryptString($runtime['secret']),
                'started_at' => now()->toIso8601String(),
            ];
            $locked->forceFill(['metadata_json' => $metadata])->save();
        });
        $request->setRawAttributes($request->fresh()->getAttributes(), true);
    }

    protected function forgetRuntime(WorkflowAssistanceRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            $locked = WorkflowAssistanceRequest::query()->lockForUpdate()->find($request->getKey());
            if (! $locked) {
                return;
            }
            $metadata = is_array($locked->metadata_json) ? $locked->metadata_json : [];
            if (! array_key_exists('live_view', $metadata)) {
                return;
            }
            unset($metadata['live_view']);
            $locked->forceFill(['metadata_json' => $metadata])->save();
        });
        $request->setRawAttributes($request->fresh()?->getAttributes() ?? $request->getAttributes(), true);
    }

    protected function client(array $runtime, int $timeoutSeconds): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($runtime['secret'])
            ->withOptions(['allow_redirects' => false, 'proxy' => ''])
            ->connectTimeout(2)
            ->timeout(max(2, $timeoutSeconds));
    }

    protected function endpoint(array $runtime): string
    {
        $port = $runtime['port'] ?? null;
        if (! is_int($port) || $port < 1024 || $port > 65535
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($runtime['secret'] ?? ''))) {
            throw new RuntimeException('Die private Live-Verbindung ist nicht verfügbar.');
        }

        return 'http://127.0.0.1:'.$port;
    }
}
