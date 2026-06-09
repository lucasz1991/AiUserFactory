<?php

namespace App\Services\Simulation;

use App\Models\Person;
use Illuminate\Support\Carbon;

class PersonaActivityPlanner
{
    protected int $state = 1;

    protected array $intensityProfiles = [
        'quiet' => [
            'label' => 'Ruhig',
            'daily_sessions' => [1, 2],
            'weekend_sessions' => [1, 3],
            'weekly_posts' => [1, 2],
            'like_chance' => 52,
            'comment_chance' => 10,
            'save_chance' => 8,
        ],
        'balanced' => [
            'label' => 'Ausgewogen',
            'daily_sessions' => [2, 4],
            'weekend_sessions' => [2, 4],
            'weekly_posts' => [2, 4],
            'like_chance' => 66,
            'comment_chance' => 17,
            'save_chance' => 13,
        ],
        'active' => [
            'label' => 'Aktiv',
            'daily_sessions' => [3, 5],
            'weekend_sessions' => [3, 5],
            'weekly_posts' => [3, 6],
            'like_chance' => 74,
            'comment_chance' => 24,
            'save_chance' => 17,
        ],
        'creator' => [
            'label' => 'Creator',
            'daily_sessions' => [4, 6],
            'weekend_sessions' => [3, 6],
            'weekly_posts' => [5, 9],
            'like_chance' => 78,
            'comment_chance' => 28,
            'save_chance' => 20,
        ],
    ];

    public function build(Person $person, int $days = 7, string $intensity = 'balanced', ?string $seed = null, ?Carbon $startsAt = null): array
    {
        $days = max(1, min(14, $days));
        $intensity = array_key_exists($intensity, $this->intensityProfiles) ? $intensity : 'balanced';
        $profile = $this->buildPersonaProfile($person, $intensity);
        $timezone = $profile['timezone'];
        $startsAt = ($startsAt ?: Carbon::now($timezone))->copy()->timezone($timezone)->startOfDay();
        $seed = trim((string) $seed);
        $seed = $seed !== '' ? $seed : $this->defaultSeed($person, $startsAt, $days, $intensity);

        $this->seed($seed);

        $config = $this->intensityProfiles[$intensity];
        $profile['weekly_post_target'] = $this->randomInt($config['weekly_posts'][0], $config['weekly_posts'][1]);

        $postDayIndexes = $this->postDayIndexes(
            days: $days,
            postTarget: (int) round($profile['weekly_post_target'] * ($days / 7))
        );

        $daysPlan = [];
        $totals = [
            'planned_sessions' => 0,
            'planned_steps' => 0,
            'planned_posts' => 0,
            'planned_comments' => 0,
            'planned_likes' => 0,
            'max_day_risk_score' => 0,
        ];

        for ($index = 0; $index < $days; $index++) {
            $date = $startsAt->copy()->addDays($index);
            $isWeekend = in_array((int) $date->dayOfWeekIso, [6, 7], true);
            $sessionCount = $this->sessionCount($config, $isWeekend);
            $contentItems = in_array($index, $postDayIndexes, true)
                ? $this->buildContentItems($date, $profile)
                : [];
            $sessions = $this->buildSessions($date, $sessionCount, $isWeekend, $profile, $config, $contentItems);
            $dayMetrics = $this->dayMetrics($sessions, $contentItems);
            $dayRisk = $this->dayRiskScore($dayMetrics);

            $totals['planned_sessions'] += $dayMetrics['sessions'];
            $totals['planned_steps'] += $dayMetrics['steps'];
            $totals['planned_posts'] += count($contentItems);
            $totals['planned_comments'] += $dayMetrics['comments'];
            $totals['planned_likes'] += $dayMetrics['likes'];
            $totals['max_day_risk_score'] = max($totals['max_day_risk_score'], $dayRisk);

            $daysPlan[] = [
                'date' => $date->toDateString(),
                'weekday' => $this->weekdayLabel((int) $date->dayOfWeekIso),
                'day_type' => $isWeekend ? 'weekend' : 'weekday',
                'anchor' => $this->dayAnchor($isWeekend, $profile),
                'mood' => $this->pick($this->moodsFor($profile)),
                'sessions' => $sessions,
                'content_items' => $contentItems,
                'metrics' => [
                    ...$dayMetrics,
                    'risk_score' => $dayRisk,
                    'risk_level' => $this->riskLevel($dayRisk),
                ],
            ];
        }

        $averageStepsPerSession = $totals['planned_sessions'] > 0
            ? round($totals['planned_steps'] / $totals['planned_sessions'], 1)
            : 0;

        return [
            'schema_version' => 1,
            'scope' => 'internal_sandbox_only',
            'status' => $person->bot_status === 'disabled' ? 'paused' : 'draft',
            'generated_at' => Carbon::now($timezone)->toIso8601String(),
            'seed' => $seed,
            'days' => $days,
            'timezone' => $timezone,
            'intensity' => $intensity,
            'intensity_label' => $config['label'],
            'profile' => $profile,
            'metrics' => [
                ...$totals,
                'average_steps_per_session' => $averageStepsPerSession,
                'review_required' => $totals['max_day_risk_score'] >= 70,
            ],
            'governance' => [
                'real_platform_access' => false,
                'browser_automation' => false,
                'external_api_actions' => false,
                'requires_admin_review_before_execution' => true,
                'notes' => [
                    'Dieser Plan ist nur fuer eine interne Sandbox gedacht.',
                    'Schritte beschreiben interne Feed- und Session-Ereignisse, keine echten Plattformaktionen.',
                    'Login-, Cookie-, Scraper- und Browserdaten werden nicht verwendet.',
                ],
            ],
            'days_plan' => $daysPlan,
        ];
    }

