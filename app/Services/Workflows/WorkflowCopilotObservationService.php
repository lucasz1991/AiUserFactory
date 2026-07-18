<?php

namespace App\Services\Workflows;

use App\Models\WorkflowRun;
use App\Models\WorkflowRunArtifact;
use App\Models\WorkflowStepRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Throwable;

class WorkflowCopilotObservationService
{
    public const MAX_ELEMENTS = 80;

    public const MAX_SOURCE_ELEMENTS = 960;

    public const MAX_SCREENSHOT_BYTES = 4_194_304;

    public const MAX_SOURCE_SCREENSHOT_BYTES = 20_971_520;

    public const MAX_OBSERVATION_BYTES = 98_304;

    protected int $sensitiveFieldsRemoved = 0;

    protected bool $payloadTruncated = false;

    public function __construct(
        protected WorkflowDebugArtifactService $artifactService,
    ) {}

    /**
     * Build a model-safe observation. screenshot_data_url is intentionally an
     * internal transport value and must never be persisted in chat/events.
     */
    public function observe(WorkflowRun $run, ?WorkflowStepRun $stepRun = null): array
    {
        $this->sensitiveFieldsRemoved = 0;
        $this->payloadTruncated = false;

        $stepRun ??= $this->latestStepRun($run);
        $artifacts = $this->artifacts($run, $stepRun);
        $domArtifact = $this->latestSuccessfulArtifact($artifacts, ['dom', 'dom_snapshot']);
        $screenshotArtifact = $this->latestSuccessfulArtifact($artifacts, ['screenshot', 'screen']);
        $payloads = $this->resultPayloads($run, $stepRun, $artifacts);
        $interactionPayloads = $this->resultPayloads($run, $stepRun, $artifacts, $stepRun === null);

        $domFile = $domArtifact ? $this->artifactPath($domArtifact) : null;
        $domSnapshot = $domFile ? $this->readDomSnapshot($domFile, $domArtifact) : [];
        $candidates = [];

        foreach ($interactionPayloads as $payload) {
            $this->collectInteractionCandidates($payload, $candidates);
        }

        foreach ($domSnapshot['elements'] ?? [] as $element) {
            if (is_array($element)) {
                $candidates[] = $element;
            }
        }

        $interactionMap = $this->normalizeInteractionMap(
            $candidates,
            (string) ($domArtifact?->browser_window ?: $screenshotArtifact?->browser_window ?: 'main'),
        );
        $page = $this->pagePayload($payloads, $domArtifact, $screenshotArtifact, $domSnapshot);
        $screenshot = $this->screenshotPayload($payloads, $screenshotArtifact);
        $dom = $this->domPayload($payloads, $domArtifact, $domSnapshot);
        $page['state'] = $dom['ui_state'];
        $stateSignature = $this->firstScalar($payloads, [
            'state_signature',
            'stateSignature',
        ]);

        if (! is_string($stateSignature) || trim($stateSignature) === '') {
            $stateSignature = hash('sha256', json_encode([
                $page['url'] ?? null,
                $page['title'] ?? null,
                $dom['ui_state'] ?? null,
                array_column($interactionMap, 'element_ref'),
            ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
        }

        $capturedAt = now()->toIso8601String();
        $observation = [
            'workflow_run_id' => $run->getKey() ? (int) $run->getKey() : null,
            'workflow_step_run_id' => $stepRun?->getKey() ? (int) $stepRun->getKey() : null,
            'captured_at' => $capturedAt,
            'workflow_revision' => $run->workflow_revision !== null ? (int) $run->workflow_revision : null,
            'state_signature' => Str::limit(trim((string) $stateSignature), 128, ''),
            'page' => $page,
            'page_state' => $dom['ui_state'],
            'dom' => $dom,
            'browser_windows' => $this->browserWindows($artifacts),
            'interaction_map' => $interactionMap,
            'screenshot_artifact_id' => $screenshot['artifact_id'],
            'dom_artifact_id' => $domArtifact?->getKey() ? (int) $domArtifact->getKey() : null,
            'evidence_provenance' => [
                'workflow_run_id' => $run->getKey() ? (int) $run->getKey() : null,
                'workflow_step_run_id' => $stepRun?->getKey() ? (int) $stepRun->getKey() : null,
                'workflow_revision' => $run->workflow_revision !== null ? (int) $run->workflow_revision : null,
                'captured_at' => $capturedAt,
                'dom_artifact_id' => $domArtifact?->getKey() ? (int) $domArtifact->getKey() : null,
                'screenshot_artifact_id' => $screenshot['artifact_id'],
            ],
            'screenshot_relative_path' => $screenshot['relative_path'],
            'screenshot_url' => $screenshot['url'],
            'screenshot_data_url' => $screenshot['data_url'],
            'screenshot_changed' => $this->screenshotChanged($artifacts, $screenshotArtifact),
            'screenshot' => [
                'artifact_id' => $screenshot['artifact_id'],
                'mime_type' => $screenshot['mime_type'],
                'size_bytes' => $screenshot['size_bytes'],
                'width' => $screenshot['width'],
                'height' => $screenshot['height'],
                'available_for_vision' => $screenshot['data_url'] !== null,
            ],
            'evidence_sufficient' => $interactionMap !== []
                || filled($dom['visible_text_excerpt'] ?? null)
                || $screenshot['data_url'] !== null,
            'payload_truncated' => false,
            'sensitive_fields_removed' => 0,
        ];

        $observation = $this->limitObservation($observation);
        $observation['payload_truncated'] = $this->payloadTruncated;
        $observation['sensitive_fields_removed'] = $this->sensitiveFieldsRemoved;
        $observation = $this->limitObservation($observation);
        $observation['payload_truncated'] = $this->payloadTruncated;
        $observation['sensitive_fields_removed'] = $this->sensitiveFieldsRemoved;

        return $observation;
    }

    /**
     * Recursively remove secrets from arbitrary result/model payloads.
     */
    public function sanitizeForModel(mixed $value): mixed
    {
        return $this->sanitizeValue($value, 0);
    }

    protected function latestStepRun(WorkflowRun $run): ?WorkflowStepRun
    {
        if ($run->relationLoaded('stepRuns')) {
            return $run->stepRuns->last();
        }

        if (! $run->exists) {
            return null;
        }

        return $run->stepRuns()->latest('id')->first();
    }

    protected function artifacts(WorkflowRun $run, ?WorkflowStepRun $stepRun): Collection
    {
        $artifacts = $run->relationLoaded('artifacts')
            ? $run->artifacts
            : ($run->exists ? $run->artifacts()->latest('id')->limit(200)->get() : collect());

        if ($stepRun) {
            $stepRunId = (int) $stepRun->getKey();
            $artifacts = $artifacts->filter(
                fn (mixed $artifact): bool => $artifact instanceof WorkflowRunArtifact
                    && (int) $artifact->workflow_step_run_id === $stepRunId,
            );
        }

        if ($stepRun?->relationLoaded('artifacts')) {
            $artifacts = $artifacts->concat($stepRun->artifacts);
        }

        return $artifacts
            ->filter(fn (mixed $artifact): bool => $artifact instanceof WorkflowRunArtifact)
            ->unique(fn (WorkflowRunArtifact $artifact): string => $artifact->getKey()
                ? 'id:'.$artifact->getKey()
                : 'object:'.spl_object_id($artifact))
            ->sortBy(fn (WorkflowRunArtifact $artifact): string => sprintf(
                '%020d-%s',
                (int) ($artifact->getKey() ?? 0),
                (string) ($artifact->created_at?->format('U.u') ?? ''),
            ))
            ->values();
    }

    protected function latestSuccessfulArtifact(Collection $artifacts, array $types): ?WorkflowRunArtifact
    {
        return $artifacts
            ->filter(function (WorkflowRunArtifact $artifact) use ($types): bool {
                $type = Str::lower(trim((string) $artifact->artifact_type));
                $status = Str::lower(trim((string) $artifact->status));

                return in_array($type, $types, true)
                    && ($status === '' || in_array($status, ['success', 'completed', 'ok'], true));
            })
            ->last();
    }

    protected function resultPayloads(
        WorkflowRun $run,
        ?WorkflowStepRun $stepRun,
        Collection $artifacts,
        bool $includeRunResult = true,
    ): array {
        $payloads = [];

        $resultPayloads = [$stepRun?->result_json];
        if ($includeRunResult) {
            $resultPayloads[] = $run->result_json;
        }

        foreach ($resultPayloads as $payload) {
            if (is_array($payload)) {
                $payloads[] = $payload;
            }
        }

        foreach ($artifacts as $artifact) {
            if (is_array($artifact->metadata_json) && $artifact->metadata_json !== []) {
                $payloads[] = $artifact->metadata_json;
            }

            if (! in_array(Str::lower((string) $artifact->artifact_type), ['manifest', 'json', 'dom_manifest'], true)) {
                continue;
            }

            $path = $this->artifactPath($artifact);

            if (! $path || ! is_file($path) || filesize($path) > 1_048_576) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($path), true);

            if (is_array($decoded)) {
                $payloads[] = $decoded;
            }
        }

        return $payloads;
    }

    protected function artifactPath(WorkflowRunArtifact $artifact): ?string
    {
        try {
            return $this->artifactService->absolutePath($artifact);
        } catch (Throwable) {
            return null;
        }
    }

    protected function readDomSnapshot(string $path, WorkflowRunArtifact $artifact): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');

        if (! is_resource($handle)) {
            return [];
        }

        $html = (string) fread($handle, 2_097_153);
        fclose($handle);

        if (strlen($html) > 2_097_152) {
            $html = substr($html, 0, 2_097_152);
            $this->payloadTruncated = true;
        }

        $metadata = [];

        if (preg_match('/<!--\s*workflow-debug-metadata:\s*(\{.*?\})\s*-->/s', substr($html, 0, 8192), $match)) {
            $decoded = json_decode($match[1], true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        // Raw page HTML is attacker-controlled and cannot prove that an element
        // was visible in the captured viewport. Only browser-evaluated metadata
        // with an explicit visible=true flag may become model interaction data.
        $elements = collect($metadata['interaction_map'] ?? $metadata['interactionMap'] ?? [])
            ->filter(fn (mixed $element): bool => is_array($element)
                && $this->boolOrNull($element['visible'] ?? $element['is_visible'] ?? $element['isVisible'] ?? null) === true)
            ->take(self::MAX_SOURCE_ELEMENTS)
            ->values()
            ->all();

        return ['metadata' => $metadata, 'elements' => $elements];
    }

    protected function collectInteractionCandidates(mixed $value, array &$candidates, int $depth = 0): void
    {
        if ($depth > 7 || count($candidates) >= self::MAX_SOURCE_ELEMENTS) {
            $this->payloadTruncated = true;

            return;
        }

        if (is_string($value)) {
            $trimmed = ltrim($value);

            if (strlen($value) <= 1_048_576 && (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '['))) {
                $decoded = json_decode($value, true);

                if (is_array($decoded)) {
                    $this->collectInteractionCandidates($decoded, $candidates, $depth + 1);
                }
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            $normalizedKey = $this->normalizedKey((string) $key);

            if ($this->isSensitiveKey($normalizedKey)) {
                $this->sensitiveFieldsRemoved++;

                continue;
            }

            if (in_array($normalizedKey, [
                'interactionmap',
                'elements',
                'interactiveelements',
                'selectorsuggestions',
                'elementmap',
            ], true) && is_array($item)) {
                $items = array_is_list($item) ? $item : [$item];

                foreach ($items as $candidate) {
                    if (is_array($candidate)) {
                        $candidates[] = $candidate;
                    }
                }
            }

            if (is_array($item) || is_string($item)) {
                $this->collectInteractionCandidates($item, $candidates, $depth + 1);
            }
        }
    }

    protected function normalizeInteractionMap(array $candidates, string $defaultWindow): array
    {
        $normalized = [];

        $candidates = $this->prioritizeInteractionCandidates($candidates);

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $element = $this->normalizeElement($candidate, $defaultWindow);

            if ($element === null) {
                continue;
            }

            $ref = $element['element_ref'];

            if (! isset($normalized[$ref])) {
                $normalized[$ref] = $element;
            } else {
                $normalized[$ref] = $this->mergeElement($normalized[$ref], $element);
            }

            if (count($normalized) >= self::MAX_ELEMENTS) {
                $this->payloadTruncated = count($candidates) > self::MAX_ELEMENTS;

                break;
            }
        }

        return collect(array_values($normalized))
            ->values()
            ->map(function (array $element, int $index): array {
                $element['element_number'] = $index + 1;

                return $element;
            })
            ->all();
    }

    protected function prioritizeInteractionCandidates(array $candidates): array
    {
        $ranked = [];

        foreach ($candidates as $index => $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $ranked[] = [
                'candidate' => $candidate,
                'index' => (int) $index,
                'priority' => $this->interactionCandidatePriority($candidate),
            ];
        }

        usort($ranked, static function (array $left, array $right): int {
            $priority = ((int) $right['priority']) <=> ((int) $left['priority']);

            return $priority !== 0 ? $priority : ((int) $left['index']) <=> ((int) $right['index']);
        });

        return array_column($ranked, 'candidate');
    }

    protected function interactionCandidatePriority(array $candidate): int
    {
        if ($this->boolOrNull($candidate['visible'] ?? $candidate['is_visible'] ?? $candidate['isVisible'] ?? null) === false) {
            return -1000;
        }

        $haystack = Str::lower(implode(' ', array_filter([
            $candidate['text'] ?? null,
            $candidate['label'] ?? null,
            $candidate['aria'] ?? null,
            $candidate['aria_label'] ?? null,
            $candidate['ariaLabel'] ?? null,
            $candidate['name'] ?? null,
            $candidate['selector'] ?? null,
        ], static fn (mixed $value): bool => is_scalar($value))));
        $tag = Str::lower(trim((string) ($candidate['tag'] ?? $candidate['tag_name'] ?? $candidate['tagName'] ?? '')));
        $role = Str::lower(trim((string) ($candidate['role'] ?? '')));
        $priority = in_array($tag, ['button', 'a', 'input'], true) || $role === 'button' ? 20 : 0;

        if (preg_match('/(?:alle\s+ablehnen|reject\s+all|decline\s+all|refuse\s+all|nur\s+(?:notwendige|erforderliche)|only\s+(?:necessary|required)|\bablehnen\b|\breject\b|\bdecline\b)/u', $haystack)) {
            return $priority + 1000;
        }

        if (preg_match('/(?:alle\s+akzeptieren|accept\s+all|allow\s+all|\bakzeptieren\b|\baccept\b|\ballow\b)/u', $haystack)) {
            return $priority + 800;
        }

        if (preg_match('/(?:consent|cookie|einwilligung|datenschutz|privacy)/u', $haystack)) {
            return $priority + 500;
        }

        return $priority;
    }

    protected function normalizeElement(array $candidate, string $defaultWindow): ?array
    {
        $tag = $this->cleanIdentifier($candidate['tag'] ?? $candidate['tag_name'] ?? $candidate['tagName'] ?? '', 30);
        $role = $this->cleanIdentifier($candidate['role'] ?? '', 60);
        $type = $this->cleanIdentifier($candidate['type'] ?? $candidate['input_type'] ?? $candidate['inputType'] ?? '', 40);
        $aria = $this->safeString($candidate['aria'] ?? $candidate['aria_label'] ?? $candidate['ariaLabel'] ?? '', 180);
        $name = $this->safeString($candidate['name'] ?? '', 160);
        $placeholder = $this->safeString($candidate['placeholder'] ?? '', 180);
        $title = $this->safeString($candidate['title'] ?? $candidate['element_title'] ?? $candidate['elementTitle'] ?? '', 180);
        $label = $this->safeString($candidate['label'] ?? $candidate['field_label'] ?? $candidate['fieldLabel'] ?? '', 180);
        $visible = $this->boolOrNull($candidate['visible'] ?? $candidate['is_visible'] ?? $candidate['isVisible'] ?? null);

        if ($visible === false) {
            $this->sensitiveFieldsRemoved++;

            return null;
        }

        $isInput = in_array($tag, ['input', 'textarea', 'select', 'option'], true);
        $text = $isInput
            ? $this->redactedInputValue($candidate)
            : $this->safeString($candidate['text'] ?? $candidate['label'] ?? $candidate['visible_text'] ?? $candidate['visibleText'] ?? '', 240);
        $selectors = $this->selectorCandidates($candidate, $tag, $role, $type, $aria, $title, $placeholder, $name, $label, $text);
        $frame = $this->safeString($candidate['frame'] ?? $candidate['frame_url'] ?? $candidate['frameUrl'] ?? '', 240);
        $window = $this->safeString($candidate['window'] ?? $candidate['browser_window'] ?? $candidate['browserWindow'] ?? $defaultWindow, 120) ?: 'main';

        if ($tag === '' && $role === '' && $selectors === [] && $text === '' && $aria === '' && $title === '' && $placeholder === '') {
            return null;
        }

        $explicitRef = trim((string) ($candidate['element_ref'] ?? $candidate['elementRef'] ?? $candidate['ref'] ?? ''));
        $semanticLabel = collect([$title, $aria, $placeholder, $label, $name, $text])
            ->first(fn (string $value): bool => $value !== '') ?? '';
        $stableIdentity = implode('|', array_filter([
            $role,
            $type,
            $title,
            $aria,
            $placeholder,
            $label,
            $name,
        ]));
        $stableIdentity = $stableIdentity !== '' ? $stableIdentity : ($selectors[0] ?? $text);
        $elementRef = preg_match('/^(?:el|element|node)[_.:-][A-Za-z0-9_.:-]{1,70}$/', $explicitRef)
            ? $explicitRef
            : 'el_'.substr(hash('sha256', implode('|', [
                $window,
                $frame,
                $tag,
                $stableIdentity,
            ])), 0, 16);

        return [
            'element_ref' => $elementRef,
            'tag' => $tag ?: null,
            'role' => $role ?: null,
            'type' => $type ?: null,
            'text' => $text ?: null,
            'aria' => $aria ?: null,
            'name' => $name ?: null,
            'placeholder' => $placeholder ?: null,
            'title' => $title ?: null,
            'label' => $label ?: null,
            'semantic_label' => $semanticLabel ?: null,
            'visible' => $visible,
            'enabled' => $this->boolOrNull($candidate['enabled'] ?? $candidate['is_enabled'] ?? $candidate['isEnabled'] ?? null),
            'focused' => $this->boolOrNull($candidate['focused'] ?? $candidate['is_focused'] ?? $candidate['isFocused'] ?? null),
            'selected' => $this->boolOrNull($candidate['selected'] ?? $candidate['checked'] ?? $candidate['is_selected'] ?? null),
            'bounding_box' => $this->normalizeBoundingBox($candidate['bounding_box'] ?? $candidate['boundingBox'] ?? $candidate['rect'] ?? $candidate['box'] ?? null),
            'selector_candidates' => $selectors,
            'frame' => $frame ?: null,
            'window' => $window,
        ];
    }

    protected function redactedInputValue(array $candidate): string
    {
        foreach (['text', 'value', 'input_value', 'inputValue'] as $key) {
            if (array_key_exists($key, $candidate) && filled($candidate[$key])) {
                $this->sensitiveFieldsRemoved++;

                return '[REDACTED]';
            }
        }

        return '';
    }

    protected function selectorCandidates(
        array $candidate,
        string $tag,
        string $role,
        string $type,
        string $aria,
        string $title,
        string $placeholder,
        string $name,
        string $label,
        string $text,
    ): array {
        $ranked = [];
        $sequence = 0;
        $add = function (mixed $selector, ?int $priority = null) use (&$ranked, &$sequence): void {
            $selector = preg_replace('/\s*\/\*.*?\*\/\s*/s', '', trim((string) $selector)) ?: '';

            if (! $this->safeSelector($selector)) {
                return;
            }

            $ranked[] = [
                'selector' => Str::limit($selector, 300, ''),
                'priority' => $priority ?? $this->selectorStabilityPriority($selector),
                'sequence' => $sequence++,
            ];
        };

        $tagSelector = $tag ?: '*';

        if ($title !== '') {
            $add($tagSelector.'[title="'.$this->escapeCssAttribute($title).'"]', 1000);
        }

        if ($aria !== '') {
            $add($tagSelector.'[aria-label="'.$this->escapeCssAttribute($aria).'"]', 990);
        }

        if ($placeholder !== '') {
            $add($tagSelector.'[placeholder="'.$this->escapeCssAttribute($placeholder).'"]', 980);
        }

        foreach (['data-testid', 'data_testid', 'dataTestId', 'data-test', 'data-cy', 'data-qa'] as $attribute) {
            if (filled($candidate[$attribute] ?? null)) {
                $cssAttribute = str_replace('_', '-', Str::snake($attribute, '-'));
                $add($tagSelector.'['.$cssAttribute.'="'.$this->escapeCssAttribute((string) $candidate[$attribute]).'"]', 970);
            }
        }

        if ($name !== '') {
            $add($tagSelector.'[name="'.$this->escapeCssAttribute($name).'"]', 900);
        }

        $visibleLabel = $label !== '' ? $label : ($text !== '[REDACTED]' ? $text : '');

        if ($visibleLabel !== '' && in_array($tag, ['a', 'button', 'label', 'option'], true)) {
            $add($tagSelector.':has-text("'.$this->escapeSelectorText($visibleLabel).'")', 880);
        }

        if ($role !== '' && $type !== '') {
            $add($tagSelector.'[role="'.$this->escapeCssAttribute($role).'"][type="'.$this->escapeCssAttribute($type).'"]', 840);
        } elseif ($role !== '') {
            $add($tagSelector.'[role="'.$this->escapeCssAttribute($role).'"]', 820);
        } elseif ($type !== '') {
            $add($tagSelector.'[type="'.$this->escapeCssAttribute($type).'"]', 800);
        }

        foreach (['selector', 'css_selector', 'cssSelector'] as $key) {
            if (filled($candidate[$key] ?? null)) {
                $add($candidate[$key]);
            }
        }

        foreach (['selector_candidates', 'selectorCandidates', 'selectors'] as $key) {
            if (is_array($candidate[$key] ?? null)) {
                foreach ($candidate[$key] as $selector) {
                    $add($selector);
                }
            }
        }

        if (filled($candidate['id'] ?? null)) {
            $add('[id="'.$this->escapeCssAttribute((string) $candidate['id']).'"]', 100);
        }

        return collect($ranked)
            ->sortBy([
                ['priority', 'desc'],
                ['sequence', 'asc'],
            ])
            ->unique('selector')
            ->take(8)
            ->pluck('selector')
            ->values()
            ->all();
    }

    protected function selectorStabilityPriority(string $selector): int
    {
        $lower = Str::lower($selector);

        return match (true) {
            str_contains($lower, '[title=') => 1000,
            str_contains($lower, '[aria-label=') => 990,
            str_contains($lower, '[placeholder=') => 980,
            preg_match('/\[data-(?:testid|test|cy|qa)=/i', $selector) === 1 => 970,
            str_contains($lower, '[name=') => 900,
            str_contains($lower, ':has-text('), str_starts_with($lower, 'text=') => 880,
            str_contains($lower, '[role='), str_contains($lower, '[type=') => 820,
            preg_match('/(?:^|[\s>+~,])#[A-Za-z0-9_-]+/', $selector) === 1,
            str_contains($lower, '[id=') => 100,
            preg_match('/(?:nth-child|nth-of-type)/i', $selector) === 1 => 50,
            default => 700,
        };
    }

    protected function escapeSelectorText(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    protected function safeSelector(string $selector): bool
    {
        if ($selector === '' || str_contains($selector, '<') || preg_match('/[\r\n]/', $selector)) {
            return false;
        }

        if (preg_match('/[A-Za-z0-9_-]{24,}/', $selector)) {
            $this->sensitiveFieldsRemoved++;

            return false;
        }

        if ($this->containsSensitiveString($selector)) {
            $this->sensitiveFieldsRemoved++;

            return false;
        }

        return true;
    }

    protected function mergeElement(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if ($key === 'selector_candidates') {
                $left[$key] = array_values(array_unique(array_slice([
                    ...($left[$key] ?? []),
                    ...($value ?? []),
                ], 0, 8)));
            } elseif (($left[$key] ?? null) === null && $value !== null) {
                $left[$key] = $value;
            }
        }

        return $left;
    }

    protected function pagePayload(array $payloads, ?WorkflowRunArtifact $domArtifact, ?WorkflowRunArtifact $screenshotArtifact, array $domSnapshot): array
    {
        $metadata = is_array($domSnapshot['metadata'] ?? null) ? $domSnapshot['metadata'] : [];
        $url = $domArtifact?->current_url
            ?: $screenshotArtifact?->current_url
            ?: ($metadata['url'] ?? $this->firstScalar($payloads, ['current_url', 'currentUrl', 'final_url', 'finalUrl', 'url']));
        $title = $domArtifact?->title
            ?: $screenshotArtifact?->title
            ?: ($metadata['title'] ?? $this->firstScalar($payloads, ['current_title', 'currentTitle', 'title']));
        $viewport = $this->firstArray($payloads, ['viewport', 'viewport_size', 'viewportSize']);

        return [
            'url' => is_string($url) ? $this->safeUrl($url) : null,
            'title' => $this->safeString($title, 300) ?: null,
            'window' => $this->safeString($domArtifact?->browser_window ?: $screenshotArtifact?->browser_window ?: 'main', 120) ?: 'main',
            'viewport' => $this->normalizeViewport($viewport),
        ];
    }

    protected function domPayload(array $payloads, ?WorkflowRunArtifact $artifact, array $snapshot): array
    {
        $metadata = array_filter([
            is_array($artifact?->metadata_json) ? $artifact->metadata_json : [],
            is_array($snapshot['metadata'] ?? null) ? $snapshot['metadata'] : [],
        ]);
        $metadata = array_merge(...($metadata ?: [[]]));
        $all = [$metadata, ...$payloads];

        return [
            'ui_state' => $this->safeString($this->firstScalar($all, ['ui_state', 'uiState']), 120) ?: 'unknown_browser_state',
            'ready_state' => $this->safeString($this->firstScalar($all, ['ready_state', 'readyState']), 60) ?: null,
            'visible_text_excerpt' => $this->safeString($this->firstScalar($all, ['visible_text_excerpt', 'visibleTextExcerpt']), 1600) ?: null,
            'source_artifact_id' => $artifact?->getKey() ? (int) $artifact->getKey() : null,
        ];
    }

    protected function screenshotPayload(array $payloads, ?WorkflowRunArtifact $artifact): array
    {
        $relativePath = $artifact?->storage_path
            ?: $this->firstScalar($payloads, [
                'screenshot_relative_path',
                'screenshotRelativePath',
                'live_preview_relative_path',
                'livePreviewRelativePath',
            ]);
        $relativePath = is_string($relativePath) ? $this->safeRelativePath($relativePath) : null;
        $url = null;
        $absolutePath = null;

        if ($artifact) {
            $absolutePath = $this->artifactPath($artifact);

            try {
                $candidateUrl = $this->artifactService->artifactUrl($artifact);
                $url = $candidateUrl !== '#' ? $candidateUrl : null;
            } catch (Throwable) {
                $url = null;
            }
        }

        $url ??= $this->firstScalar($payloads, ['screenshot_url', 'screenshotUrl']);
        $url = is_string($url) ? $this->safeUrl($url) : null;

        if (! $absolutePath && $relativePath) {
            $absolutePath = $this->safeLocalScreenshotPath($relativePath);
        }

        $data = $absolutePath ? $this->localImageDataUrl($absolutePath) : [null, null, null];

        if ($data[0] === null) {
            $inline = $this->firstScalar($payloads, ['screenshot_data_url', 'screenshotDataUrl']);
            $data = is_string($inline) ? $this->validatedInlineImage($inline) : [null, null, null];
        }

        return [
            'artifact_id' => $artifact?->getKey() ? (int) $artifact->getKey() : null,
            'relative_path' => $relativePath,
            'url' => $url,
            'data_url' => $data[0],
            'mime_type' => $data[1],
            'size_bytes' => $data[2],
            'width' => $data[3] ?? null,
            'height' => $data[4] ?? null,
        ];
    }

    protected function browserWindows(Collection $artifacts): array
    {
        return $artifacts
            ->filter(fn (WorkflowRunArtifact $artifact): bool => filled($artifact->browser_window))
            ->groupBy(fn (WorkflowRunArtifact $artifact): string => (string) $artifact->browser_window)
            ->map(function (Collection $windowArtifacts, string $name): array {
                $latest = $windowArtifacts->last();

                return [
                    'name' => $this->safeString($name, 120),
                    'url' => $latest?->current_url ? $this->safeUrl((string) $latest->current_url) : null,
                    'title' => $this->safeString($latest?->title, 300) ?: null,
                ];
            })
            ->values()
            ->take(20)
            ->all();
    }

    protected function screenshotChanged(Collection $artifacts, ?WorkflowRunArtifact $latest): bool
    {
        if (! $latest) {
            return false;
        }

        $screenshots = $artifacts
            ->filter(function (WorkflowRunArtifact $artifact): bool {
                $type = Str::lower(trim((string) $artifact->artifact_type));
                $status = Str::lower(trim((string) $artifact->status));

                return in_array($type, ['screenshot', 'screen'], true)
                    && ($status === '' || in_array($status, ['success', 'completed', 'ok'], true));
            })
            ->take(-2)
            ->values();

        if ($screenshots->count() < 2) {
            return true;
        }

        $previousPath = $this->artifactPath($screenshots->first());
        $latestPath = $this->artifactPath($screenshots->last());

        if (! $previousPath || ! $latestPath || ! is_file($previousPath) || ! is_file($latestPath)) {
            return true;
        }

        return hash_file('sha256', $previousPath) !== hash_file('sha256', $latestPath);
    }

    protected function safeLocalScreenshotPath(string $relativePath): ?string
    {
        if ($relativePath === '' || str_contains($relativePath, '..') || preg_match('/^[A-Za-z]:|^\//', $relativePath)) {
            return null;
        }

        $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);

        foreach ([storage_path('app/public/'.$relativePath), storage_path('app/'.$relativePath), public_path($relativePath)] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function localImageDataUrl(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [null, null, null, null, null];
        }

        $size = filesize($path);

        if (! is_int($size) || $size <= 0 || $size > self::MAX_SOURCE_SCREENSHOT_BYTES) {
            $this->payloadTruncated = $size > self::MAX_SOURCE_SCREENSHOT_BYTES;

            return [null, null, $size ?: null, null, null];
        }

        $mime = function_exists('mime_content_type') ? mime_content_type($path) : null;
        $mime = is_string($mime) ? Str::lower($mime) : '';

        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = match ($extension) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                default => '',
            };
        }

        if ($mime === '') {
            return [null, null, $size, null, null];
        }

        $dimensions = @getimagesize($path);
        $needsResize = $size > self::MAX_SCREENSHOT_BYTES
            || (is_array($dimensions) && ((int) ($dimensions[0] ?? 0) > 1920 || (int) ($dimensions[1] ?? 0) > 1200));

        if ($needsResize) {
            $resized = $this->resizedImageDataUrl($path);

            if ($resized[0] !== null) {
                return $resized;
            }

            if ($size > self::MAX_SCREENSHOT_BYTES) {
                $this->payloadTruncated = true;

                return [null, $mime, $size, $dimensions[0] ?? null, $dimensions[1] ?? null];
            }
        }

        $content = file_get_contents($path);

        return is_string($content)
            ? ['data:'.$mime.';base64,'.base64_encode($content), $mime, $size, $dimensions[0] ?? null, $dimensions[1] ?? null]
            : [null, $mime, $size, $dimensions[0] ?? null, $dimensions[1] ?? null];
    }

