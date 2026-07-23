<?php

namespace Tests\Unit;

use App\Services\Workflows\WorkflowRuntimeFingerprint;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Sichert den Runtime-Fingerabdruck ab, mit dem Teamprotokoll-Regel 7
 * (Sync von `node/workflows` in den ClientController) maschinell pruefbar wird.
 *
 * Siehe docs/workflow-runtime-analyse-und-optimierung.md, Paket P6.
 */
class WorkflowRuntimeFingerprintTest extends TestCase
{
    /** @var list<string> */
    protected array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        $this->temporaryDirectories = [];

        parent::tearDown();
    }

    public function test_it_hashes_the_real_runtime_deterministically(): void
    {
        $first = app(WorkflowRuntimeFingerprint::class)->hash();
        $second = app(WorkflowRuntimeFingerprint::class)->hash();

        $this->assertSame($first, $second, 'Zwei Instanzen muessen denselben Hash liefern.');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first, 'Erwartet wird ein SHA-256-Hex-Hash.');
    }

    public function test_it_covers_the_runtime_scripts_but_ignores_test_files(): void
    {
        $files = app(WorkflowRuntimeFingerprint::class)->files();

        $this->assertArrayHasKey('node/workflows/run_step.cjs', $files);
        $this->assertArrayHasKey('node/workflows/tasks/browser/click.cjs', $files);
        $this->assertArrayHasKey('node/workflows/lib/selector.cjs', $files);
        $this->assertArrayHasKey('resources/node/register/lib/browser-launcher.cjs', $files);

        foreach (array_keys($files) as $relativePath) {
            $this->assertStringEndsWith('.cjs', $relativePath);
            $this->assertStringEndsNotWith('.test.cjs', $relativePath);
        }

        // Bewusst keine feste Zahl: neue Task-Skripte sollen den Test nicht
        // rot faerben. Die Untergrenze schuetzt nur davor, dass der Suchpfad
        // unbemerkt ins Leere laeuft.
        $this->assertGreaterThan(40, count($files), 'Es wurden auffaellig wenige Runtime-Dateien gefunden.');
    }

    public function test_the_hash_is_independent_of_line_endings_and_bom(): void
    {
        $unix = $this->fingerprintFor([
            'a.cjs' => "'use strict';\nmodule.exports = { run() {} };\n",
            'nested/b.cjs' => "const x = 1;\n",
        ]);

        $windows = $this->fingerprintFor([
            'a.cjs' => "\xEF\xBB\xBF'use strict';\r\nmodule.exports = { run() {} };\r\n",
            'nested/b.cjs' => "const x = 1;\r\n",
        ]);

        $this->assertSame(
            $unix->hash(),
            $windows->hash(),
            'CRLF- und BOM-Unterschiede duerfen den Hash nicht veraendern, sonst ist der '
            .'Regel-7-Abgleich zwischen Windows- und Linux-Checkout wertlos.',
        );
    }

    public function test_changed_content_changes_the_hash(): void
    {
        $before = $this->fingerprintFor(['a.cjs' => "const x = 1;\n"]);
        $after = $this->fingerprintFor(['a.cjs' => "const x = 2;\n"]);

        $this->assertNotSame($before->hash(), $after->hash());
    }

    public function test_renaming_a_file_changes_the_hash(): void
    {
        $before = $this->fingerprintFor(['a.cjs' => "const x = 1;\n"]);
        $after = $this->fingerprintFor(['b.cjs' => "const x = 1;\n"]);

        $this->assertNotSame(
            $before->hash(),
            $after->hash(),
            'Der Pfad muss in den Hash eingehen, sonst bleibt eine Umbenennung unbemerkt.',
        );
    }

    public function test_test_files_do_not_influence_the_hash(): void
    {
        $without = $this->fingerprintFor(['a.cjs' => "const x = 1;\n"]);
        $with = $this->fingerprintFor([
            'a.cjs' => "const x = 1;\n",
            'a.test.cjs' => "require('node:test');\n",
        ]);

        $this->assertSame($without->hash(), $with->hash());
        $this->assertArrayNotHasKey('a.test.cjs', $with->files());
    }

    public function test_compare_reports_drift_against_a_remote_node(): void
    {
        $fingerprint = $this->fingerprintFor([
            'a.cjs' => "const x = 1;\n",
            'b.cjs' => "const y = 2;\n",
        ]);

        $this->assertTrue($fingerprint->compare($fingerprint->files())['inSync']);

        $remote = $fingerprint->files();
        $remote['b.cjs'] = str_repeat('0', 64);
        unset($remote['a.cjs']);
        $remote['veraltet.cjs'] = str_repeat('1', 64);

        $result = $fingerprint->compare($remote);

        $this->assertFalse($result['inSync']);
        $this->assertSame(['a.cjs'], $result['missingRemote']);
        $this->assertSame(['veraltet.cjs'], $result['missingLocal']);
        $this->assertSame(['b.cjs'], $result['changed']);
    }

    public function test_summary_exposes_the_fields_used_by_the_bundle(): void
    {
        $summary = app(WorkflowRuntimeFingerprint::class)->summary();

        $this->assertSame('sha256', $summary['algorithm']);
        $this->assertSame(WorkflowRuntimeFingerprint::RUNTIME_DIRECTORIES, $summary['directories']);
        $this->assertStringContainsString('resources/node/register/lib', $summary['directory']);
        $this->assertSame(app(WorkflowRuntimeFingerprint::class)->hash(), $summary['hash']);
        $this->assertGreaterThan(0, $summary['fileCount']);
    }

    public function test_a_missing_runtime_directory_yields_no_files(): void
    {
        $directory = $this->temporaryDirectory().DIRECTORY_SEPARATOR.'gibt-es-nicht';
        $fingerprint = $this->fingerprintForDirectory($directory);

        $this->assertSame([], $fingerprint->files());
        $this->assertSame(0, $fingerprint->fileCount());
    }

    /**
     * @param  array<string, string>  $files
     */
    protected function fingerprintFor(array $files): WorkflowRuntimeFingerprint
    {
        $directory = $this->temporaryDirectory();

        foreach ($files as $relativePath => $contents) {
            $path = $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $contents);
        }

        return $this->fingerprintForDirectory($directory);
    }

    protected function fingerprintForDirectory(string $directory): WorkflowRuntimeFingerprint
    {
        return new class($directory) extends WorkflowRuntimeFingerprint
        {
            public function __construct(protected string $directory) {}

            protected function runtimeDirectories(): array
            {
                return ['' => $this->directory];
            }
        };
    }

    protected function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'runtime-fingerprint-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($directory);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