    protected function buildPersonaProfile(Person $person, string $intensity): array
    {
        $identity = is_array($person->identity_profile) ? $person->identity_profile : [];
        $bot = is_array($person->bot_profile) ? $person->bot_profile : [];
        $interests = $this->listFrom($identity['interests'] ?? []);
        $traits = $this->listFrom($identity['personality_traits'] ?? []);
        $values = $this->listFrom($identity['values'] ?? []);
        $languages = $this->listFrom($identity['languages'] ?? []);
        $occupation = trim((string) ($identity['occupation'] ?? ''));

        if ($interests === []) {
            $interests = $this->fallbackInterests($occupation, trim((string) $person->person_city));
        }

        $contentThemes = $this->contentThemes($interests, $occupation, trim((string) $person->person_city));

        return [
            'display_name' => $person->display_name ?: $person->profile_label,
            'age' => $this->ageFromDate($person->person_date_of_birth),
            'timezone' => $this->normalizeTimezone($person->person_timezone),
            'city' => trim((string) $person->person_city),
            'country' => trim((string) $person->person_country),
            'occupation' => $occupation,
            'daily_routine' => trim((string) ($identity['daily_routine'] ?? '')),
            'background_story' => trim((string) ($identity['background_story'] ?? '')),
            'communication_style' => trim((string) ($bot['communication_style'] ?? '')),
            'writing_style' => trim((string) ($bot['writing_style'] ?? '')),
            'behavior_guidelines' => trim((string) ($bot['behavior_guidelines'] ?? '')),
            'languages' => array_slice($languages, 0, 4),
            'interests' => array_slice($interests, 0, 8),
            'personality_traits' => array_slice($traits, 0, 8),
            'values' => array_slice($values, 0, 8),
            'content_themes' => array_slice($contentThemes, 0, 8),
            'activity_model' => [
                'planner' => 'macro_themes_and_day_anchors',
                'behavior_engine' => 'deterministic_stochastic_sessions',
                'intensity' => $intensity,
                'session_timing' => 'circadian_slots_with_random_offsets',
                'interaction_distribution' => 'bursty_clusters_inside_sessions',
            ],
        ];
    }

    protected function buildSessions(Carbon $date, int $sessionCount, bool $isWeekend, array $profile, array $config, array $contentItems): array
    {
        $slots = $this->pickSlots($isWeekend, $sessionCount);
        $sessions = [];
        $contentAssigned = false;

        foreach ($slots as $slot) {
            $startMinute = $this->randomInt($slot['range'][0], $slot['range'][1]);
            $duration = $this->randomInt($slot['duration'][0], $slot['duration'][1]);
            $theme = $this->pick($profile['content_themes']);
            $contentItem = null;

            if (! $contentAssigned && $contentItems !== [] && in_array($slot['type'], ['afternoon_creation', 'evening_deep_dive', 'weekend_creation'], true)) {
                $contentItem = $contentItems[0];
                $contentAssigned = true;
            }

            $sessions[] = [
                'starts_at_local' => $this->timeLabel($startMinute),
                'duration_minutes' => $duration,
                'session_type' => $slot['type'],
                'intent' => $this->sessionIntent($slot['type'], $theme, $profile),
                'energy' => $slot['energy'],
                'theme' => $theme,
                'steps' => $this->buildSteps($slot['type'], $duration, $theme, $profile, $config, $contentItem),
            ];
        }

        usort($sessions, static fn (array $left, array $right): int => strcmp($left['starts_at_local'], $right['starts_at_local']));

        return array_values($sessions);
    }

