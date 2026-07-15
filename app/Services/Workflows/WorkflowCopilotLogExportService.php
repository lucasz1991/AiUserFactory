<?php

namespace App\Services\Workflows;

use App\Models\WorkflowCopilotSession;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class WorkflowCopilotLogExportService
{
    /** @var array<int, string> */
    protected array $sensitiveValues = [];

    public function __construct(
        protected WorkflowRunDebugPackageService $runDebugPackages,
    ) {}

    /** @return array{path:string, filename:string} */
    public function make(WorkflowCopilotSession $session): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP-Zip-Erweiterung ist nicht verfuegbar.');
        }

        $session->loadMissing([
            'workflow.steps' => fn ($query) => $query->ordered(),
            'events',
            'revisions',
            'taskAttempts',
            'checkpoints',
            'runs.stepRuns.workflowStep',
        ]);

        if (! $session->workflow) {
            throw new RuntimeException('Workflow der Copilot-Sitzung wurde nicht gefunden.');
        }

        $directory = storage_path('app/private/workflow-copilot-logs');
        File::ensureDirectoryExists($directory);
        $slug = Str::slug($session->workflow->slug ?: $session->workflow->name) ?: 'workflow';
        $filename = 'workflow-copilot-log-'.$slug.'-session-'.$session->id.'-'.now()->format('Y-m-d-His').'.zip';
        $path = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.zip';
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Copilot-Protokoll-ZIP konnte nicht erzeugt werden.');
        }

        $temporaryRunPackages = [];
        $manifest = [
            'schema_version' => 1,
            'generated_at' => now()->toIso8601String(),
            'workflow_id' => (int) $session->workflow_id,
            'workflow_name' => (string) $session->workflow->name,
            'copilot_session_id' => (int) $session->id,
            'copilot_session_uuid' => (string) $session->session_uuid,
            'files' => [],
            'warnings' => [],
        ];

        try {
            $rawPayload = [
                'schema_version' => 1,
                'generated_at' => now()->toIso8601String(),
                'session' => $session->withoutRelations()->toArray(),
                'workflow' => $session->workflow->toArray(),
                'events' => $session->events->toArray(),
                'revisions' => $session->revisions->toArray(),
                'task_attempts' => $session->taskAttempts->toArray(),
                'checkpoints' => $session->checkpoints->toArray(),
                'runs' => $session->runs->toArray(),
            ];
            $this->sensitiveValues = array_values(array_unique($this->collectSensitiveValues($rawPayload)));
            $payload = $this->sanitize($rawPayload);
            $this->addJson($zip, $manifest, 'optimization/complete-log.json', $payload);
            $this->addJson($zip, $manifest, 'workflow/final-workflow.json', $this->sanitize($session->workflow->toArray()));

            $events = collect($session->events)
                ->map(fn ($event): array => $this->sanitize($event->toArray()))
                ->values();
            $eventJsonl = $events
                ->map(fn (array $event): string => json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}')
                ->implode("\n");
            $this->addString($zip, $manifest, 'optimization/events.jsonl', $eventJsonl.($eventJsonl !== '' ? "\n" : ''));
            $this->addJson(
                $zip,
                $manifest,
                'optimization/chat-and-tools.json',
                $events->filter(fn (array $event): bool => $this->isConversationEvent((string) ($event['event_type'] ?? '')))->values()->all(),
            );

            foreach ($session->runs as $run) {
                try {
                    $runExport = $this->runDebugPackages->make($run);
                    $entry = 'runs/workflow-run-'.$run->id.'-debug.zip';

                    if ($zip->addFile($runExport['path'], $entry) !== true) {
                        throw new RuntimeException('Run-Debugpaket konnte dem Copilot-Protokoll nicht hinzugefuegt werden.');
                    }

                    $temporaryRunPackages[] = $runExport['path'];
                    $manifest['files'][] = $entry;
                } catch (Throwable $exception) {
                    $manifest['warnings'][] = 'Run #'.$run->id.': '.$exception->getMessage();
                }
            }

            $readme = implode("\n", [
                '# Workflow-Copilot Optimierungsprotokoll',
                '',
                'Dieses Paket enthaelt den gespeicherten Ablauf der Copilot-Sitzung inklusive sichtbarer Chatantworten, Toolaufrufe, Toolergebnisse, Ereignisse, Workflow-Revisionen, Checkpoints und Taskversuche.',
                '',
                '- optimization/complete-log.json: Gesamtsnapshot der Sitzung.',
                '- optimization/events.jsonl: Unveraenderliche Ereignisfolge in zeitlicher Reihenfolge.',
                '- optimization/chat-and-tools.json: Sichtbare Antworten sowie Toolaufrufe und -ergebnisse.',
                '- workflow/final-workflow.json: Workflow-Stand beim Export.',
                '- runs/*-debug.zip: Bereinigtes Debugpaket jedes zugeordneten Vorschau-Tests.',
                '',
                'Passwoerter, Tokens, Cookies, Session-Payloads und WebSocket-Endpunkte werden im Export redigiert.',
                '',
            ]);
            $this->addString($zip, $manifest, 'README.md', $readme);
            $this->addJson($zip, $manifest, 'manifest.json', $manifest);
        } finally {
            $zip->close();

            foreach ($temporaryRunPackages as $temporaryRunPackage) {
                @unlink($temporaryRunPackage);
            }
        }

        return ['path' => $path, 'filename' => $filename];
    }

    protected function addJson(ZipArchive $zip, array &$manifest, string $name, mixed $payload): void
    {
        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if ($json === false) {
            throw new RuntimeException('Copilot-Protokolldaten konnten nicht als JSON serialisiert werden.');
        }

        $this->addString($zip, $manifest, $name, $json."\n");
    }

    protected function addString(ZipArchive $zip, array &$manifest, string $name, string $contents): void
    {
        if ($zip->addFromString($name, $contents) !== true) {
            throw new RuntimeException('Datei konnte nicht zum Copilot-Protokoll hinzugefuegt werden: '.$name);
        }

        $manifest['files'][] = $name;
    }

    protected function isConversationEvent(string $eventType): bool
    {
        return Str::startsWith($eventType, ['chat.', 'assistant.', 'tool.', 'instruction.']);
    }

    protected function sanitize(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return is_string($payload) ? $this->sanitizeText($payload) : $payload;
        }

        $sanitized = [];

        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[redacted]';

                continue;
            }

            $sanitized[$key] = $this->sanitize($value);
        }

        return $sanitized;
    }

    protected function sanitizeText(string $value): string
    {
        if ($this->sensitiveValues !== []) {
            $value = str_replace($this->sensitiveValues, '[redacted]', $value);
        }

        return preg_replace([
            '#\b(?:wss?|cdp)://[^\s"\']+#i',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i',
            '/\b(password|passwd|pwd|secret|token|cookie|authorization|signature|credential|session(?:_?id)?|api[_-]?key)\s*[:=]\s*[^\s,;]+/i',
            '/\beyJ[A-Za-z0-9_-]{2,}\.[A-Za-z0-9_-]{3,}\.[A-Za-z0-9_-]{3,}\b/',
            '/\b[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
        ], [
            '[websocket redacted]',
            'Bearer [redacted]',
            '$1=[redacted]',
            '[token redacted]',
            '[token redacted]',
        ], $value) ?? $value;
    }

    /** @return array<int, string> */
    protected function collectSensitiveValues(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $values = [];

        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $values = [...$values, ...$this->scalarSensitiveValues($value)];

                continue;
            }

            $values = [...$values, ...$this->collectSensitiveValues($value)];
        }

        return $values;
    }

    /** @return array<int, string> */
    protected function scalarSensitiveValues(mixed $payload): array
    {
        if (is_array($payload)) {
            return collect($payload)
                ->flatMap(fn (mixed $value): array => $this->scalarSensitiveValues($value))
                ->values()
                ->all();
        }

        if (! is_scalar($payload)) {
            return [];
        }

        $value = trim((string) $payload);

        return mb_strlen($value) >= 4 && $value !== '[redacted]' ? [$value] : [];
    }

    protected function isSensitiveKey(string $key): bool
    {
        $key = Str::lower(str_replace(['-', '_', ' '], '', $key));

        return str_contains($key, 'password')
            || str_contains($key, 'secret')
            || str_contains($key, 'token')
            || str_contains($key, 'cookie')
            || str_contains($key, 'sessionpayload')
            || str_contains($key, 'sessionfilepath')
            || str_contains($key, 'payloadencrypted')
            || str_contains($key, 'browserwsendpoint')
            || str_contains($key, 'websocketendpoint')
            || in_array($key, ['webmailsession', 'webmailsessiondata', 'browsersession', 'browsersessiondata'], true)
            || $key === 'wsendpoint';
    }
}
