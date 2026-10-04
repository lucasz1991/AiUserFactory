<?php

namespace App\Services\Workflows;

use App\Models\WorkflowRecording;
use App\Services\Mail\MailAccountRegistrationRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/** Private server-side bridge. Browser bearer credentials never reach Livewire. */
class WorkflowRecordingBrowser
{
    public function __construct(private MailAccountRegistrationRunner $settings) {}

    public function start(WorkflowRecording $recording): array
    {
        if (! config('workflow_recording.enabled', true)) {
            throw new RuntimeException('Live Aufnahme ist auf diesem Server deaktiviert.');
        }

        $directory = $this->directory($recording).DIRECTORY_SEPARATOR.Str::uuid();
        File::ensureDirectoryExists($directory, 0700);
        $secret = bin2hex(random_bytes(32));
        $runtime = [
            'runtimeDir' => $directory,
            'secret' => $secret,
            'testingLocalHosts' => app()->environment('testing') && config('workflow_recording.testing_local_hosts') === true,
            'viewport' => config('workflow_recording.viewport'),
            'idleTimeoutMs' => max(60, min(1800, (int) config('workflow_recording.idle_timeout_seconds', 600))) * 1000,
            'maxEvents' => max(10, min(500, (int) config('workflow_recording.max_events', 500))),
            'chromiumNoSandbox' => (bool) ($this->settings->settings()['chromium_no_sandbox'] ?? false),
        ];
        if ($binary = trim((string) config('workflow_recording.browser_executable_path'))) {
            $runtime['browserExecutablePath'] = $binary;
        }

        $configPath = $directory.DIRECTORY_SEPARATOR.'config.json';
        File::put($configPath, json_encode($runtime, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        @chmod($configPath, 0600);
        try {
            $this->spawn($this->nodeBinary(), base_path('node/recorder/serve.cjs'), $configPath, $directory);
        } catch (\Throwable $exception) {
            File::delete($configPath);
            throw $exception;
        }
        $deadline = microtime(true) + max(5, min(45, (int) config('workflow_recording.startup_timeout_seconds', 30)));

        do {
            $descriptorPath = $directory.DIRECTORY_SEPARATOR.'descriptor.json';
            if (File::isFile($descriptorPath)) {
                $descriptor = json_decode(File::get($descriptorPath), true);
                $metadata = ['port' => $descriptor['port'] ?? null, 'secret' => $secret];
                $this->endpoint($metadata);

                return $metadata;
            }
            if (File::isFile($directory.DIRECTORY_SEPARATOR.'startup-error.json')) {
                throw new RuntimeException('Der Aufnahme-Browser konnte nicht gestartet werden. Bitte Node-/Chrome-Konfiguration prüfen.');
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        // Daemon has a startup timeout and its own idle deadline, also after HTTP interruption.
        throw new RuntimeException('Der Aufnahme-Browser antwortet nicht. Bitte Node-/Chrome-Konfiguration prüfen.');
    }

    public function command(WorkflowRecording $recording, array $command): array
    {
        return $this->request($recording, 'command', $command);
    }

    public function state(WorkflowRecording $recording): array
    {
        return $this->request($recording, 'state');
    }

    public function frame(WorkflowRecording $recording): string
    {
        $runtime = (array) $recording->runtime_json;
        $response = Http::withToken($runtime['secret'] ?? '')
            ->withOptions(['allow_redirects' => false, 'proxy' => ''])
            ->connectTimeout(2)->timeout(8)->get($this->endpoint($runtime).'/frame');
        if (! $response->successful() || ! str_starts_with($response->body(), "\xFF\xD8")) {
            throw new RuntimeException('Die Browser-Vorschau ist derzeit nicht verfügbar.');
        }

        return $response->body();
    }

    public function close(WorkflowRecording $recording): void
    {
        if ((array) $recording->runtime_json === []) {
            return;
        }
        try {
            $this->request($recording, 'command', ['type' => 'stop']);
        } catch (\Throwable) {
            // No PID-based broad process kill. The owned daemon expires independently.
        }
    }

    protected function request(WorkflowRecording $recording, string $endpoint, ?array $command = null): array
    {
        $runtime = (array) $recording->runtime_json;
        $request = Http::withToken($runtime['secret'] ?? '')
            ->withOptions(['allow_redirects' => false, 'proxy' => ''])
            ->acceptJson()->connectTimeout(2)->timeout(35);
        $url = $this->endpoint($runtime).'/'.$endpoint;
        $response = $command === null ? $request->get($url) : $request->post($url, $command);
        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload)) {
            // Never forward browser text, page URLs, request bodies, logs or bearer data.
            throw new RuntimeException('Browseraktion nicht möglich. Ziel, Aufnahmezustand und Browser-Verbindung prüfen.');
        }

        return $payload;
    }

    protected function directory(WorkflowRecording $recording): string
    {
        if (! Str::isUuid((string) $recording->recording_uuid)) {
            throw new RuntimeException('Ungültige Aufnahme-ID.');
        }

        return storage_path('app/private/workflow-recordings/'.$recording->recording_uuid);
    }

    protected function endpoint(array $runtime): string
    {
        $port = $runtime['port'] ?? null;
        if (! is_int($port) || $port < 1024 || $port > 65535
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($runtime['secret'] ?? ''))) {
            throw new RuntimeException('Die private Browser-Verbindung ist nicht verfügbar.');
        }

        return 'http://127.0.0.1:'.$port;
    }

    protected function nodeBinary(): string
    {
        $binary = trim((string) config('services.workflow.node_binary'));
        if ($binary === '') {
            $result = PHP_OS_FAMILY === 'Windows'
                ? Process::timeout(5)->run(['where.exe', 'node'])
                : Process::timeout(5)->run(['sh', '-lc', 'command -v node']);
            $binary = trim(strtok($result->output(), "\r\n") ?: '');
        }
        if ($binary === '' || ! File::isFile($binary)) {
            throw new RuntimeException('WORKFLOW_NODE_BINARY muss auf eine vorhandene Node.js-Binary zeigen.');
        }
        $version = Process::timeout(5)->run([$binary, '--version']);
        if (! $version->successful() || ! preg_match('/^v(\d+\.\d+\.\d+)$/', trim($version->output()), $matches)
            || version_compare($matches[1], '22.12.0', '<')) {
            throw new RuntimeException('Live Aufnahme benötigt Node.js >= 22.12.0.');
        }

        return $binary;
    }

    protected function spawn(string $binary, string $scriptPath, string $configPath, string $directory): void
    {
        if (! File::isFile($scriptPath)) {
            throw new RuntimeException('Die Browser-Aufnahme-Runtime fehlt auf diesem Server.');
        }
        // Use the already validated Node binary on every OS. No shell quoting,
        // PowerShell dependency, visible helper window or inherited HTTP pipes.
        $launcher = "const child = require('node:child_process').spawn(process.execPath, process.argv.slice(1),"
            ." { cwd: process.cwd(), detached: true, windowsHide: true, stdio: 'ignore' });"
            ." child.once('error', () => { process.exitCode = 1; }); child.once('spawn', () => child.unref());";
        $result = Process::path(base_path())->env($this->processEnvironment())->timeout(10)
            ->run([$binary, '-e', $launcher, $scriptPath, $configPath]);
        if (! $result->successful()) {
            throw new RuntimeException('Die private Browser-Aufnahme konnte nicht gestartet werden.');
        }
    }

    protected function processEnvironment(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }
        // Symfony's HTTP-SAPI environment intersection can drop SystemRoot;
        // Windows CNG then fails even though `node --version` still succeeds.
        // These are inherited OS values, not application ENV configuration.
        $environment = [];
        foreach (['SystemRoot', 'WINDIR', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'TEMP', 'TMP', 'PATH', 'PROGRAMDATA', 'COMSPEC'] as $key) {
            $value = getenv($key, true);
            if (is_string($value) && $value !== '') {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }
}
