<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use ZipArchive;

class WorkflowTransferService
{
    public const FORMAT_VERSION = '1';

    public const CSV_FILENAME = 'workflows.csv';

    private const MAX_IMPORT_BYTES = 10_485_760;

    private const HEADERS = [
        'format_version',
        'source_id',
        'slug',
        'name',
        'description',
        'category',
        'subcategory',
        'is_active',
        'is_locked',
        'trigger_type',
        'settings_json',
        'steps_json',
    ];

    public function csv(iterable $workflows): string
    {
        $workflows = $this->workflowCollection($workflows);
        $workflowSlugs = Workflow::query()->pluck('slug', 'id');
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('CSV-Zwischenspeicher konnte nicht geoeffnet werden.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, self::HEADERS);

        foreach ($workflows as $workflow) {
            $steps = $workflow->steps
                ->map(fn (WorkflowStep $step): array => [
                    'name' => $step->name,
                    'type' => $step->type,
                    'action_key' => $step->action_key,
                    'position' => (int) $step->position,
                    'is_enabled' => (bool) $step->is_enabled,
                    'config' => $this->enrichWorkflowReferences(
                        is_array($step->config_json) ? $step->config_json : [],
                        $workflowSlugs,
                    ),
                    'retry_attempts' => max(0, (int) $step->retry_attempts),
                    'wait_after_seconds' => max(0, (int) $step->wait_after_seconds),
                ])
                ->values()
                ->all();

            fputcsv($stream, [
                self::FORMAT_VERSION,
                (int) $workflow->id,
                (string) $workflow->slug,
                (string) $workflow->name,
                (string) $workflow->description,
                (string) $workflow->category,
                (string) $workflow->subcategory,
                $workflow->is_active ? '1' : '0',
                $workflow->is_locked ? '1' : '0',
                (string) $workflow->trigger_type,
                $this->encodeJson(is_array($workflow->settings_json) ? $workflow->settings_json : []),
                $this->encodeJson($steps),
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new RuntimeException('CSV konnte nicht erzeugt werden.');
        }

        return $csv;
    }

    public function zip(iterable $workflows, ?string $baseName = null): array
    {
        $csv = $this->csv($workflows);
        $directory = storage_path('app/private/workflow-exports');

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Export-Verzeichnis konnte nicht erzeugt werden.');
        }

        $baseName = Str::slug($baseName ?: 'workflows-'.now()->format('Y-m-d-His')) ?: 'workflows-export';
        $filename = $baseName.'.zip';
        $path = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.zip';
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP-Datei konnte nicht erzeugt werden.');
        }

        $zip->addFromString(self::CSV_FILENAME, $csv);
        $zip->close();

        return ['path' => $path, 'filename' => $filename];
    }

    public function importFile(string $path, string $originalName): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Importdatei konnte nicht gelesen werden.');
        }

