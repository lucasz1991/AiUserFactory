<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowsIndex;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class WorkflowTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_csv_roundtrip_restores_workflows_steps_and_embedded_references(): void
    {
        $child = $this->workflow('child-flow', 'Child Flow');
        $child->steps()->create($this->stepAttributes('Child step', 'child-step'));

        $parent = $this->workflow('parent-flow', 'Parent Flow');
        $parent->steps()->create($this->stepAttributes('Parent step', 'parent-step', [
            'tasks' => [[
                'key' => 'run-child',
                'title' => 'Run child',
                'runner' => 'workflow',
                'workflow_id' => $child->id,
            ]],
        ]));

        $service = app(WorkflowTransferService::class);
        $csv = $service->csv([$parent, $child]);

        $this->assertStringContainsString('format_version,source_id,slug,name', $csv);
        $this->assertStringContainsString('child-flow', $csv);

        $parent->delete();
        $child->delete();

        $result = $service->importCsv($csv);

        $this->assertSame(['total' => 2, 'created' => 2, 'updated' => 0], $result);

        $importedParent = Workflow::query()->where('slug', 'parent-flow')->firstOrFail();
        $importedChild = Workflow::query()->where('slug', 'child-flow')->firstOrFail();
        $task = data_get($importedParent->steps()->firstOrFail()->config_json, 'tasks.0');

        $this->assertSame($importedChild->id, (int) data_get($task, 'workflow_id'));
        $this->assertSame('child-flow', data_get($task, 'workflow_slug'));
        $this->assertTrue($importedParent->includedWorkflows()->whereKey($importedChild->id)->exists());
        $this->assertSame('Child step', $importedChild->steps()->firstOrFail()->name);

        $importedParent->forceFill(['name' => 'Changed locally'])->save();
        $updated = $service->importCsv($csv);

        $this->assertSame(['total' => 2, 'created' => 0, 'updated' => 2], $updated);
        $this->assertSame('Parent Flow', $importedParent->fresh()->name);
    }

    public function test_zip_export_contains_csv_and_zip_and_csv_files_can_be_imported(): void
    {
        $workflow = $this->workflow('portable-flow', 'Portable Flow');
        $workflow->steps()->create($this->stepAttributes('Portable step', 'portable-step'));
        $service = app(WorkflowTransferService::class);
        $export = $service->zip([$workflow], 'portable-flow');

        $this->assertFileExists($export['path']);
        $this->assertSame('portable-flow.zip', $export['filename']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($export['path']) === true);
        $this->assertNotFalse($zip->locateName(WorkflowTransferService::CSV_FILENAME));
        $zip->close();

        $workflow->delete();
        $zipResult = $service->importFile($export['path'], 'portable-flow.zip');
        $this->assertSame(1, $zipResult['created']);

        $csvPath = tempnam(sys_get_temp_dir(), 'workflow-csv-');
        file_put_contents($csvPath, $service->csv([Workflow::query()->where('slug', 'portable-flow')->firstOrFail()]));

        try {
            $csvResult = $service->importFile($csvPath, 'portable-flow.csv');
            $this->assertSame(1, $csvResult['updated']);
        } finally {
            @unlink($csvPath);
            @unlink($export['path']);
        }
    }

    public function test_workflow_list_can_select_visible_or_all_workflows(): void
    {
        $custom = $this->workflow('custom-flow', 'Custom Flow');
        $mail = $this->workflow('mail-flow', 'Mail Flow');
        $mail->forceFill(['category' => 'mail'])->save();

        Livewire::test(WorkflowsIndex::class)
            ->set('activeGroup', 'custom')
            ->call('toggleSelectAllVisibleWorkflows')
            ->assertSet('selectedWorkflowIds', [(string) $custom->id])
            ->call('selectAllWorkflows')
            ->assertSet('selectedWorkflowIds', [(string) $custom->id, (string) $mail->id])
            ->call('clearWorkflowSelection')
            ->assertSet('selectedWorkflowIds', []);
    }

    protected function workflow(string $slug, string $name): Workflow
    {
        return Workflow::query()->create([
            'slug' => $slug,
            'name' => $name,
            'description' => $name.' description',
            'category' => 'custom',
            'subcategory' => 'transfer',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => ['portable' => true],
        ]);
    }

    protected function stepAttributes(string $name, string $actionKey, array $config = []): array
    {
        return [
            'name' => $name,
            'type' => WorkflowStep::TYPE_PREPARATION,
            'action_key' => $actionKey,
            'position' => 10,
            'is_enabled' => true,
            'config_json' => $config,
            'retry_attempts' => 1,
            'wait_after_seconds' => 2,
        ];
    }
}
