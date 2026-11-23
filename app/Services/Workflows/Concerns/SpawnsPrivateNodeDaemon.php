<?php

namespace App\Services\Workflows\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Startet private, lokal lauschende Node-Daemons (Live-Aufnahme, Live-Ansicht
 * fuer Admin-Aufgaben) mit einer validierten Node-Binary, ohne Shell und ohne
 * geerbte HTTP-Pipes.
 */
trait SpawnsPrivateNodeDaemon
{
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