    protected function buildSteps(string $sessionType, int $duration, string $theme, array $profile, array $config, ?array $contentItem): array
    {
        $steps = [
            $this->step(0, 'open_home_feed', 'Internen Home-Feed oeffnen', 'Start der Sandbox-Session.'),
        ];
        $offset = $this->randomInt(1, 3);
        $clusters = max(1, min(4, intdiv($duration, 9) + $this->randomInt(0, 1)));

        for ($cluster = 1; $cluster <= $clusters; $cluster++) {
            $steps[] = $this->step(
                min($offset, $duration),
                'scroll_batch',
                'Feed-Batch scannen',
                sprintf('Kandidaten zu "%s" und nahen Interessen pruefen.', $theme)
            );

            $items = $this->randomInt(1, 3);

            for ($item = 1; $item <= $items; $item++) {
                $offset = min($duration, $offset + $this->randomInt(1, 2));
                $steps[] = $this->step(
                    $offset,
                    'open_post',
                    'Internen Beitrag oeffnen',
                    sprintf('Beitrag aus dem Themenfeld "%s" ansehen.', $theme)
                );

                $offset = min($duration, $offset + $this->randomInt(0, 1));
                $steps[] = $this->step(
                    $offset,
                    'dwell',
                    'Kurz verweilen',
                    $this->randomInt(18, 95).' Sekunden simulierte Lese- oder Betrachtungszeit.'
                );

                if ($this->roll($config['like_chance'])) {
                    $steps[] = $this->step($offset, 'internal_like', 'Interne Reaktion', 'Positive Reaktion im Sandbox-Feed speichern.');
                }

                if ($this->roll($config['comment_chance'])) {
                    $steps[] = $this->step(
                        $offset,
                        'internal_comment',
                        'Interner Kommentar',
                        $this->commentIntent($theme, $profile)
                    );
                }

                if ($this->roll($config['save_chance'])) {
                    $steps[] = $this->step($offset, 'internal_save', 'Intern merken', 'Beitrag fuer spaeter in der Sandbox markieren.');
                }

                $offset = min($duration, $offset + $this->randomInt(1, 4));
            }
        }

        if ($contentItem !== null) {
            $steps[] = $this->step(
                max(1, min($duration - 1, $offset)),
                $contentItem['type'] === 'story' ? 'create_internal_story' : 'create_internal_post',
                $contentItem['type'] === 'story' ? 'Interne Story planen' : 'Internen Beitrag planen',
                $contentItem['prompt']
            );
        }

        $steps[] = $this->step($duration, 'close_session', 'Session beenden', 'Zustand speichern und naechste interne Aktivitaet abwarten.');

        usort($steps, static fn (array $left, array $right): int => $left['offset_minutes'] <=> $right['offset_minutes']);

        return array_values($steps);
    }

    protected function buildContentItems(Carbon $date, array $profile): array
    {
        $theme = $this->pick($profile['content_themes']);
        $type = $this->roll(35) ? 'story' : 'photo_post';
        $time = $this->timeLabel($this->randomInt(11 * 60, 21 * 60 + 30));
        $style = $profile['writing_style'] !== '' ? $profile['writing_style'] : 'natuerlich, kurz und alltagsnah';

        return [[
            'planned_time_local' => $time,
            'type' => $type,
            'theme' => $theme,
            'prompt' => sprintf(
                'Interner %s fuer %s: %s. Ton: %s. Keine Marken, keine echten Handles, keine Plattformdaten.',
                $type === 'story' ? 'Story-Impuls' : 'Feed-Beitrag',
                $date->toDateString(),
                $theme,
                $style
            ),
            'visibility' => 'internal_sandbox',
        ]];
    }

    protected function dayMetrics(array $sessions, array $contentItems): array
    {
        $steps = 0;
        $comments = 0;
        $likes = 0;

        foreach ($sessions as $session) {
            $steps += count($session['steps'] ?? []);

            foreach (($session['steps'] ?? []) as $step) {
                $comments += ($step['action'] ?? null) === 'internal_comment' ? 1 : 0;
                $likes += ($step['action'] ?? null) === 'internal_like' ? 1 : 0;
            }
        }

        return [
            'sessions' => count($sessions),
            'steps' => $steps,
            'content_items' => count($contentItems),
            'comments' => $comments,
            'likes' => $likes,
        ];
    }

    protected function dayRiskScore(array $metrics): int
    {
        $score = 8;
        $score += $metrics['sessions'] * 7;
        $score += (int) ceil($metrics['steps'] / 3);
        $score += $metrics['content_items'] * 9;
        $score += $metrics['comments'] * 2;

        return max(1, min(100, $score));
    }

