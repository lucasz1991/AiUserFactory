<?php

namespace App\Services\Workflows;

use Illuminate\Support\Str;

/**
 * Deterministische Selektor-Diagnose fuer den Workflow-Copiloten.
 *
 * Hintergrund (Session 24, Workflow 15): Bei einem Selector-Timeout existiert
 * das Zielelement nicht in der Beobachtung, daher gibt es keine Vision-Evidenz
 * und der Modell-Planer durfte Selektoren nie reparieren. Dieser Service
 * klassifiziert Task-Fehler rein string-/statusbasiert und leitet aus der
 * eigenen DOM-Beobachtung deterministisch einen eindeutigen, stabilen
 * Ersatz-Selektor ab (Evidenzklasse `selector_probe`). Er erzeugt keinerlei
 * Modell-Aufrufe und veraendert selbst keine Workflows.
 */
class WorkflowSelectorProbeService
{
    public const FAILURE_SELECTOR_TIMEOUT = 'selector_timeout';

    public const FAILURE_SELECTOR_NOT_FOUND = 'selector_not_found';

    public const FAILURE_NAVIGATION = 'navigation';

    public const FAILURE_CONSENT = 'consent';

    public const FAILURE_NETWORK = 'network';

    public const FAILURE_UNKNOWN = 'unknown';

    /**
     * Nur Kandidaten mit mindestens dieser Stabilitaet (Attribut-, Text- oder
     * Ueberschriften-basierte Selektoren) duerfen eine deterministische Probe
     * ausloesen. Rohe IDs, Klassen und nth-child-Pfade bleiben aussen vor.
     */
    private const MIN_CANDIDATE_STABILITY = 800;

    /**
     * Rollen-Gruppen: Welche beobachteten Element-Tags zur aus dem bisherigen
     * Selector abgeleiteten Elementrolle passen.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_TAG_GROUPS = [
        'a' => ['a'],
        'button' => ['button'],
        'input' => ['input', 'textarea', 'select'],
        'textarea' => ['input', 'textarea'],
        'select' => ['select'],
    ];

    /**
     * Klassifiziert einen Task-Fehler deterministisch aus Outcome und
     * Statusmeldung. Ueberbreite Treffer bei consent/network/navigation sind
     * bewusst: Sie verhindern nur eine Selektor-Probe, loesen aber nie eine aus.
     *
     * @param  array<string, mixed>  $checkpoint
     */
    public function classifyFailure(array $checkpoint): string
    {
        $outcome = Str::lower(trim((string) ($checkpoint['outcome'] ?? $checkpoint['status'] ?? '')));
        $message = Str::lower(trim(implode(' ', array_filter([
            data_get($checkpoint, 'result.statusMessage'),
            data_get($checkpoint, 'result.status_message'),
            data_get($checkpoint, 'result.error'),
        ], static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== ''))));

        if (preg_match('/(?:consent|einwilligung|cookie)/u', $message) === 1) {
            return self::FAILURE_CONSENT;
        }

        if (preg_match('/(?:net::err_|err_name_not_resolved|err_connection|err_internet|\bdns\b|\bssl\b|\bproxy\b|connection\s+(?:refused|reset|closed)|netzwerkfehler|network\s+error)/u', $message) === 1) {
            return self::FAILURE_NETWORK;
        }

        if (preg_match('/(?:navigation|navigieren|seite\s+konnte\s+nicht\s+geladen|page\s+load|\bgoto\b)/u', $message) === 1) {
            return self::FAILURE_NAVIGATION;
        }

        $mentionsSelectorTarget = preg_match('/(?:selector|selektor|\bziel\b|\bziele[sn]?\b|element)/u', $message) === 1;

        if ($mentionsSelectorTarget
            && ($outcome === 'timeout'
                || preg_match('/(?:timeout|timed\s+out|zeitueberschreitung)/u', $message) === 1)) {
            return self::FAILURE_SELECTOR_TIMEOUT;
        }

        if (preg_match('/(?:kein\s+klickbares\s+ziel|keines\s+der\s+gefundenen\s+ziele|kein\s+element\s+gefunden|kein\s+passendes\s+input-feld|element\s+nicht\s+gefunden|nicht\s+gefunden|not\s+found|no\s+element|no\s+such\s+element|failed\s+to\s+find)/u', $message) === 1) {
            return self::FAILURE_SELECTOR_NOT_FOUND;
        }

        return self::FAILURE_UNKNOWN;
    }

