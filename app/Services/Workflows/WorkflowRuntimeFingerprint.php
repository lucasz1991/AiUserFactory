<?php

namespace App\Services\Workflows;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Berechnet einen stabilen Fingerabdruck der Workflow-Node-Runtime unter
 * `node/workflows`.
 *
 * Zweck: Teamprotokoll-Regel 7 verlangt, dass Aenderungen unter `node/workflows`
 * anschliessend in den ClientController synchronisiert werden. Bisher liess sich
 * nur manuell pruefen, ob beide Seiten denselben Stand haben. Mit diesem
 * Fingerabdruck wird der Abgleich maschinell moeglich: der Hash wandert in das
 * Client-Bundle (`ClientWorkflowBundleCompiler`) und laesst sich per
 * `php artisan workflow:runtime-hash` auf beiden Seiten vergleichen.
 *
 * Kanonisierung — wichtig, damit derselbe Code auf Windows und Linux denselben
 * Hash ergibt:
 *
 * 1. Nur `.cjs`-Dateien; `*.test.cjs` bleibt aussen vor, damit reine
 *    Testaenderungen keinen falschen „Sync noetig"-Alarm ausloesen.
 * 2. Pfade relativ zu `node/workflows`, immer mit `/` als Trenner, aufsteigend
 *    sortiert.
 * 3. Zeilenenden werden vor dem Hashen auf LF normalisiert und ein etwaiges
 *    UTF-8-BOM entfernt. Ohne diesen Schritt liefert dieselbe Datei je nach
 *    `core.autocrlf`-Einstellung unterschiedliche Hashes.
 *
 * Siehe docs/workflow-runtime-analyse-und-optimierung.md, Paket P6.
 */
class WorkflowRuntimeFingerprint
{
    public const RUNTIME_DIRECTORY = 'node/workflows';

    public const ALGORITHM = 'sha256';

    /** @var array<string, string>|null */
    protected ?array $fileHashes = null;

    /**
     * Kombinierter Hash ueber alle Runtime-Dateien.
     */
    public function hash(): string
    {
        $lines = [];

        foreach ($this->files() as $relativePath => $fileHash) {
            $lines[] = $relativePath.':'.$fileHash;
        }

        return hash(self::ALGORITHM, implode("\n", $lines));
    }

    /**
     * Einzelhashes je Datei, nach Pfad sortiert.
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        if ($this->fileHashes !== null) {
            return $this->fileHashes;
        }

        $directory = $this->runtimeDirectory();

        if (! File::isDirectory($directory)) {
            return $this->fileHashes = [];
        }

        $hashes = [];

        foreach (File::allFiles($directory) as $file) {
            if (! $this->isRuntimeFile($file)) {
                continue;
            }

            $relativePath = str_replace('\\', '/', $file->getRelativePathname());
            $hashes[$relativePath] = hash(self::ALGORITHM, $this->canonicalContents($file->getPathname()));
        }

        ksort($hashes, SORT_STRING);

        return $this->fileHashes = $hashes;
    }

    public function fileCount(): int
    {
        return count($this->files());
    }

    /**
     * Kompakte Zusammenfassung fuer Ausgaben und Bundles.
     *
     * @return array{hash: string, algorithm: string, fileCount: int, directory: string}
     */
    public function summary(): array
    {
        return [
            'hash' => $this->hash(),
            'algorithm' => self::ALGORITHM,
            'fileCount' => $this->fileCount(),
            'directory' => self::RUNTIME_DIRECTORY,
        ];
    }

    /**
     * Vergleicht den lokalen Stand mit einem entfernten Dateihash-Verzeichnis,
     * z. B. dem eines ClientController-Nodes.
     *
     * @param  array<string, string>  $remoteFiles
     * @return array{inSync: bool, missingRemote: list<string>, missingLocal: list<string>, changed: list<string>}
     */
    public function compare(array $remoteFiles): array
    {
        $local = $this->files();
        $remote = [];

        foreach ($remoteFiles as $path => $fileHash) {
            $remote[str_replace('\\', '/', (string) $path)] = (string) $fileHash;
        }

        $missingRemote = array_values(array_diff(array_keys($local), array_keys($remote)));
        $missingLocal = array_values(array_diff(array_keys($remote), array_keys($local)));
        $changed = [];

        foreach ($local as $path => $fileHash) {
            if (array_key_exists($path, $remote) && $remote[$path] !== $fileHash) {
                $changed[] = $path;
            }
        }

        sort($missingRemote, SORT_STRING);
        sort($missingLocal, SORT_STRING);
        sort($changed, SORT_STRING);

        return [
            'inSync' => $missingRemote === [] && $missingLocal === [] && $changed === [],
            'missingRemote' => $missingRemote,
            'missingLocal' => $missingLocal,
            'changed' => $changed,
        ];
    }

    /**
     * Verwirft den Zwischenspeicher. Nur noetig, wenn sich die Dateien im selben
     * Prozess aendern (z. B. in Tests).
     */
    public function flush(): void
    {
        $this->fileHashes = null;
    }

    protected function runtimeDirectory(): string
    {
        return base_path(self::RUNTIME_DIRECTORY);
    }

    protected function isRuntimeFile(SplFileInfo $file): bool
    {
        $name = $file->getFilename();

        return str_ends_with($name, '.cjs') && ! str_ends_with($name, '.test.cjs');
    }

    protected function canonicalContents(string $path): string
    {
        $contents = (string) file_get_contents($path);

        // UTF-8-BOM entfernen.
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        // CRLF und CR auf LF normalisieren, damit der Hash plattformunabhaengig
        // ist (Windows-Checkouts mit core.autocrlf=true sonst abweichend).
        return str_replace(["\r\n", "\r"], "\n", $contents);
    }
}
