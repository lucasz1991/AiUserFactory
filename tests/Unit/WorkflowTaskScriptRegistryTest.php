<?php

namespace Tests\Unit;

use App\Services\Workflows\WorkflowTaskCatalog;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Sichert den Vertrag zwischen dem PHP-Task-Katalog und den Node-Task-Skripten.
 *
 * Der WorkflowTaskCatalog ist die einzige Registry fuer Runner und Node-Skript.
 * Diese Tests ziehen fehlende Dateien oder einen falschen Modulexport nach vorn
 * und sichern die beiden ausdruecklichen Kompatibilitaetsfaelle ab.
 *
 * Siehe docs/workflow-runtime-analyse-und-optimierung.md, Befund B4.
 */
class WorkflowTaskScriptRegistryTest extends TestCase
{
    public function test_every_catalog_entry_points_at_an_existing_node_script(): void
    {
        $missingScript = [];
        $missingFile = [];

        foreach (app(WorkflowTaskCatalog::class)->all() as $key => $definition) {
            $script = trim((string) ($definition['node_script'] ?? ''));

            if ($script === '') {
                $missingScript[] = $key;

                continue;
            }

            if (! File::exists(base_path($script))) {
                $missingFile[] = $key.' -> '.$script;
            }
        }

        $this->assertSame([], $missingScript, 'Katalogeintraege ohne node_script: '.implode(', ', $missingScript));
        $this->assertSame([], $missingFile, 'node_script-Dateien fehlen auf der Platte: '.implode(', ', $missingFile));
    }

    public function test_every_node_script_exports_a_run_function_with_the_catalog_key(): void
    {
        $scripts = collect(app(WorkflowTaskCatalog::class)->all())
            ->flatMap(function (array $definition, string $taskKey): array {
                $scripts = [(string) ($definition['node_script'] ?? '')];

                foreach ((array) data_get($definition, 'runtime.variants', []) as $variant) {
                    if (is_array($variant)) {
                        $scripts[] = (string) ($variant['node_script'] ?? '');
                    }
                }

                return collect($scripts)
                    ->map(fn (string $script): array => [
                        'taskKey' => $taskKey,
                        'script' => trim($script),
                    ])
                    ->filter(fn (array $entry): bool => $entry['script'] !== '')
                    ->values()
                    ->all();
            })
            ->unique(fn (array $entry): string => $entry['taskKey'].'|'.$entry['script'])
            ->values()
            ->all();

        $this->assertNotEmpty($scripts, 'Der Task-Katalog liefert keine node_script-Eintraege.');

        $node = $this->resolveNodeBinary();

        if ($node === null) {
            $this->markTestSkipped('Node.js ist in dieser Umgebung nicht verfuegbar.');
        }

        $mapPath = tempnam(sys_get_temp_dir(), 'catalog_scripts_').'.json';
        File::put($mapPath, json_encode($scripts));

        $checker = <<<'JS'
const fs = require('fs');
const path = require('path');
// argv[0] = node, argv[1] = dieses Skript, danach die uebergebenen Argumente.
const map = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const basePath = process.argv[3];
const problems = [];

for (const { taskKey: key, script: relativePath } of map) {
  const absolutePath = path.resolve(basePath, relativePath);
  let taskModule;

  try {
    taskModule = require(absolutePath);
  } catch (error) {
    problems.push(`${key}: require fehlgeschlagen (${error.message})`);
    continue;
  }

  if (!taskModule || typeof taskModule.run !== 'function') {
    problems.push(`${key}: exportiert keine run()-Funktion`);
    continue;
  }

  if (typeof taskModule.key === 'string' && taskModule.key !== key) {
    problems.push(`${key}: Skript meldet abweichenden key "${taskModule.key}"`);
  }
}

process.stdout.write(JSON.stringify(problems));
JS;

        $checkerPath = tempnam(sys_get_temp_dir(), 'catalog_checker_').'.cjs';
        File::put($checkerPath, $checker);

        try {
            $result = Process::timeout(120)->run([$node, $checkerPath, $mapPath, base_path()]);

            $this->assertTrue(
                $result->successful(),
                'Die Node-Pruefung konnte nicht ausgefuehrt werden: '.trim($result->errorOutput()),
            );

            $problems = json_decode(trim($result->output()), true);

            $this->assertIsArray($problems, 'Die Node-Pruefung lieferte keine auswertbare Antwort: '.$result->output());
            $this->assertSame([], $problems, "Task-Skripte verletzen den run()-Vertrag:\n".implode("\n", $problems));
        } finally {
            File::delete([$mapPath, $checkerPath]);
        }
    }

    public function test_runtime_resolver_uses_catalog_as_the_only_execution_registry(): void
    {
        $catalog = app(WorkflowTaskCatalog::class);

        foreach ($catalog->all() as $key => $definition) {
            $resolved = $catalog->resolveRuntimeTask([
                'task_key' => $key,
                'runner' => 'stale-runner',
                'node_script' => 'stale/script.cjs',
            ]);

            $this->assertSame($definition['runner'], $resolved['runner'], $key);
            $this->assertSame($definition['node_script'], $resolved['node_script'], $key);
        }

        $this->assertFalse(
            method_exists(app(WorkflowTaskRunner::class), 'normalizeRuntimeTask'),
            'WorkflowTaskRunner darf keine zweite Runner-/Skript-Registry mehr enthalten.',
        );
    }