        if (filesize($path) > self::MAX_IMPORT_BYTES) {
            throw new InvalidArgumentException('Die Importdatei darf maximal 10 MB gross sein.');
        }

        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new InvalidArgumentException('CSV-Datei konnte nicht gelesen werden.');
            }

            return $this->importCsv($contents);
        }

        if ($extension === 'zip') {
            return $this->importCsv($this->csvFromZip($path));
        }

        throw new InvalidArgumentException('Erlaubt sind ausschliesslich CSV- und ZIP-Dateien.');
    }

    public function importCsv(string $csv): array
    {
        $definitions = $this->parseCsv($csv);
        $sourceIds = collect($definitions)
            ->filter(fn (array $definition): bool => $definition['source_id'] > 0)
            ->mapWithKeys(fn (array $definition): array => [$definition['source_id'] => $definition['slug']]);

        return DB::transaction(function () use ($definitions, $sourceIds): array {
            $created = 0;
            $updated = 0;
            $workflowsBySlug = collect();

            foreach ($definitions as $definition) {
                $workflow = Workflow::query()->where('slug', $definition['slug'])->first();
                $attributes = [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'category' => $definition['category'],
                    'subcategory' => $definition['subcategory'],
                    'is_active' => $definition['is_active'],
                    'is_locked' => $definition['is_locked'],
                    'trigger_type' => $definition['trigger_type'],
                    'settings_json' => $definition['settings'],
                ];

                if ($workflow) {
                    $workflow->forceFill($attributes)->save();
                    $updated++;
                } else {
                    $workflow = Workflow::query()->create(['slug' => $definition['slug'], ...$attributes]);
                    $created++;
                }

                $workflowsBySlug->put($definition['slug'], $workflow);
            }

            $allWorkflowIdsBySlug = Workflow::query()->pluck('id', 'slug');

            foreach ($definitions as $definition) {
                /** @var Workflow $workflow */
                $workflow = $workflowsBySlug->get($definition['slug']);
                $workflow->steps()->delete();

                foreach ($definition['steps'] as $step) {
                    $workflow->steps()->create([
                        'name' => $step['name'],
                        'type' => $step['type'],
                        'action_key' => $step['action_key'],
                        'position' => $step['position'],
                        'is_enabled' => $step['is_enabled'],
                        'config_json' => $this->remapWorkflowReferences(
                            $step['config'],
                            $sourceIds,
                            $allWorkflowIdsBySlug,
                        ),
                        'retry_attempts' => $step['retry_attempts'],
                        'wait_after_seconds' => $step['wait_after_seconds'],
                    ]);
                }
            }

            foreach ($workflowsBySlug as $workflow) {
                $workflow->syncIncludedWorkflowReferences();
            }

            return [
                'total' => count($definitions),
                'created' => $created,
                'updated' => $updated,
            ];
        });
    }

    protected function workflowCollection(iterable $workflows): Collection
    {
        return collect($workflows)
            ->filter(fn (mixed $workflow): bool => $workflow instanceof Workflow)
            ->unique(fn (Workflow $workflow): int => (int) $workflow->id)
            ->values()
            ->each(fn (Workflow $workflow) => $workflow->loadMissing(['steps' => fn ($query) => $query->ordered()]));
    }

    protected function csvFromZip(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('ZIP-Datei ist ungueltig oder beschaedigt.');
        }

        try {
            $csvIndex = null;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $entryName = (string) ($entry['name'] ?? '');

                if (Str::lower(pathinfo($entryName, PATHINFO_EXTENSION)) !== 'csv') {
                    continue;
                }

                if ((int) ($entry['size'] ?? 0) > self::MAX_IMPORT_BYTES) {
                    throw new InvalidArgumentException('Die CSV im ZIP darf maximal 10 MB gross sein.');
                }

                $csvIndex = $index;

                if (basename($entryName) === self::CSV_FILENAME) {
                    break;
                }
            }

            if ($csvIndex === null) {
                throw new InvalidArgumentException('Das ZIP enthaelt keine CSV-Datei.');
            }

            $contents = $zip->getFromIndex($csvIndex);

            if ($contents === false) {
                throw new InvalidArgumentException('CSV konnte nicht aus dem ZIP gelesen werden.');
            }

            return $contents;
        } finally {
            $zip->close();
        }
    }

    protected function parseCsv(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;

        if (strlen($csv) > self::MAX_IMPORT_BYTES) {
            throw new InvalidArgumentException('Die CSV darf maximal 10 MB gross sein.');
        }

        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('CSV-Zwischenspeicher konnte nicht geoeffnet werden.');
        }

        fwrite($stream, $csv);
        rewind($stream);
        $headers = fgetcsv($stream);

        if ($headers !== self::HEADERS) {
            fclose($stream);
            throw new InvalidArgumentException('CSV-Format oder Spalten entsprechen keinem Workflow-Export.');
        }

        $definitions = [];
        $line = 1;

        while (($row = fgetcsv($stream)) !== false) {
            $line++;

            if ($row === [null] || $row === []) {
                continue;
            }

            if (count($row) !== count(self::HEADERS)) {
                fclose($stream);
                throw new InvalidArgumentException("CSV-Zeile {$line} hat eine ungueltige Spaltenanzahl.");
            }

            $data = array_combine(self::HEADERS, $row);

            if (($data['format_version'] ?? '') !== self::FORMAT_VERSION) {
                fclose($stream);
                throw new InvalidArgumentException("CSV-Zeile {$line} verwendet eine nicht unterstuetzte Formatversion.");
            }

            $definitions[] = $this->normalizeDefinition($data, $line);
        }

        fclose($stream);

        if ($definitions === []) {
            throw new InvalidArgumentException('Die CSV enthaelt keine Workflows.');
        }

        $slugs = array_column($definitions, 'slug');

        if (count($slugs) !== count(array_unique($slugs))) {
            throw new InvalidArgumentException('Die CSV enthaelt doppelte Workflow-Slugs.');
        }

        return $definitions;
    }

    protected function normalizeDefinition(array $data, int $line): array
    {
        $slug = Str::slug((string) ($data['slug'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));

        if ($slug === '' || $name === '') {
            throw new InvalidArgumentException("CSV-Zeile {$line} benoetigt Slug und Name.");
        }

        if (mb_strlen($name) > 160) {
            throw new InvalidArgumentException("Der Workflow-Name in CSV-Zeile {$line} ist zu lang.");
        }

        $settings = $this->decodeJson((string) $data['settings_json'], "Einstellungen in CSV-Zeile {$line}");
        $steps = $this->decodeJson((string) $data['steps_json'], "Listen in CSV-Zeile {$line}");

        return [
            'source_id' => max(0, (int) $data['source_id']),
            'slug' => $slug,
            'name' => $name,
            'description' => trim((string) $data['description']),
            'category' => Str::slug((string) $data['category'], '_') ?: 'custom',
            'subcategory' => Str::slug((string) $data['subcategory'], '_') ?: null,
            'is_active' => $this->booleanValue($data['is_active']),
            'is_locked' => $this->booleanValue($data['is_locked']),
            'trigger_type' => Str::slug((string) $data['trigger_type'], '_') ?: 'manual',
            'settings' => $settings,
            'steps' => collect($steps)
                ->values()
                ->map(fn (mixed $step, int $index): array => $this->normalizeStep($step, $line, $index))
                ->all(),
        ];
    }

    protected function normalizeStep(mixed $step, int $line, int $index): array
    {
        if (! is_array($step)) {
            throw new InvalidArgumentException('Liste '.($index + 1)." in CSV-Zeile {$line} ist ungueltig.");
        }

        $name = trim((string) ($step['name'] ?? ''));
        $type = trim((string) ($step['type'] ?? ''));

        if ($name === '' || $type === '') {
            throw new InvalidArgumentException('Liste '.($index + 1)." in CSV-Zeile {$line} benoetigt Name und Typ.");
        }

        return [
            'name' => Str::limit($name, 160, ''),
            'type' => Str::limit($type, 120, ''),
            'action_key' => trim((string) ($step['action_key'] ?? '')) ?: null,
            'position' => max(0, (int) ($step['position'] ?? (($index + 1) * 10))),
            'is_enabled' => $this->booleanValue($step['is_enabled'] ?? true),
            'config' => is_array($step['config'] ?? null) ? $step['config'] : [],
            'retry_attempts' => min(255, max(0, (int) ($step['retry_attempts'] ?? 0))),
            'wait_after_seconds' => max(0, (int) ($step['wait_after_seconds'] ?? 0)),
        ];
    }

    protected function enrichWorkflowReferences(array $value, Collection $workflowSlugs): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->enrichWorkflowReferences($entry, $workflowSlugs);
            }
        }

        if ((string) ($value['runner'] ?? '') === 'workflow') {
            $workflowId = (int) ($value['workflow_id'] ?? 0);
            $workflowSlug = trim((string) ($value['workflow_slug'] ?? ''));

            if ($workflowSlug === '' && $workflowId > 0) {
                $value['workflow_slug'] = (string) ($workflowSlugs->get($workflowId) ?? '');
            }
        }

        return $value;
    }

    protected function remapWorkflowReferences(array $value, Collection $sourceIds, Collection $workflowIdsBySlug): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->remapWorkflowReferences($entry, $sourceIds, $workflowIdsBySlug);
            }
        }

        if ((string) ($value['runner'] ?? '') === 'workflow') {
            $slug = trim((string) ($value['workflow_slug'] ?? ''));

            if ($slug === '' && (int) ($value['workflow_id'] ?? 0) > 0) {
                $slug = (string) ($sourceIds->get((int) $value['workflow_id']) ?? '');
            }

            if ($slug !== '' && $workflowIdsBySlug->has($slug)) {
                $value['workflow_slug'] = $slug;
                $value['workflow_id'] = (int) $workflowIdsBySlug->get($slug);
            } elseif ($slug !== '') {
                $value['workflow_slug'] = $slug;
                $value['workflow_id'] = 0;
            }
        }

        return $value;
    }

    protected function encodeJson(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Workflow-Daten konnten nicht als JSON serialisiert werden.', previous: $exception);
        }
    }

    protected function decodeJson(string $value, string $field): array
    {
        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("{$field} enthalten ungueltiges JSON.", previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("{$field} muessen ein JSON-Array oder -Objekt enthalten.");
        }

        return $decoded;
    }

    protected function booleanValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