    protected function riskLevel(int $score): string
    {
        return match (true) {
            $score >= 70 => 'review',
            $score >= 45 => 'moderate',
            default => 'low',
        };
    }

    protected function postDayIndexes(int $days, int $postTarget): array
    {
        $postTarget = max(0, min($days, $postTarget));
        $indexes = [];

        while (count($indexes) < $postTarget) {
            $indexes[] = $this->randomInt(0, $days - 1);
            $indexes = array_values(array_unique($indexes));
        }

        sort($indexes);

        return $indexes;
    }

    protected function sessionCount(array $config, bool $isWeekend): int
    {
        $range = $isWeekend ? $config['weekend_sessions'] : $config['daily_sessions'];
        $count = $this->randomInt($range[0], $range[1]);

        if ($this->roll($isWeekend ? 30 : 18)) {
            $count++;
        }

        return max(1, min(6, $count));
    }

    protected function pickSlots(bool $isWeekend, int $count): array
    {
        $slots = $isWeekend ? $this->weekendSlots() : $this->weekdaySlots();
        $weighted = [];

        foreach ($slots as $slot) {
            $weighted[] = [
                'slot' => $slot,
                'weight' => $this->randomInt(1, 1000),
            ];
        }

        usort($weighted, static fn (array $left, array $right): int => $left['weight'] <=> $right['weight']);

        return array_map(
            static fn (array $entry): array => $entry['slot'],
            array_slice($weighted, 0, min($count, count($weighted)))
        );
    }

    protected function weekdaySlots(): array
    {
        return [
            ['type' => 'morning_check', 'range' => [7 * 60 + 5, 9 * 60 + 20], 'duration' => [7, 18], 'energy' => 'low'],
            ['type' => 'midday_break', 'range' => [11 * 60 + 40, 13 * 60 + 50], 'duration' => [8, 22], 'energy' => 'medium'],
            ['type' => 'commute_scroll', 'range' => [16 * 60 + 30, 18 * 60 + 45], 'duration' => [9, 24], 'energy' => 'medium'],
            ['type' => 'evening_deep_dive', 'range' => [19 * 60, 22 * 60 + 15], 'duration' => [18, 42], 'energy' => 'high'],
            ['type' => 'late_light_check', 'range' => [22 * 60, 23 * 60 + 20], 'duration' => [5, 14], 'energy' => 'low'],
        ];
    }

    protected function weekendSlots(): array
    {
        return [
            ['type' => 'late_morning_browse', 'range' => [9 * 60 + 15, 11 * 60 + 45], 'duration' => [12, 28], 'energy' => 'medium'],
            ['type' => 'afternoon_creation', 'range' => [13 * 60, 16 * 60 + 30], 'duration' => [18, 48], 'energy' => 'high'],
            ['type' => 'errand_micro_check', 'range' => [16 * 60, 18 * 60 + 15], 'duration' => [5, 16], 'energy' => 'low'],
            ['type' => 'evening_deep_dive', 'range' => [19 * 60, 22 * 60 + 40], 'duration' => [20, 55], 'energy' => 'high'],
            ['type' => 'weekend_creation', 'range' => [10 * 60, 20 * 60], 'duration' => [25, 60], 'energy' => 'high'],
        ];
    }

    protected function sessionIntent(string $sessionType, string $theme, array $profile): string
    {
        $occupation = $profile['occupation'] !== '' ? $profile['occupation'] : 'Alltag';

        return match ($sessionType) {
            'morning_check' => 'Kurzer Check vor dem Tagesstart, nur relevante interne Feed-Signale aufnehmen.',
            'midday_break' => sprintf('Pause nutzen, um Inhalte zu "%s" locker zu lesen.', $theme),
            'commute_scroll' => sprintf('Uebergang nach %s mit kurzen Feed-Bursts simulieren.', $occupation),
            'evening_deep_dive' => sprintf('Laengere Abend-Session mit Kommentaren und Themenvertiefung zu "%s".', $theme),
            'late_light_check' => 'Sehr kurze spaete Rueckkehr ohne neue grosse Aktivitaet.',
            'late_morning_browse' => sprintf('Wochenendlicher Einstieg mit entspannter Suche nach "%s".', $theme),
            'afternoon_creation', 'weekend_creation' => sprintf('Eigenen internen Inhalt rund um "%s" vorbereiten.', $theme),
            'errand_micro_check' => 'Kurze Unterwegs-Session mit wenig Interaktion.',
            default => sprintf('Interne Sandbox-Session zu "%s".', $theme),
        };
    }

