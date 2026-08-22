<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\PortalProfiles;
use App\Models\WorkflowPortalProfile;
use App\Services\Workflows\WorkflowPortalProfileService;
use App\Services\Workflows\WorkflowPortalSelectorLearningService;
use App\Services\Workflows\WorkflowSelectorProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Baustein B (Portal-Profile) aus dem Copilot-Konzept v2.
 *
 * Die Selektoren stammen aus echten Produktionslaeufen: 'textarea[title="Suche"]'
 * und 'button:has-text("Decline")' waren syntaktisch gueltig und haben den Lauf
 * trotzdem blockiert.
 */
class WorkflowPortalProfileTest extends TestCase
{
    use RefreshDatabase;

    private function service(): WorkflowPortalProfileService
    {
        return app(WorkflowPortalProfileService::class);
    }

    public function test_remember_creates_the_entry_and_counts_further_hits(): void
    {
        $service = $this->service();

        $created = $service->remember('https://www.google.de/search?q=test', 'search_input', 'textarea[name="q"]', 'vision');

        $this->assertSame('google.de', $created->domain);
        $this->assertSame('search_input', $created->role);
        $this->assertSame(1, $created->hit_count);
        $this->assertSame(0, $created->miss_count);
        $this->assertSame('vision', $created->source);
        $this->assertNotNull($created->last_confirmed_at);

        $repeated = $service->remember('google.de', 'search_input', 'textarea[name="q"]');

        $this->assertSame($created->getKey(), $repeated->getKey(), 'Derselbe Selector darf keinen zweiten Eintrag erzeugen.');
        $this->assertSame(2, $repeated->hit_count);
        $this->assertSame('vision', $repeated->fresh()->source, 'Eine Bestaetigung ohne Quelle darf die bekannte Quelle nicht loeschen.');
        $this->assertSame(1, WorkflowPortalProfile::query()->count());

        $service->remember('google.de', 'search_input', 'input[name="q"]');

        $this->assertSame(2, WorkflowPortalProfile::query()->count(), 'Ein anderer Selector ist ein eigener Eintrag.');
    }

    public function test_remember_stores_the_quality_verdict_of_the_selector_syntax_service(): void
    {
        $service = $this->service();

        $risky = $service->remember('google.de', 'consent_reject', 'button:has-text("Decline")');
        $robust = $service->remember('google.de', 'search_input', 'textarea[name="q"]');

        $this->assertTrue($risky->has_quality_warnings);
        $this->assertFalse($robust->has_quality_warnings);
    }

    public function test_best_for_prefers_the_higher_success_rate(): void
    {
        $service = $this->service();

        $service->remember('google.de', 'result_link', '#search a:has(h3)');
        $service->remember('google.de', 'result_link', '#search a:has(h3)');
        $service->remember('google.de', 'result_link', '#search a:has(h3)');
        $service->recordMiss('google.de', 'result_link', '#search a:has(h3)');

        $service->remember('google.de', 'result_link', '#rso a');
        $service->recordMiss('google.de', 'result_link', '#rso a');

        // 3/4 gegen 1/2 — beide warnungsfrei, beide nicht verfallen.
        $this->assertSame('#search a:has(h3)', $service->bestFor('google.de', 'result_link'));
        $this->assertSame(
            ['#search a:has(h3)', '#rso a'],
            $service->rankedSelectorsFor('google.de', 'result_link'),
        );
    }

    public function test_an_expired_entry_is_never_delivered(): void
    {
        $service = $this->service();

        $service->remember('portal.example', 'consent_reject', '#cmp .reject');
        $service->recordMiss('portal.example', 'consent_reject', '#cmp .reject');
        $service->recordMiss('portal.example', 'consent_reject', '#cmp .reject');

        $expired = WorkflowPortalProfile::query()->firstOrFail();
        $this->assertSame(1, $expired->hit_count);
        $this->assertSame(2, $expired->miss_count);
        $this->assertTrue($expired->isExpired());

        $this->assertNull($service->bestFor('portal.example', 'consent_reject'));

        $service->remember('portal.example', 'consent_reject', '#cmp button[data-action="reject"]');

        $this->assertSame('#cmp button[data-action="reject"]', $service->bestFor('portal.example', 'consent_reject'));
        $this->assertSame(
            ['#cmp button[data-action="reject"]'],
            $service->rankedSelectorsFor('portal.example', 'consent_reject'),
            'Der verfallene Eintrag darf auch nicht als Fallback erscheinen.',
        );
    }

    public function test_a_warning_free_selector_beats_a_flagged_one_at_the_same_rate(): void
    {
        $service = $this->service();

        $this->travelTo(Carbon::parse('2026-07-01 10:00:00'));
        $service->remember('google.de', 'consent_reject', '#CXQnmb button[jsname="tWT92d"]');

        // Spaeter bestaetigt, aber sprachabhaengig und zu allgemein: Die
        // juengere Bestaetigung darf die Warnung nicht ausstechen.
        $this->travelTo(Carbon::parse('2026-07-20 10:00:00'));
        $service->remember('google.de', 'consent_reject', 'button:has-text("Decline")');
        $this->travelBack();

        $this->assertSame('#CXQnmb button[jsname="tWT92d"]', $service->bestFor('google.de', 'consent_reject'));
    }

