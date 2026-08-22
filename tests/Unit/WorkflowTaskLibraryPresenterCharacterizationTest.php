<?php

namespace Tests\Unit;

use App\Services\Workflows\WorkflowTaskCatalog;
use Tests\TestCase;

class WorkflowTaskLibraryPresenterCharacterizationTest extends TestCase
{
    public function test_library_arrangement_skips_invalid_rows_and_keeps_fallback_ordering_metadata(): void
    {
        $arranged = (new WorkflowTaskCatalog)->arrangeLibraryOptions([
            'invalid row',
            ['key' => 'custom.zulu', 'label' => 'Zulu', 'runner' => 'node'],
            ['task_key' => 'custom.alpha', 'label' => 'alpha', 'runner' => 'node'],
            ['key' => 'browser.open', 'label' => 'Browser starten', 'runner' => 'node'],
            ['key' => 'workflow.include.42', 'label' => 'Workflow', 'runner' => 'node'],
        ]);

        $this->assertSame([
            'browser.open',
            null,
            'custom.zulu',
            'workflow.include.42',
        ], collect($arranged)->pluck('key')->all());
        $this->assertSame('custom.alpha', $arranged[1]['task_key']);
        $this->assertSame('data', $arranged[1]['library_group']);
        $this->assertSame('Daten & Abschluss', $arranged[1]['library_group_label']);
        $this->assertSame(500, $arranged[1]['library_order']);
        $this->assertSame('workflows', $arranged[3]['library_group']);
        $this->assertSame('Unter-Workflows', $arranged[3]['library_group_label']);
    }

    public function test_workflow_runner_and_include_prefix_keep_their_existing_group_precedence(): void
    {
        $catalog = new WorkflowTaskCatalog;

        $this->assertSame('workflows', $catalog->libraryGroupFor('custom.task', ['runner' => 'workflow']));
        $this->assertSame('workflows', $catalog->libraryGroupFor('workflow.include.7', ['runner' => 'node']));
        $this->assertSame('data', $catalog->libraryGroupFor('unknown.task', ['kind' => 'browser']));
    }
}