    public function test_missing_task_key_is_inferred_from_a_unique_catalogued_node_script(): void
    {
        $catalog = app(WorkflowTaskCatalog::class);
        $regular = $catalog->resolveRuntimeTask([
            'runner' => 'node',
            'node_script' => '.\\node\\workflows\\tasks\\browser\\open.cjs',
        ]);
        $legacyLoop = $catalog->resolveRuntimeTask([
            'runner' => 'node',
            'node_script' => 'node/workflows/tasks/loop/for_each_element_legacy.cjs',
            'selector' => '.legacy-result',
        ]);

        $this->assertSame('browser.open', $regular['task_key']);
        $this->assertSame('node/workflows/tasks/browser/open.cjs', $regular['node_script']);
        $this->assertSame('loop.for_each_element', $legacyLoop['task_key']);
        $this->assertSame('node/workflows/tasks/loop/for_each_element_legacy.cjs', $legacyLoop['node_script']);
    }

    public function test_runtime_resolver_rejects_uncatalogued_executable_cards(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('missing.catalog.task');

        app(WorkflowTaskCatalog::class)->resolveRuntimeTask([
            'task_key' => 'missing.catalog.task',
            'runner' => 'node',
            'node_script' => 'node/workflows/tasks/browser/open.cjs',
        ]);
    }

    public function test_missing_task_key_is_not_inferred_from_an_unknown_node_script(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('(ohne task_key)');

        app(WorkflowTaskCatalog::class)->resolveRuntimeTask([
            'runner' => 'node',
            'node_script' => 'node/workflows/tasks/not-catalogued.cjs',
        ]);
    }

    public function test_missing_task_key_is_not_inferred_from_an_ambiguous_node_script(): void
    {
        $catalog = new class extends WorkflowTaskCatalog
        {
            public function all(): array
            {
                return [
                    'test.first' => ['runner' => 'node', 'node_script' => 'node/workflows/tasks/shared.cjs'],
                    'test.second' => ['runner' => 'node', 'node_script' => 'node/workflows/tasks/shared.cjs'],
                ];
            }
        };

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('(ohne task_key)');

        $catalog->resolveRuntimeTask([
            'runner' => 'node',
            'node_script' => 'node/workflows/tasks/shared.cjs',
        ]);
    }

    public function test_loop_for_each_element_keeps_its_legacy_variant(): void
    {
        $catalog = app(WorkflowTaskCatalog::class);
        $withoutSelector = $catalog->resolveRuntimeTask(['task_key' => 'loop.for_each_element']);
        $withSelector = $catalog->resolveRuntimeTask(['task_key' => 'loop.for_each_element', 'selector' => '#treffer a']);

        $this->assertSame(
            'node/workflows/tasks/loop/for_each_element.cjs',
            $withoutSelector['node_script'] ?? null,
            'Ohne Selector muss die reguläre Schleife verwendet werden.',
        );

        $this->assertSame(
            'node/workflows/tasks/loop/for_each_element_legacy.cjs',
            $withSelector['node_script'] ?? null,
            'Mit Selector muss die Legacy-DOM-Schleife verwendet werden. Diese Fallunterscheidung '
            .'muss ausdruecklich im Katalogvertrag erhalten bleiben.',
        );

        $this->assertTrue(
            File::exists(base_path('node/workflows/tasks/loop/for_each_element_legacy.cjs')),
            'Das Legacy-Schleifenskript fehlt, obwohl der Runner darauf verweist.',
        );
    }

    public function test_save_workflow_data_is_a_hidden_catalogued_compatibility_task(): void
    {
        $catalog = app(WorkflowTaskCatalog::class);
        $definition = $catalog->task('data.save_workflow_data');
        $resolved = $catalog->resolveRuntimeTask([
            'task_key' => 'data.save_workflow_data',
            'runner' => 'stale-runner',
            'node_script' => 'stale/script.cjs',
        ]);

        $this->assertNotNull($definition);
        $this->assertTrue((bool) ($definition['hidden_from_library'] ?? false));
        $this->assertSame('node', $resolved['runner']);
        $this->assertSame('node/workflows/tasks/data/save_workflow_data.cjs', $resolved['node_script']);
        $this->assertNotContains(
            'data.save_workflow_data',
            collect($catalog->options())->pluck('key')->all(),
            'Der Backcompat-Task darf nicht als neue Karte in der Bibliothek angeboten werden.',
        );

        $this->assertTrue(
            File::exists(base_path('node/workflows/tasks/data/save_workflow_data.cjs')),
            'save_workflow_data.cjs fehlt, wird aber von persist_mail_account.cjs eingebunden.',
        );
    }

    private function resolveNodeBinary(): ?string
    {
        $candidates = PHP_OS_FAMILY === 'Windows'
            ? ['C:\\Program Files\\nodejs\\node.exe', 'C:\\Program Files (x86)\\nodejs\\node.exe']
            : ['/usr/bin/node', '/usr/local/bin/node', '/bin/node', '/snap/bin/node'];

        foreach ($candidates as $candidate) {
            if (File::exists($candidate)) {
                return $candidate;
            }
        }

        $resolved = PHP_OS_FAMILY === 'Windows'
            ? Process::timeout(10)->run(['where.exe', 'node'])
            : Process::timeout(10)->run(['sh', '-lc', 'command -v node 2>/dev/null']);

        $binary = trim(strtok($resolved->output(), "\r\n") ?: '');

        return ($resolved->successful() && $binary !== '') ? $binary : null;
    }
}