    public function test_at_equal_rate_and_equal_quality_the_last_confirmed_wins(): void
    {
        $service = $this->service();

        $this->travelTo(Carbon::parse('2026-07-01 10:00:00'));
        $service->remember('google.de', 'search_input', 'input[name="q"]');

        $this->travelTo(Carbon::parse('2026-07-20 10:00:00'));
        $service->remember('google.de', 'search_input', 'textarea[name="q"]');
        $this->travelBack();

        $this->assertSame('textarea[name="q"]', $service->bestFor('google.de', 'search_input'));
    }

    public function test_record_miss_does_not_invent_entries_for_unknown_selectors(): void
    {
        $service = $this->service();

        $service->recordMiss('google.de', 'search_input', 'textarea[title="Suche"]');

        $this->assertSame(0, WorkflowPortalProfile::query()->count());
        $this->assertNull($service->bestFor('google.de', 'search_input'));
    }

    public function test_entries_are_kept_apart_by_domain_and_role(): void
    {
        $service = $this->service();

        $service->remember('google.de', 'search_input', 'textarea[name="q"]');
        $service->remember('bing.com', 'search_input', 'input[name="q"]');
        $service->remember('google.de', 'result_link', '#search a:has(h3)');

        $this->assertSame('textarea[name="q"]', $service->bestFor('https://www.google.de/imghp', 'search_input'));
        $this->assertSame('input[name="q"]', $service->bestFor('bing.com', 'search_input'));
        $this->assertSame('#search a:has(h3)', $service->bestFor('google.de', 'result_link'));
        $this->assertNull($service->bestFor('duckduckgo.com', 'search_input'));
        $this->assertNull($service->bestFor('google.de', 'gibt_es_nicht'));
    }

    public function test_portal_memory_prioritizes_only_a_selector_confirmed_by_the_current_dom(): void
    {
        $profile = $this->service();
        $learning = app(WorkflowPortalSelectorLearningService::class);
        $probes = app(WorkflowSelectorProbeService::class);
        $task = [
            'task_key' => 'input.fill_field',
            'title' => 'Suchbegriff eingeben',
            'selector' => 'input.legacy-search',
        ];
        $profile->remember('example.test', 'search_input', 'input[name="q"]');
        $observation = [
            'page' => ['url' => 'https://example.test/search'],
            'interaction_map' => [[
                'element_ref' => 'el_search',
                'tag' => 'input',
                'visible' => true,
                'enabled' => true,
                'selector_candidates' => ['input[name="query"]', 'input[name="q"]'],
                'selector_evidence' => [[
                    'selector' => 'input[name="query"]',
                    'unique' => true,
                    'match_count' => 1,
                ], [
                    'selector' => 'input[name="q"]',
                    'unique' => true,
                    'match_count' => 1,
                ]],
            ]],
        ];
        $preferred = $learning->preferredSelectors($task, $observation);

        $candidate = $probes->bestCandidate($task, $observation, [], ['el_search'], $preferred);

        $this->assertSame(['input[name="q"]'], $preferred);
        $this->assertSame('input[name="q"]', data_get($candidate, 'selector'));
        $this->assertTrue((bool) data_get($candidate, 'portal_profile_match'));

        data_set($observation, 'interaction_map.0.selector_candidates', ['input[name="query"]']);
        data_set($observation, 'interaction_map.0.selector_evidence', [[
            'selector' => 'input[name="query"]',
            'unique' => true,
            'match_count' => 1,
        ]]);
        $currentDomOnly = $probes->bestCandidate($task, $observation, [], ['el_search'], $preferred);

        $this->assertSame('input[name="query"]', data_get($currentDomOnly, 'selector'));
        $this->assertFalse((bool) data_get($currentDomOnly, 'portal_profile_match'));
    }

    public function test_probe_outcome_updates_the_matching_portal_profile(): void
    {
        $learning = app(WorkflowPortalSelectorLearningService::class);
        $plan = [
            'portal_profile_context' => ['domain' => 'example.test', 'role' => 'search_input'],
            'changes' => ['selector' => 'input[name="q"]'],
        ];

        $learning->rememberSuccessfulProbe($plan);
        $learning->recordFailedProbe($plan);

        $stored = WorkflowPortalProfile::query()->firstOrFail();
        $this->assertSame('example.test', $stored->domain);
        $this->assertSame('search_input', $stored->role);
        $this->assertSame(1, $stored->hit_count);
        $this->assertSame(1, $stored->miss_count);
        $this->assertSame('copilot_probe', $stored->source);
        $this->assertSame(1, $stored->profile_version);
        $this->assertSame(1, data_get($stored->evidence_json, 'match_count'));
        $this->assertNotEmpty(data_get($stored->evidence_json, 'observed_at'));
    }