    protected function commentIntent(string $theme, array $profile): string
    {
        $style = $profile['communication_style'] !== '' ? $profile['communication_style'] : 'kurz, freundlich und persoenlich';

        return sprintf('Kommentarabsicht: passend zu "%s", Stil: %s.', $theme, $style);
    }

    protected function dayAnchor(bool $isWeekend, array $profile): string
    {
        if ($isWeekend) {
            return $this->pick([
                'Freizeit und Erholung',
                'Besorgungen und spontane Ideen',
                'Hobby-Zeit mit lockerer Online-Aktivitaet',
            ]);
        }

        if ($profile['occupation'] !== '') {
            return 'Arbeitstag mit Aktivitaet vor, waehrend und nach '.$profile['occupation'];
        }

        return 'Normaler Alltag mit kurzen Check-ins und Abendfenster';
    }

    protected function moodsFor(array $profile): array
    {
        $traits = $profile['personality_traits'];
        $moods = ['ruhig', 'aufmerksam', 'alltagsnah', 'neugierig', 'fokussiert'];

        foreach ($traits as $trait) {
            $moods[] = strtolower($trait);
        }

        return array_values(array_unique(array_filter($moods)));
    }

    protected function contentThemes(array $interests, string $occupation, string $city): array
    {
        $themes = [];

        foreach ($interests as $interest) {
            $themes[] = $interest;
            $themes[] = 'Alltagsmoment rund um '.$interest;
        }

        if ($occupation !== '') {
            $themes[] = 'Beruflicher Einblick: '.$occupation;
        }

        if ($city !== '') {
            $themes[] = 'Lokaler Alltag in '.$city;
        }

        $themes[] = 'unaufgeregter Tagesmoment';
        $themes[] = 'kurzer Gedanke aus dem Alltag';

        return array_values(array_unique(array_filter(array_map('trim', $themes))));
    }

    protected function fallbackInterests(string $occupation, string $city): array
    {
        $fallback = ['Musik', 'Kochen', 'Fitness', 'Reisen', 'Fotografie'];

        if ($occupation !== '') {
            array_unshift($fallback, $occupation);
        }

        if ($city !== '') {
            $fallback[] = 'Lokales aus '.$city;
        }

        return array_values(array_unique($fallback));
    }

    protected function listFrom(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[\r\n,;]+/', (string) $value) ?: [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $items
        )));
    }

    protected function step(int $offset, string $action, string $label, string $details): array
    {
        return [
            'offset_minutes' => max(0, $offset),
            'action' => $action,
            'label' => $label,
            'details' => $details,
        ];
    }

    protected function ageFromDate(mixed $date): ?int
    {
        if ($date instanceof Carbon) {
            return $date->age;
        }

        $date = trim((string) $date);

        if ($date === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function normalizeTimezone(mixed $timezone): string
    {
        $timezone = trim((string) $timezone);

        if ($timezone === '') {
            return 'Europe/Berlin';
        }

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return 'Europe/Berlin';
        }
    }

    protected function defaultSeed(Person $person, Carbon $startsAt, int $days, string $intensity): string
    {
        return hash('sha256', implode('|', [
            $person->getKey() ?: $person->profile_key ?: $person->profile_label,
            $startsAt->toDateString(),
            $days,
            $intensity,
        ]));
    }

    protected function seed(string $seed): void
    {
        $hash = hexdec(substr(hash('sha256', $seed), 0, 8));
        $this->state = max(1, (int) $hash);
    }

    protected function randomInt(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        $this->state = (int) (($this->state * 1103515245 + 12345) % 2147483647);

        return $min + ($this->state % (($max - $min) + 1));
    }

    protected function roll(int $chancePercent): bool
    {
        return $this->randomInt(1, 100) <= max(0, min(100, $chancePercent));
    }

    protected function pick(array $items): mixed
    {
        $items = array_values($items);

        if ($items === []) {
            return null;
        }

        return $items[$this->randomInt(0, count($items) - 1)];
    }

    protected function timeLabel(int $minuteOfDay): string
    {
        $minuteOfDay = max(0, min((24 * 60) - 1, $minuteOfDay));

        return sprintf('%02d:%02d', intdiv($minuteOfDay, 60), $minuteOfDay % 60);
    }

    protected function weekdayLabel(int $dayOfWeekIso): string
    {
        return [
            1 => 'Montag',
            2 => 'Dienstag',
            3 => 'Mittwoch',
            4 => 'Donnerstag',
            5 => 'Freitag',
            6 => 'Samstag',
            7 => 'Sonntag',
        ][$dayOfWeekIso] ?? 'Unbekannt';
    }
}
