<?php

namespace Tests\Unit;

use App\Services\Workflows\WorkflowSelectorSyntaxService;
use Tests\TestCase;

class WorkflowSelectorSyntaxServiceTest extends TestCase
{
    public function test_element_candidate_contract_matches_the_node_resolver_syntax(): void
    {
        $syntax = app(WorkflowSelectorSyntaxService::class);

        foreach ([
            'button[type=submit], text=Weiter',
            'text-is="Jetzt anmelden"',
            'css=body',
            'button:has-text("Weiter")',
            'button:has(span:has-text("Login"))',
        ] as $selector) {
            $this->assertNull(
                $syntax->validate($selector, WorkflowSelectorSyntaxService::MODE_ELEMENT_CANDIDATES),
                $selector,
            );
        }

        foreach ([
            'css=',
            'button[type=submit',
            'button:has-text(Weiter)',
            'button,,text=Weiter',
            'text=',
        ] as $selector) {
            $this->assertNotNull(
                $syntax->validate($selector, WorkflowSelectorSyntaxService::MODE_ELEMENT_CANDIDATES),
                $selector,
            );
        }
    }

    public function test_raw_css_contract_rejects_resolver_only_tokens(): void
    {
        $syntax = app(WorkflowSelectorSyntaxService::class);

        $this->assertNull($syntax->validate(
            'article:has(h3), [data-result]',
            WorkflowSelectorSyntaxService::MODE_CSS_SELECTOR,
        ));
        $this->assertNull($syntax->validate(
            'input[name*="email" i]',
            WorkflowSelectorSyntaxService::MODE_CSS_SELECTOR,
        ));

        foreach ([
            'text=Weiter',
            'css=button',
            'button:has-text("Weiter")',
            'input[name=email',
        ] as $selector) {
            $this->assertNotNull(
                $syntax->validate($selector, WorkflowSelectorSyntaxService::MODE_CSS_SELECTOR),
                $selector,
            );
        }
    }

    public function test_structured_selector_fields_validate_their_embedded_selectors(): void
    {
        $syntax = app(WorkflowSelectorSyntaxService::class);

        $this->assertNull($syntax->validate(
            '[{"name":"title","selector":"h3","fallback_selectors":["[role=heading]"],"type":"text"}]',
            WorkflowSelectorSyntaxService::MODE_SELECTOR_FIELD_DEFINITIONS,
        ));
        $this->assertNotNull($syntax->validate(
            '[{"name":"title","selector":"h3[","type":"text"}]',
            WorkflowSelectorSyntaxService::MODE_SELECTOR_FIELD_DEFINITIONS,
        ));
        $this->assertNotNull($syntax->validate(
            '[{"name":"title","selector":[],"type":"text"}]',
            WorkflowSelectorSyntaxService::MODE_SELECTOR_FIELD_DEFINITIONS,
        ));
        $this->assertNull($syntax->validate(
            '{}',
            WorkflowSelectorSyntaxService::MODE_SELECTOR_FALLBACK_MAP,
        ));
        $this->assertNull($syntax->validate(
            '{"title":["h3","[role=heading]"],"link":["a[href]"]}',
            WorkflowSelectorSyntaxService::MODE_SELECTOR_FALLBACK_MAP,
        ));
        $this->assertNotNull($syntax->validate(
            '{"unknown":["h3"]}',
            WorkflowSelectorSyntaxService::MODE_SELECTOR_FALLBACK_MAP,
        ));
        $this->assertNull($syntax->validate(
            '[{"selector":"text=Loeschen","wait_ms":500},{"selector":"button:has-text(\"Bestaetigen\")","required":false}]',
            WorkflowSelectorSyntaxService::MODE_SELECTOR_ACTION_STEPS,
        ));
        $this->assertNull($syntax->validate(
            '[{"selector":"text=Loeschen","required":false,},]',
            WorkflowSelectorSyntaxService::MODE_SELECTOR_ACTION_STEPS,
        ));
        $this->assertNotNull($syntax->validate(
            '[{"selector":"button[","required":"sometimes"}]',
            WorkflowSelectorSyntaxService::MODE_SELECTOR_ACTION_STEPS,
        ));
    }

    public function test_task_field_map_distinguishes_css_text_targets_and_workflow_variable_names(): void
    {
        $syntax = app(WorkflowSelectorSyntaxService::class);

        $this->assertSame(
            WorkflowSelectorSyntaxService::MODE_ELEMENT_CANDIDATES,
            $syntax->modeFor('browser.click', 'selector'),
        );
        $this->assertSame(
            WorkflowSelectorSyntaxService::MODE_CSS_SELECTOR,
            $syntax->modeFor('mail.inbox_list_scan', 'value'),
        );
        $this->assertSame(
            WorkflowSelectorSyntaxService::MODE_VARIABLE_PATH,
            $syntax->modeFor('data.workflow_return', 'selector'),
        );
        $this->assertNull($syntax->validate(
            'workflow_return.result-1',
            WorkflowSelectorSyntaxService::MODE_VARIABLE_PATH,
        ));
        $this->assertNotNull($syntax->validate(
            'workflow return',
            WorkflowSelectorSyntaxService::MODE_VARIABLE_PATH,
        ));
    }
}