    public function test_manual_rollback_excludes_a_profile_until_an_explicit_approval(): void
    {
        $service = $this->service();
        $profile = $service->remember('example.test', 'search_input', 'input[name="q"]');

        $rolledBack = $service->rollback($profile, null, 'Selector ist nach Portal-Update falsch.');

        $this->assertFalse($rolledBack->is_active);
        $this->assertNotNull($rolledBack->disabled_at);
        $this->assertSame('Selector ist nach Portal-Update falsch.', $rolledBack->disable_reason);
        $this->assertSame(2, $rolledBack->profile_version);
        $this->assertNull($service->bestFor('example.test', 'search_input'));

        // Ein spaeterer Lauf darf einen bewussten Rollback nicht still aufheben.
        $service->remember('example.test', 'search_input', 'input[name="q"]', 'copilot_probe');
        $this->assertNull($service->bestFor('example.test', 'search_input'));

        $approved = $service->approve($rolledBack->fresh());

        $this->assertTrue($approved->is_active);
        $this->assertTrue($approved->is_approved);
        $this->assertNull($approved->disabled_at);
        $this->assertSame('input[name="q"]', $service->bestFor('example.test', 'search_input'));
    }

    public function test_admin_dashboard_exposes_conflicts_and_can_rollback_the_losing_selector(): void
    {
        $service = $this->service();
        $preferred = $service->remember('example.test', 'search_input', 'input[name="q"]');
        $losing = $service->remember('example.test', 'search_input', 'input[name="query"]');

        Livewire::test(PortalProfiles::class)
            ->set('status', 'conflicts')
            ->assertSee('input[name=&quot;q&quot;]', false)
            ->assertSee('input[name=&quot;query&quot;]', false)
            ->call('rollbackProfile', $losing->id)
            ->assertSee('wurde zurückgerollt');

        $this->assertTrue($preferred->fresh()->is_active);
        $this->assertFalse($losing->fresh()->is_active);
        $this->assertSame('input[name="q"]', $service->bestFor('example.test', 'search_input'));
    }

    public function test_normalize_domain_reduces_urls_to_the_stored_host(): void
    {
        $service = $this->service();

        $this->assertSame('google.de', $service->normalizeDomain('https://www.Google.de/suche'));
        $this->assertSame('google.de', $service->normalizeDomain('GOOGLE.DE'));
        $this->assertSame('sub.example.com', $service->normalizeDomain('http://sub.example.com:8080/pfad?q=1#frag'));
        $this->assertSame('portal.de', $service->normalizeDomain('https://benutzer:geheim@www.portal.de/pfad'));
        $this->assertSame('example.de', $service->normalizeDomain('  www.example.de/  '));
        $this->assertSame('localhost', $service->normalizeDomain('http://localhost:8721'));
        $this->assertSame('', $service->normalizeDomain('   '));
    }

    public function test_forget_stale_removes_only_entries_without_recent_confirmation(): void
    {
        $service = $this->service();

        $this->travelTo(Carbon::parse('2026-01-01 08:00:00'));
        $service->remember('alt.example', 'search_input', '#alt input');

        $this->travelTo(Carbon::parse('2026-07-30 08:00:00'));
        $service->remember('neu.example', 'search_input', '#neu input');

        $removed = $service->forgetStale(90);
        $this->travelBack();

        $this->assertSame(1, $removed);
        $this->assertSame(['neu.example'], WorkflowPortalProfile::query()->pluck('domain')->all());
    }

    public function test_incomplete_input_is_rejected_instead_of_stored(): void
    {
        $service = $this->service();

        foreach ([['', 'search_input', 'input'], ['google.de', '   ', 'input'], ['google.de', 'search_input', '  ']] as $arguments) {
            try {
                $service->remember(...$arguments);
                $this->fail('Unvollstaendige Eingabe muesste abgelehnt werden: '.json_encode($arguments));
            } catch (InvalidArgumentException) {
                // erwartet
            }
        }

        $this->assertSame(0, WorkflowPortalProfile::query()->count());
    }

    /**
     * Die rohe Trefferquote liess einen einzigen Treffer (1,0) einen ueber viele
     * Laeufe bewaehrten Selector (20/1 = 0,952) verdraengen. Die geglaettete
     * Quote verhindert genau das.
     */
    public function test_a_single_hit_does_not_outrank_a_long_proven_selector(): void
    {
        $service = app(WorkflowPortalProfileService::class);

        for ($i = 0; $i < 20; $i++) {
            $service->remember('example.test', 'search_input', 'textarea[name="q"]');
        }
        $service->recordMiss('example.test', 'search_input', 'textarea[name="q"]');

        $service->remember('example.test', 'search_input', 'form input');

        $this->assertSame(
            'textarea[name="q"]',
            $service->bestFor('example.test', 'search_input'),
            'Der bewaehrte Selector darf nicht von einem einzelnen Treffer verdraengt werden.',
        );
    }

    public function test_forget_stale_refuses_to_wipe_the_table(): void
    {
        $service = app(WorkflowPortalProfileService::class);
        $service->remember('example.test', 'search_input', 'textarea[name="q"]');

        $this->expectException(InvalidArgumentException::class);

        $service->forgetStale(0);
    }
}