    protected function resizedImageDataUrl(string $path): array
    {
        try {
            $image = ImageManager::gd()->read($path)->scaleDown(width: 1920, height: 1200);

            foreach ([82, 68] as $quality) {
                $encoded = (string) $image->toWebp(quality: $quality);
                $size = strlen($encoded);

                if ($size > 0 && $size <= self::MAX_SCREENSHOT_BYTES) {
                    $dimensions = @getimagesizefromstring($encoded);

                    return [
                        'data:image/webp;base64,'.base64_encode($encoded),
                        'image/webp',
                        $size,
                        $dimensions[0] ?? null,
                        $dimensions[1] ?? null,
                    ];
                }
            }
        } catch (Throwable) {
            // The unmodified image remains usable when it is already below the hard byte limit.
        }

        return [null, null, null, null, null];
    }

    protected function validatedInlineImage(string $dataUrl): array
    {
        if (! preg_match('#^data:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=\r\n]+)$#', $dataUrl, $match)) {
            return [null, null, null, null, null];
        }

        $binary = base64_decode($match[2], true);

        if (! is_string($binary) || strlen($binary) === 0 || strlen($binary) > self::MAX_SCREENSHOT_BYTES) {
            $this->payloadTruncated = is_string($binary) && strlen($binary) > self::MAX_SCREENSHOT_BYTES;

            return [null, $match[1], is_string($binary) ? strlen($binary) : null, null, null];
        }

        $dimensions = @getimagesizefromstring($binary);

        return [
            'data:'.$match[1].';base64,'.base64_encode($binary),
            $match[1],
            strlen($binary),
            $dimensions[0] ?? null,
            $dimensions[1] ?? null,
        ];
    }

