<?php

namespace App\Services\Ai;

use App\Models\Setting;
use App\Services\Workflows\WorkflowCopilotObservationService;
use App\Services\Workflows\WorkflowTaskCatalog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class WorkflowCopilotVisionService
{
    protected const MIN_ACTION_CONFIDENCE = 0.55;

    protected const TASKS_REQUIRING_ELEMENT_REF = [
        'browser.click',
        'browser.hover',
        'browser.read_element_fields',
        'input.fill_field',
        'input.submit',
    ];

    public function __construct(
        protected AiConnectionService $ai,
        protected WorkflowTaskCatalog $taskCatalog,
        protected WorkflowCopilotObservationService $observations,
    ) {}

    public function analyze(array $observation, string $goal = ''): array
    {
        $startedAt = microtime(true);
        $attempts = [];
        $models = $this->configuredModels();
        $image = $this->validImageDataUrl($observation['screenshot_data_url'] ?? null);
        $safeObservation = $this->safeObservation($observation, $image !== null);
        $prompt = $this->prompt($safeObservation, $goal, $image !== null);

        if ($image !== null) {
            foreach ($models as $index => $model) {
                $attemptStartedAt = microtime(true);

                try {
                    $response = $this->ai->imageUnderstanding($prompt, $image, [
                        'model' => $model,
                        'temperature' => 0,
                        'max_completion_tokens' => 1800,
                        'response_format' => ['type' => 'json_object'],
                        '_timeout' => 120,
                    ]);
                    $decoded = $this->decodeResponse($response);
                    $result = $this->normalizeResult($decoded, $safeObservation);
                    $duration = $this->durationMs($attemptStartedAt);

                    if ($result === null) {
                        $attempts[] = $this->attempt($model, 'vision', $duration, $index > 0, 'invalid');
                        $this->logAttempt(end($attempts));

                        continue;
                    }

                    $attempts[] = $this->attempt($model, 'vision', $duration, $index > 0, 'success', null, $result);
                    $this->logAttempt(end($attempts));

                    return $this->finish($result, $model, 'vision', $attempts, $startedAt, $index > 0);
                } catch (Throwable $exception) {
                    $attempts[] = $this->attempt(
                        $model,
                        'vision',
                        $this->durationMs($attemptStartedAt),
                        $index > 0,
                        'error',
                        $exception->getMessage(),
                    );
                    $this->logAttempt(end($attempts));
                }
            }
        }

        if ($this->hasDomEvidence($safeObservation)) {
            $attemptStartedAt = microtime(true);
            $model = $models[0] ?? 'configured-data-model';

            try {
                $decoded = $this->ai->json(
                    $this->domOnlyPrompt($safeObservation, $goal),
                    'Du analysierst ausschliesslich die bereitgestellte, redigierte DOM-Interaktionskarte. '
                        .'Erfinde keine sichtbaren Elemente und fuehre keine Aktion aus. Antworte nur als JSON-Objekt.',
                    array_filter([
                        'model' => $models[0] ?? null,
                        'temperature' => 0,
                        'max_completion_tokens' => 1600,
                        '_timeout' => 90,
                    ], static fn (mixed $value): bool => $value !== null),
                );
                $result = $this->normalizeResult($decoded, $safeObservation);
                $duration = $this->durationMs($attemptStartedAt);

                if ($result !== null) {
                    $attempts[] = $this->attempt($model, 'dom', $duration, true, 'success', null, $result);
                    $this->logAttempt(end($attempts));

                    return $this->finish($result, $model, 'dom', $attempts, $startedAt, true);
                }

                $attempts[] = $this->attempt($model, 'dom', $duration, true, 'invalid');
                $this->logAttempt(end($attempts));
            } catch (Throwable $exception) {
                $attempts[] = $this->attempt(
                    $model,
                    'dom',
                    $this->durationMs($attemptStartedAt),
                    true,
                    'error',
                    $exception->getMessage(),
                );
                $this->logAttempt(end($attempts));
            }
        }

        return $this->finish(
            $this->safePauseResult($safeObservation),
            null,
            'safe_pause',
            $attempts,
            $startedAt,
            $attempts !== [],
        );
    }

    protected function configuredModels(): array
    {
        $openRouter = $this->setting('services', 'openrouter');
        $copilot = $this->setting('ai_assistant', 'workflow_copilot');
        $primary = trim((string) (
            $openRouter['image_understanding_model']
            ?? $openRouter['vision_model']
            ?? config('services.openrouter.image_understanding_model')
            ?? config('services.openrouter.vision_model')
            ?? ''
        ));
        $fallbacks = $copilot['vision_fallback_models']
            ?? $openRouter['vision_fallback_models']
            ?? config('services.openrouter.vision_fallback_models', []);

        return collect([$primary, ...$this->modelList($fallbacks)])
            ->map(fn (mixed $model): string => trim((string) $model))
            ->filter()
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }

    protected function setting(string $type, string $key): array
    {
        try {
            $value = Setting::getValue($type, $key);

            return is_array($value) ? $value : [];
        } catch (Throwable) {
            return [];
        }
    }

    protected function modelList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[\r\n,;]+/', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn (mixed $model): string => trim((string) (is_array($model) ? ($model['model'] ?? $model['id'] ?? '') : $model)))
            ->filter()
            ->values()
            ->all();
    }

    protected function safeObservation(array $observation, bool $hasValidImage): array
    {
        unset($observation['screenshot_data_url']);

        $interactionMap = array_slice(
            is_array($observation['interaction_map'] ?? null) ? $observation['interaction_map'] : [],
            0,
            WorkflowCopilotObservationService::MAX_ELEMENTS,
        );
        $hasDomEvidence = $interactionMap !== [] || filled(data_get($observation, 'dom.visible_text_excerpt'));

        return (array) $this->observations->sanitizeForModel([
            'state_signature' => $observation['state_signature'] ?? null,
            'page' => $observation['page'] ?? [],
            'dom' => $observation['dom'] ?? [],
            'interaction_map' => $interactionMap,
            'screenshot' => [
                'available_for_vision' => $hasValidImage,
                'mime_type' => data_get($observation, 'screenshot.mime_type'),
                'size_bytes' => data_get($observation, 'screenshot.size_bytes'),
            ],
            'evidence_sufficient' => $hasValidImage || $hasDomEvidence,
        ]);
    }

    protected function prompt(array $observation, string $goal, bool $hasImage): string
    {
        return implode("\n\n", [
            'Analysiere den aktuellen Workflow-Bildschirm anhand '.($hasImage ? 'des Screenshots und ' : '').'der nummerierbaren DOM-Interaktionskarte. '
                .'Das Bild dient nur zur Beobachtung. Du fuehrst keine Aktion aus, erfindest keine Elemente und gibst keine internen Gedankengaenge aus.',
            'Workflow-Ziel: '.$this->safeGoal($goal),
            'Erlaubte Task-Keys: '.implode(', ', array_keys($this->taskCatalog->all())),
            'Antworte als JSON-Objekt mit exakt diesen Feldern: '
                .'page_type (string), ui_state (string), goal_progress (Zahl 0..1 oder kurze Beschreibung), '
                .'blockers (string[]), relevant_elements ({element_ref, reason, confidence}[]), confidence (Zahl 0..1), '
                .'suggested_task_actions ({task_key, element_ref, parameters, reason, confidence}[]), '
                .'needs_screenshot (boolean), verdict (pass|continue|pause). '
                .'Verwende nur element_ref-Werte aus interaction_map und nur erlaubte Task-Keys. '
                .'Rohwerte fuer Passwort, Token, Cookie oder Eingabefelder duerfen nicht vorgeschlagen werden; nutze allenfalls value_reference.',
            'Redigierte Beobachtung: '.$this->encode($observation),
        ]);
    }

    protected function domOnlyPrompt(array $observation, string $goal): string
    {
        return $this->prompt($observation, $goal, false)
            ."\n\nEs gibt keinen verlaesslichen Screenshot. Wenn DOM und URL den Zustand nicht eindeutig belegen, setze verdict=pause, needs_screenshot=true und suggested_task_actions=[].";
    }

    protected function decodeResponse(array $response): array
    {
        $content = data_get($response, 'choices.0.message.content');

        if (is_array($content)) {
            $content = collect($content)
                ->map(fn (mixed $part): string => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part)
                ->implode('');
        }

        if (! is_string($content)) {
            throw new RuntimeException('Vision-Modell lieferte keinen JSON-Inhalt.');
        }

        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?: $content;
        $content = preg_replace('/\s*```$/', '', $content) ?: $content;
        $firstBrace = strpos($content, '{');
        $lastBrace = strrpos($content, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace >= $firstBrace) {
            $content = substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Vision-Modell lieferte ungueltiges JSON.');
        }

        return $decoded;
    }

    protected function normalizeResult(array $value, array $observation): ?array
    {
        $aliases = [
            'page_type' => $value['page_type'] ?? $value['pageType'] ?? null,
            'ui_state' => $value['ui_state'] ?? $value['page_state'] ?? $value['uiState'] ?? $value['pageState'] ?? null,
            'goal_progress' => $value['goal_progress'] ?? $value['goalProgress'] ?? null,
            'blockers' => $value['blockers'] ?? null,
            'relevant_elements' => $value['relevant_elements'] ?? $value['elements'] ?? $value['relevantElements'] ?? null,
            'confidence' => $value['confidence'] ?? null,
            'suggested_task_actions' => $value['suggested_task_actions'] ?? $value['recommended_actions'] ?? $value['suggestedTaskActions'] ?? $value['recommendedActions'] ?? null,
            'needs_screenshot' => $value['needs_screenshot'] ?? $value['needs_new_observation'] ?? $value['needsScreenshot'] ?? $value['needsNewObservation'] ?? null,
            'verdict' => $value['verdict'] ?? null,
        ];

        if (
            ! is_string($aliases['page_type'])
            || trim($aliases['page_type']) === ''
            || ! is_string($aliases['ui_state'])
            || trim($aliases['ui_state']) === ''
            || $aliases['goal_progress'] === null
            || ! is_array($aliases['blockers'])
            || ! is_array($aliases['relevant_elements'])
            || ! is_numeric($aliases['confidence'])
            || ! is_array($aliases['suggested_task_actions'])
            || ! is_bool($aliases['needs_screenshot'])
            || ! is_string($aliases['verdict'])
        ) {
            return null;
        }

        $confidence = max(0, min(1, (float) $aliases['confidence']));
        $allowedRefs = collect($observation['interaction_map'] ?? [])
            ->pluck('element_ref')
            ->filter(fn (mixed $ref): bool => is_string($ref) && $ref !== '')
            ->flip();
        $blockers = collect($aliases['blockers'])
            ->filter(fn (mixed $blocker): bool => is_scalar($blocker))
            ->map(fn (mixed $blocker): string => Str::limit(trim((string) $this->observations->sanitizeForModel($blocker)), 300, ''))
            ->filter()
            ->take(8)
            ->values()
            ->all();
        $elements = collect($aliases['relevant_elements'])
            ->map(function (mixed $element) use ($allowedRefs): ?array {
                $element = is_string($element) ? ['element_ref' => $element] : $element;

                if (! is_array($element)) {
                    return null;
                }

                $ref = trim((string) ($element['element_ref'] ?? $element['elementRef'] ?? ''));

                if ($ref === '' || ! $allowedRefs->has($ref)) {
                    return null;
                }

                return [
                    'element_ref' => $ref,
                    'reason' => Str::limit(trim((string) $this->observations->sanitizeForModel($element['reason'] ?? '')), 300, '') ?: null,
                    'confidence' => is_numeric($element['confidence'] ?? null)
                        ? max(0, min(1, (float) $element['confidence']))
                        : null,
                ];
            })
            ->filter()
            ->take(12)
            ->values()
            ->all();
        $relevantRefs = collect($elements)->pluck('element_ref')->flip();
        $actions = collect($aliases['suggested_task_actions'])
            ->map(function (mixed $action) use ($allowedRefs, $relevantRefs): ?array {
                if (! is_array($action)) {
                    return null;
                }

                $taskKey = trim((string) ($action['task_key'] ?? $action['taskKey'] ?? ''));
                $elementRef = trim((string) ($action['element_ref'] ?? $action['elementRef'] ?? ''));

                if ($taskKey === '' || $this->taskCatalog->task($taskKey) === null) {
                    return null;
                }

                if ($elementRef !== '' && ! $allowedRefs->has($elementRef)) {
                    return null;
                }

                if ($elementRef !== '' && ! $relevantRefs->has($elementRef)) {
                    return null;
                }

                if (in_array($taskKey, self::TASKS_REQUIRING_ELEMENT_REF, true) && $elementRef === '') {
                    return null;
                }

                $parameters = is_array($action['parameters'] ?? null)
                    ? (array) $this->observations->sanitizeForModel($action['parameters'])
                    : [];

                return [
                    'task_key' => $taskKey,
                    'element_ref' => $elementRef ?: null,
                    'parameters' => $parameters,
                    'reason' => Str::limit(trim((string) $this->observations->sanitizeForModel($action['reason'] ?? '')), 300, '') ?: null,
                    'confidence' => is_numeric($action['confidence'] ?? null)
                        ? max(0, min(1, (float) $action['confidence']))
                        : null,
                ];
            })
            ->filter()
            ->take(8)
            ->values()
            ->all();
        $verdict = Str::lower(trim((string) $aliases['verdict']));
        $verdict = in_array($verdict, ['pass', 'continue', 'pause'], true) ? $verdict : 'pause';
        $needsScreenshot = $aliases['needs_screenshot'];
        $safePause = false;

        if ($confidence < self::MIN_ACTION_CONFIDENCE || ! (bool) ($observation['evidence_sufficient'] ?? false)) {
            $verdict = 'pause';
            $actions = [];
            $needsScreenshot = true;
            $safePause = true;
            $blockers[] = 'Die Beobachtung ist fuer eine autonome Aktion nicht verlaesslich genug.';
        }

        if ($aliases['suggested_task_actions'] !== [] && $actions === []) {
            $verdict = 'pause';
            $needsScreenshot = true;
            $safePause = true;
            $blockers[] = 'Die vorgeschlagenen Aktionen konnten keinem erlaubten Task und DOM-Element sicher zugeordnet werden.';
        }

        if ($needsScreenshot && ! (bool) data_get($observation, 'screenshot.available_for_vision', false)) {
            $verdict = 'pause';
            $actions = [];
            $safePause = true;
        }

        return [
            'page_type' => Str::limit(trim((string) $this->observations->sanitizeForModel($aliases['page_type'])), 120, ''),
            'ui_state' => Str::limit(trim((string) $this->observations->sanitizeForModel($aliases['ui_state'])), 160, ''),
            'goal_progress' => $this->goalProgress($aliases['goal_progress']),
            'blockers' => array_values(array_unique(array_slice($blockers, 0, 8))),
            'relevant_elements' => $elements,
            'confidence' => round($confidence, 3),
            'suggested_task_actions' => $actions,
            'needs_screenshot' => $needsScreenshot,
            'verdict' => $verdict,
            'safe_pause' => $safePause,
        ];
    }

    protected function safePauseResult(array $observation): array
    {
        return [
            'page_type' => 'unknown',
            'ui_state' => Str::limit((string) data_get($observation, 'dom.ui_state', 'unknown_browser_state'), 160, ''),
            'goal_progress' => 'unknown',
            'blockers' => ['Screenshot und DOM liefern keine ausreichend verlaessliche Evidenz. Sitzung sicher pausieren.'],
            'relevant_elements' => [],
            'confidence' => 0.0,
            'suggested_task_actions' => [],
            'needs_screenshot' => true,
            'verdict' => 'pause',
            'safe_pause' => true,
        ];
    }

    protected function finish(array $result, ?string $model, string $source, array $attempts, float $startedAt, bool $fallbackUsed): array
    {
        return [
            ...$result,
            'analysis_source' => $source,
            'model' => $model,
            'fallback_used' => $fallbackUsed,
            'attempts' => $attempts,
            'duration_ms' => $this->durationMs($startedAt),
        ];
    }

    protected function attempt(
        string $model,
        string $mode,
        int $duration,
        bool $fallback,
        string $status,
        ?string $error = null,
        ?array $result = null,
    ): array {
        return array_filter([
            'model' => Str::limit($model, 200, ''),
            'mode' => $mode,
            'duration_ms' => $duration,
            'fallback' => $fallback,
            'status' => $status,
            'error' => $error !== null
                ? Str::limit((string) $this->observations->sanitizeForModel($error), 300, '')
                : null,
            'result' => $result !== null ? [
                'page_type' => $result['page_type'],
                'ui_state' => $result['ui_state'],
                'confidence' => $result['confidence'],
                'verdict' => $result['verdict'],
            ] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    protected function logAttempt(array $attempt): void
    {
        Log::info('Workflow Copilot vision analysis attempt.', $attempt);
    }

    protected function hasDomEvidence(array $observation): bool
    {
        return (bool) ($observation['evidence_sufficient'] ?? false)
            && (
                ! empty($observation['interaction_map'])
                || filled(data_get($observation, 'dom.visible_text_excerpt'))
            );
    }

    protected function validImageDataUrl(mixed $image): ?string
    {
        if (! is_string($image) || ! preg_match('#^data:image/(?:png|jpeg|webp);base64,([A-Za-z0-9+/=\r\n]+)$#', $image, $match)) {
            return null;
        }

        $encoded = preg_replace('/\s+/', '', $match[1]) ?: '';

        if (strlen($encoded) > (int) ceil(WorkflowCopilotObservationService::MAX_SCREENSHOT_BYTES * 4 / 3) + 4) {
            return null;
        }

        $decoded = base64_decode($encoded, true);

        return is_string($decoded) && strlen($decoded) <= WorkflowCopilotObservationService::MAX_SCREENSHOT_BYTES
            ? $image
            : null;
    }

    protected function goalProgress(mixed $value): float|string|array
    {
        if (is_numeric($value)) {
            $progress = (float) $value;

            if ($progress > 1 && $progress <= 100) {
                $progress /= 100;
            }

            return round(max(0, min(1, $progress)), 3);
        }

        if (is_array($value)) {
            return (array) $this->observations->sanitizeForModel(array_slice($value, 0, 20));
        }

        return Str::limit(trim((string) $this->observations->sanitizeForModel($value)), 300, '') ?: 'unknown';
    }

    protected function safeGoal(string $goal): string
    {
        $goal = trim((string) $this->observations->sanitizeForModel($goal));

        return Str::limit($goal, 2000, '') ?: 'Kein zusaetzliches Ziel angegeben.';
    }

    protected function encode(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return Str::limit(is_string($encoded) ? $encoded : '{}', WorkflowCopilotObservationService::MAX_OBSERVATION_BYTES, '');
    }

    protected function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
