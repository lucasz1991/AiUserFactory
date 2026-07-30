<?php

namespace Tests\Feature;

use App\Services\Workflows\WorkflowSelectorSyntaxService;
use Tests\TestCase;

/**
 * Baustein A (statische Stufe) aus dem Copilot-Konzept v2.
 *
 * Die Beispiele stammen aus echten Produktionslaeufen: Sie waren alle
 * syntaktisch gueltig und haben trotzdem den Lauf blockiert.
 */
class WorkflowSelectorQualityTest extends TestCase
{
    private function codes(string $selector): array
    {
        return array_column(
            app(WorkflowSelectorSyntaxService::class)->qualityWarningsFor($selector),
            'code',
        );
    }

    public function test_the_selector_that_actually_blocked_production_is_flagged(): void
    {
        // Protokoll Session 29/31: technisch erfolgreich, fachlich wirkungslos.
        $codes = $this->codes('button:has-text("Decline")');

        $this->assertContains('too_generic', $codes);
        $this->assertContains('language_dependent', $codes);
    }

    public function test_localized_attribute_is_flagged(): void
    {
        $this->assertContains('language_dependent', $this->codes('textarea[title="Suche"]'));
    }

    public function test_volatile_generated_attributes_are_flagged(): void
    {
        $this->assertContains('volatile_attribute', $this->codes('div#search a:has(div[data-rpos])'));
        $this->assertContains('volatile_attribute', $this->codes('div.css-1a2b3c > span'));
    }

    public function test_robust_selectors_stay_silent(): void
    {
        foreach (['textarea[name="q"]', '#search a:has(h3)', 'input#email', '[data-testid="submit"]'] as $selector) {
            $this->assertSame([], $this->codes($selector), "Sollte keine Warnung erzeugen: {$selector}");
        }
    }

    public function test_scoped_text_selector_is_accepted_as_specific_enough(): void
    {
        // Eingegrenzt auf einen Container: der Text-Hinweis bleibt, die
        // Zu-allgemein-Warnung entfaellt.
        $codes = $this->codes('#consent-banner button:has-text("Ablehnen")');

        $this->assertNotContains('too_generic', $codes);
        $this->assertContains('language_dependent', $codes);
    }

    public function test_empty_input_is_not_a_warning(): void
    {
        $this->assertSame([], $this->codes('   '));
    }

    public function test_existing_task_cards_are_audited(): void
    {
        $findings = app(WorkflowSelectorSyntaxService::class)->auditTaskCards([
            ['task_key' => 'browser.open', 'key' => 'google-oeffnen', 'title' => 'Google oeffnen'],
            [
                'task_key' => 'browser.click',
                'key' => 'consent-reject',
                'title' => 'Consent ablehnen',
                'selector' => 'button:has-text("Decline")',
            ],
            [
                'task_key' => 'input.fill_field',
                'key' => 'suche-fuellen',
                'title' => 'Suchfeld fuellen',
                'selector' => 'textarea[name="q"]',
            ],
        ]);

        $this->assertCount(1, $findings, 'Nur die problematische Karte darf gemeldet werden.');
        $this->assertSame('consent-reject', $findings[0]['card_key']);
        $this->assertSame('selector', $findings[0]['field']);
        $this->assertContains('too_generic', array_column($findings[0]['warnings'], 'code'));
    }

    public function test_audit_ignores_unknown_tasks_and_malformed_entries(): void
    {
        $findings = app(WorkflowSelectorSyntaxService::class)->auditTaskCards([
            'kaputt',
            ['task_key' => 'gibt.es.nicht', 'selector' => 'button:has-text("Decline")'],
            ['key' => 'ohne-task-key', 'selector' => 'button:has-text("Decline")'],
        ]);

        $this->assertSame([], $findings);
    }
}
