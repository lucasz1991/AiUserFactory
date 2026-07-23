<?php

namespace Tests\Unit;

use App\Services\Workflows\WorkflowTaskCatalog;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Sichert den Vertrag zwischen dem PHP-Task-Katalog und den Node-Task-Skripten.
 *
 * Hintergrund: `node_script` wird heute an ZWEI Stellen gepflegt — im
 * `WorkflowTaskCatalog` und zusaetzlich in
 * `WorkflowTaskRunner::normalizeRuntimeTask()`. Fehlt oder driftet ein Skript,
 * faellt das bisher erst zur Laufzeit auf ("Task-Script exportiert keine
 * run()-Funktion", run_step.cjs). Diese Tests ziehen den Fehler nach vorn und
 * dokumentieren zugleich, welche Faelle beim Entfernen der zweiten Registry
 * (Paket P2a) NICHT ersatzlos wegfallen duerfen.
 *
 * Siehe docs/workflow-runtime-analyse-und-optimierung.md, Befund B4.
 */
class WorkflowTaskScriptRegistryTest extends TestCase
{
    /**
     * Katalogkeys, deren Node-Skript bewusst von der Katalogangabe abweichen
     * darf, weil `normalizeRuntimeTask()` eine echte Fallunterscheidung trifft.
     */
    private const KEYS_WITH_RUNTIME_SCRIPT_VARIANTS = [
        // Bei gesetztem Selector wird auf die Legacy-DOM-Schleife umgebogen.
        'loop.for_each_element',
    ];

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
            ->map(fn (array $definition): string => trim((string) ($definition['node_script'] ?? '')))
            ->filter()
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

for (const [key, relativePath] of Object.entries(map)) {
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

    public function test_normalize_runtime_task_never_contradicts_the_catalog(): void
    {
        $runner = app(WorkflowTaskRunner::class);

        if (! method_exists($runner, 'normalizeRuntimeTask')) {
            $this->markTestSkipped('normalizeRuntimeTask() existiert nicht mehr — Paket P2a ist offenbar umgesetzt.');
        }

        $method = new ReflectionMethod($runner, 'normalizeRuntimeTask');
        $method->setAccessible(true);

        $conflicts = [];

        foreach (app(WorkflowTaskCatalog::class)->all() as $key => $definition) {
            $catalogScript = trim((string) ($definition['node_script'] ?? ''));

            if ($catalogScript === '' || in_array($key, self::KEYS_WITH_RUNTIME_SCRIPT_VARIANTS, true)) {
                continue;
            }

            $normalized = $method->invoke($runner, ['task_key' => $key]);
            $runtimeScript = trim((string) ($normalized['node_script'] ?? ''));

            // Keys, die normalizeRuntimeTask() gar nicht kennt, bleiben
            // unveraendert — das ist kein Konflikt, sondern der Normalfall.
            if ($runtimeScript !== '' && $runtimeScript !== $catalogScript) {
                $conflicts[] = sprintf('%s: Katalog "%s" vs. Runner "%s"', $key, $catalogScript, $runtimeScript);
            }
        }

        $this->assertSame(
            [],
            $conflicts,
            "Die zweite node_script-Registry widerspricht dem Katalog:\n".implode("\n", $conflicts),
        );
    }

    public function test_loop_for_each_element_keeps_its_legacy_variant(): void
    {
        $runner = app(WorkflowTaskRunner::class);

        if (! method_exists($runner, 'normalizeRuntimeTask')) {
            $this->markTestSkipped('normalizeRuntimeTask() existiert nicht mehr — Paket P2a ist offenbar umgesetzt.');
        }

        $method = new ReflectionMethod($runner, 'normalizeRuntimeTask');
        $method->setAccessible(true);

        $withoutSelector = $method->invoke($runner, ['task_key' => 'loop.for_each_element']);
        $withSelector = $method->invoke($runner, ['task_key' => 'loop.for_each_element', 'selector' => '#treffer a']);

        $this->assertSame(
            'node/workflows/tasks/loop/for_each_element.cjs',
            $withoutSelector['node_script'] ?? null,
            'Ohne Selector muss die reguläre Schleife verwendet werden.',
        );

        $this->assertSame(
            'node/workflows/tasks/loop/for_each_element_legacy.cjs',
            $withSelector['node_script'] ?? null,
            'Mit Selector muss die Legacy-DOM-Schleife verwendet werden. Diese Fallunterscheidung '
            .'darf beim Entfernen von normalizeRuntimeTask() (Paket P2a) nicht verloren gehen.',
        );

        $this->assertTrue(
            File::exists(base_path('node/workflows/tasks/loop/for_each_element_legacy.cjs')),
            'Das Legacy-Schleifenskript fehlt, obwohl der Runner darauf verweist.',
        );
    }

    public function test_save_workflow_data_gap_between_runner_and_catalog_is_documented(): void
    {
        $catalog = app(WorkflowTaskCatalog::class)->all();

        // `data.save_workflow_data` wird in WorkflowExecutionService ausgewertet
        // und von normalizeRuntimeTask() auf ein Skript abgebildet, steht aber
        // NICHT im Katalog. Damit ist der Key fuer den Validator unbekannt.
        // Schlaegt dieser Test fehl, wurde die Luecke geschlossen: dann den
        // Hinweis im README-Abschnitt "Befund fuer Codex: P2a ist keine reine
        // Loeschung" entfernen und diesen Test loeschen.
        $this->assertArrayNotHasKey(
            'data.save_workflow_data',
            $catalog,
            'data.save_workflow_data steht jetzt im Katalog — README-Hinweis zu P2a bitte aktualisieren.',
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