    /**
     * Leitet aus der DOM-Beobachtung einen eindeutigen besten Ersatz-Selektor
     * fuer den bisherigen Task-Selector ab. Liefert [] sobald irgendetwas nicht
     * eindeutig ist: keine ableitbare Elementrolle, keine rollen-gleichen
     * sichtbaren Elemente, keine ausreichend stabilen Kandidaten oder ein
     * Stabilitaets-Gleichstand an der Spitze.
     *
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>  $observation
     * @param  list<string>  $rejectedSelectors
     * @return array<string, mixed>
     */
    public function bestCandidate(array $task, array $observation, array $rejectedSelectors = []): array
    {
        $previousSelector = trim((string) ($task['selector'] ?? $task['element_selector'] ?? ''));

        if ($previousSelector === '') {
            return [];
        }

        $expectedTags = $this->expectedTagsForSelector($previousSelector);

        if ($expectedTags === []) {
            return [];
        }

        $elements = collect($observation['interaction_map'] ?? $observation['elements'] ?? [])
            ->filter(fn (mixed $element): bool => is_array($element)
                && ($element['visible'] ?? null) === true
                && ($element['enabled'] ?? true) !== false
                && in_array(Str::lower(trim((string) ($element['tag'] ?? ''))), $expectedTags, true))
            ->values();

        if ($elements->isEmpty()) {
            return [];
        }

        $candidates = [];

        foreach ($elements as $element) {
            $elementRef = trim((string) ($element['element_ref'] ?? $element['ref'] ?? ''));
            $selectors = collect([
                ...(is_array($element['selector_candidates'] ?? null) ? $element['selector_candidates'] : []),
                $element['selector'] ?? null,
            ])
                ->map(fn (mixed $selector): string => trim((string) $selector))
                ->filter(fn (string $selector): bool => $selector !== ''
                    && $selector !== $previousSelector
                    && ! in_array($selector, $rejectedSelectors, true)
                    && $this->isSafeSelector($selector)
                    && $this->stabilityPriority($selector) >= self::MIN_CANDIDATE_STABILITY)
                ->unique()
                ->values();

            foreach ($selectors as $selector) {
                if (! isset($candidates[$selector])) {
                    $candidates[$selector] = [
                        'selector' => $selector,
                        'stability' => $this->stabilityPriority($selector),
                        'element_refs' => [],
                        'element_count' => 0,
                    ];
                }

                $candidates[$selector]['element_count']++;

                if ($elementRef !== '' && ! in_array($elementRef, $candidates[$selector]['element_refs'], true)) {
                    $candidates[$selector]['element_refs'][] = $elementRef;
                }
            }
        }

        if ($candidates === []) {
            return [];
        }

        $ranked = collect($candidates)
            ->sortByDesc(fn (array $candidate): int => (int) $candidate['stability'])
            ->values();
        $best = $ranked->first();
        $runnerUp = $ranked->get(1);

        if (! is_array($best)
            || (is_array($runnerUp) && (int) $runnerUp['stability'] >= (int) $best['stability'])) {
            return [];
        }

        return [
            'selector' => (string) $best['selector'],
            'previous_selector' => $previousSelector,
            'expected_tags' => $expectedTags,
            'candidate_count' => $ranked->count(),
            'matches' => $ranked->take(6)->all(),
        ];
    }

    /**
     * Zentrale Selektor-Sicherheitspruefung (kanonische Implementierung, wird
     * auch vom WorkflowCopilotRepairService delegiert verwendet).
     */
    public function isSafeSelector(string $selector): bool
    {
        return $selector !== ''
            && mb_strlen($selector) <= 1000
            && ! preg_match('/(?:javascript:|<script|\beval\s*\(|\bFunction\s*\()/i', $selector);
    }

    /**
     * Zentrale Stabilitaetsbewertung fuer Selektoren (kanonische
     * Implementierung, wird auch vom WorkflowCopilotRepairService delegiert
     * verwendet). Title/Aria/Placeholder/Name/Rolle/Text vor generierten IDs.
     */
    public function stabilityPriority(string $selector): int
    {
        $lower = Str::lower($selector);

        return match (true) {
            str_contains($lower, '[title=') => 1000,
            str_contains($lower, '[aria-label=') => 990,
            str_contains($lower, '[placeholder=') => 980,
            preg_match('/\[data-(?:testid|test|cy|qa)=/i', $selector) === 1 => 970,
            str_contains($lower, '[name=') => 900,
            str_contains($lower, ':has-text('), str_starts_with($lower, 'text=') => 880,
            str_contains($lower, ':has(h1'), str_contains($lower, ':has(h2'), str_contains($lower, ':has(h3') => 860,
            str_contains($lower, '[role='), str_contains($lower, '[type=') => 820,
            preg_match('/(?:^|[\s>+~,])#[A-Za-z0-9_-]+/', $selector) === 1,
            str_contains($lower, '[id=') => 100,
            preg_match('/(?:nth-child|nth-of-type)/i', $selector) === 1 => 50,
            default => 700,
        };
    }

    /**
     * Leitet aus dem bisherigen Selector die erwartete Elementrolle als Menge
     * erlaubter Tags ab. Ohne ableitbaren Tag (reine ID-/Klassen-/Attribut-
     * Selektoren) wird bewusst [] geliefert und keine Probe ausgeloest.
     *
     * @return list<string>
     */
    protected function expectedTagsForSelector(string $selector): array
    {
        $selector = Str::lower(trim($selector));

        // Klammerinhalte wie :has(...) iterativ entfernen, damit nur die
        // eigentlichen Zielsegmente uebrig bleiben.
        for ($iteration = 0; $iteration < 10 && str_contains($selector, '('); $iteration++) {
            $stripped = preg_replace('/\([^()]*\)/', '', $selector);

            if (! is_string($stripped) || $stripped === $selector) {
                break;
            }

            $selector = $stripped;
        }

        $tags = [];

        foreach (explode(',', $selector) as $alternative) {
            $parts = preg_split('/[\s>+~]+/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $last = trim((string) end($parts));

            if ($last === '' || preg_match('/^([a-z][a-z0-9-]*)/', $last, $matches) !== 1) {
                continue;
            }

            foreach (self::ROLE_TAG_GROUPS[$matches[1]] ?? [$matches[1]] as $tag) {
                if (! in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
        }

        return $tags;
    }
}