    protected function limitObservation(array $observation): array
    {
        $image = $observation['screenshot_data_url'] ?? null;
        $observation['screenshot_data_url'] = null;

        while ($this->encodedSize($observation) > self::MAX_OBSERVATION_BYTES && count($observation['interaction_map'] ?? []) > 10) {
            array_pop($observation['interaction_map']);
            $this->payloadTruncated = true;
        }

        if ($this->encodedSize($observation) > self::MAX_OBSERVATION_BYTES) {
            $observation['dom']['visible_text_excerpt'] = Str::limit((string) ($observation['dom']['visible_text_excerpt'] ?? ''), 500, '');
            $this->payloadTruncated = true;
        }

        $observation['screenshot_data_url'] = $image;

        return $observation;
    }

    protected function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($depth > 8) {
            $this->payloadTruncated = true;

            return '[TRUNCATED]';
        }

        if (is_string($value)) {
            return $this->safeString($value, 1600);
        }

        if (! is_array($value)) {
            return is_scalar($value) || $value === null ? $value : null;
        }

        $safe = [];
        $count = 0;

        foreach ($value as $key => $item) {
            if ($count >= 100) {
                $this->payloadTruncated = true;

                break;
            }

            if ($this->isSensitiveKey($this->normalizedKey((string) $key))) {
                $safe[$key] = '[REDACTED]';
                $this->sensitiveFieldsRemoved++;
            } else {
                $safe[$key] = $this->sanitizeValue($item, $depth + 1);
            }

            $count++;
        }

        return $safe;
    }

    protected function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match('/(?:password|passwd|pwd|secret|token|authorization|cookie|sessionstorage|localstorage|storagestate|sessionid|phpsessid|apikey|accesskey|credential|browserws|wsendpoint|websocket|outerhtml|innerhtml|fullhtml|htmlsource|rawhtml|(?:input|form|field)value)/', $key)
            || in_array($key, ['html', 'cookies', 'session', 'signature', 'input', 'value'], true);
    }

    protected function safeString(mixed $value, int $limit): string
    {
        if (! is_scalar($value) && $value !== null) {
            return '';
        }

        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/<\/?(?:html|body|script|style|input|button|form|div|span)\b/i', $value)) {
            $this->sensitiveFieldsRemoved++;

            return '[HTML REDACTED]';
        }

        $replacements = [
            '#\b(?:wss?|cdp)://[^\s"\']+#i' => '[WEBSOCKET REDACTED]',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i' => 'Bearer [REDACTED]',
            '/\b(password|passwd|pwd|secret|token|cookie|authorization|signature|credential|session(?:_?id)?|api[_-]?key)\s*[:=]\s*[^\s,;]+/i' => '$1=[REDACTED]',
            '/\beyJ[A-Za-z0-9_-]{2,}\.[A-Za-z0-9_-]{3,}\.[A-Za-z0-9_-]{3,}\b/' => '[TOKEN REDACTED]',
            '/\b[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/' => '[TOKEN REDACTED]',
            '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i' => '[EMAIL REDACTED]',
            '/(?<!\d)(?:\+?\d[\s().-]*){8,}(?!\d)/' => '[PHONE REDACTED]',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $updated = preg_replace($pattern, $replacement, $value, -1, $count);

            if (is_string($updated)) {
                $value = $updated;
                $this->sensitiveFieldsRemoved += $count;
            }
        }

        if (mb_strlen($value) > $limit) {
            $this->payloadTruncated = true;
        }

        return Str::limit($value, $limit, '');
    }

    protected function safeUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || preg_match('#^(?:wss?|cdp)://#i', $url)) {
            if ($url !== '') {
                $this->sensitiveFieldsRemoved++;
            }

            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            [$relativePath, $relativeQuery] = array_pad(explode('?', $url, 2), 2, null);
            $safeRelative = $this->safeUrlPath($relativePath);

            if (is_string($relativeQuery) && $relativeQuery !== '') {
                parse_str($relativeQuery, $query);
                $redacted = [];

                foreach (array_keys($query) as $key) {
                    $redacted[(string) $key] = '[REDACTED]';
                    $this->sensitiveFieldsRemoved++;
                }

                if ($redacted !== []) {
                    $safeRelative .= '?'.http_build_query($redacted);
                }
            }

            return Str::limit($safeRelative, 2048, '') ?: null;
        }

        $safe = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $safe .= ':'.(int) $parts['port'];
        }

        $safe .= $this->safeUrlPath((string) ($parts['path'] ?? ''));

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            $redacted = [];

            foreach (array_keys($query) as $key) {
                $redacted[(string) $key] = '[REDACTED]';
                $this->sensitiveFieldsRemoved++;
            }

            if ($redacted !== []) {
                $safe .= '?'.http_build_query($redacted);
            }
        }

        return Str::limit($safe, 2048, '');
    }

    protected function safeRelativePath(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '' || str_contains($path, '..') || preg_match('/^[A-Za-z]:|^\//', $path)) {
            $this->sensitiveFieldsRemoved++;

            return null;
        }

        return Str::limit($path, 500, '');
    }

    protected function safeUrlPath(string $path): string
    {
        $safe = preg_replace('/[A-Za-z0-9_-]{24,}/', '[ID-REDACTED]', $path, -1, $pathRedactions) ?: $path;
        $safe = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[EMAIL-REDACTED]', $safe, -1, $emailRedactions) ?: $safe;
        $this->sensitiveFieldsRemoved += $pathRedactions + $emailRedactions;

        return $safe;
    }

    protected function containsSensitiveString(string $value): bool
    {
        return (bool) preg_match('/(?:password|passwd|secret|token|authorization|cookie|signature|credential|session(?:_?id)?|api[_-]?key|wss?:\/\/|Bearer\s+|[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $value);
    }

    protected function firstScalar(array $payloads, array $keys, int $depth = 0): mixed
    {
        if ($depth > 7) {
            return null;
        }

        $normalizedKeys = array_map(fn (string $key): string => $this->normalizedKey($key), $keys);

        foreach ($payloads as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            foreach ($payload as $key => $value) {
                if ($this->isSensitiveKey($this->normalizedKey((string) $key))) {
                    continue;
                }

                if (in_array($this->normalizedKey((string) $key), $normalizedKeys, true) && is_scalar($value)) {
                    return $value;
                }
            }
        }

        foreach ($payloads as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            foreach ($payload as $key => $value) {
                if (is_array($value) && ! $this->isSensitiveKey($this->normalizedKey((string) $key))) {
                    $found = $this->firstScalar([$value], $keys, $depth + 1);

                    if ($found !== null && $found !== '') {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    protected function firstArray(array $payloads, array $keys, int $depth = 0): ?array
    {
        if ($depth > 6) {
            return null;
        }

        $normalizedKeys = array_map(fn (string $key): string => $this->normalizedKey($key), $keys);

        foreach ($payloads as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            foreach ($payload as $key => $value) {
                if (in_array($this->normalizedKey((string) $key), $normalizedKeys, true) && is_array($value)) {
                    return $value;
                }
            }
        }

        foreach ($payloads as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            foreach ($payload as $key => $value) {
                if (is_array($value) && ! $this->isSensitiveKey($this->normalizedKey((string) $key))) {
                    $found = $this->firstArray([$value], $keys, $depth + 1);

                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    protected function normalizeBoundingBox(mixed $box): ?array
    {
        if (! is_array($box)) {
            return null;
        }

        $x = $box['x'] ?? $box['left'] ?? ($box[0] ?? null);
        $y = $box['y'] ?? $box['top'] ?? ($box[1] ?? null);
        $width = $box['width'] ?? ($box[2] ?? null);
        $height = $box['height'] ?? ($box[3] ?? null);

        if (! is_numeric($x) || ! is_numeric($y) || ! is_numeric($width) || ! is_numeric($height)) {
            return null;
        }

        return [
            'x' => round(max(-100000, min(100000, (float) $x)), 2),
            'y' => round(max(-100000, min(100000, (float) $y)), 2),
            'width' => round(max(0, min(100000, (float) $width)), 2),
            'height' => round(max(0, min(100000, (float) $height)), 2),
        ];
    }

    protected function decodeBoundingBox(string $value): ?array
    {
        $decoded = json_decode($value, true);

        return $this->normalizeBoundingBox($decoded);
    }

    protected function normalizeViewport(?array $viewport): ?array
    {
        if (! $viewport) {
            return null;
        }

        $width = $viewport['width'] ?? $viewport['w'] ?? null;
        $height = $viewport['height'] ?? $viewport['h'] ?? null;

        if (! is_numeric($width) || ! is_numeric($height)) {
            return null;
        }

        return [
            'width' => max(1, min(20000, (int) $width)),
            'height' => max(1, min(20000, (int) $height)),
            'device_scale_factor' => is_numeric($viewport['device_scale_factor'] ?? $viewport['deviceScaleFactor'] ?? null)
                ? max(0.1, min(10, (float) ($viewport['device_scale_factor'] ?? $viewport['deviceScaleFactor'])))
                : null,
            'scroll_x' => is_numeric($viewport['scroll_x'] ?? $viewport['scrollX'] ?? null)
                ? (float) ($viewport['scroll_x'] ?? $viewport['scrollX'])
                : 0.0,
            'scroll_y' => is_numeric($viewport['scroll_y'] ?? $viewport['scrollY'] ?? null)
                ? (float) ($viewport['scroll_y'] ?? $viewport['scrollY'])
                : 0.0,
        ];
    }

    protected function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return match (Str::lower(trim($value))) {
                'true', '1', 'yes', 'enabled', 'visible', 'selected', 'checked' => true,
                'false', '0', 'no', 'disabled', 'hidden', 'unselected', 'unchecked' => false,
                default => null,
            };
        }

        return null;
    }

    protected function cleanIdentifier(mixed $value, int $limit): string
    {
        return Str::of((string) $value)
            ->lower()
            ->replaceMatches('/[^a-z0-9_.:-]+/', '')
            ->substr(0, $limit)
            ->toString();
    }

    protected function normalizedKey(string $key): string
    {
        return Str::lower(preg_replace('/[^a-z0-9]+/i', '', $key) ?: '');
    }

    protected function escapeCssAttribute(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    protected function encodedSize(array $value): int
    {
        return strlen(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
    }
}
