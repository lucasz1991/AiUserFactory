<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Services\Workflows\WorkflowCopilotLogExportService;
use App\Services\Workflows\WorkflowCopilotSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class WorkflowCopilotLogExportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_export_contains_complete_audit_stream_and_redacts_secrets(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('PHP-Zip-Erweiterung ist nicht verfuegbar.');
        }

        $workflow = Workflow::query()->create([
            'name' => 'Exportierbarer Copilot Workflow',
            'slug' => 'exportierbarer-copilot-workflow',
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow, [
            'goal' => 'Workflow vollstaendig pruefen.',
            'workflow_inputs' => ['password' => 'never-export-this'],
        ]);
        $sessions->appendEvent(
            $session,
            'chat.assistant',
            'Workflow-Copilot hat geantwortet.',
            ['content' => 'Ich starte jetzt die Vorschau; der bekannte Wert never-export-this wird nicht exportiert.'],
            'conversation',
        );
        $sessions->appendEvent(
            $session,
            'tool.completed',
            'Assistant-Tool wurde abgeschlossen.',
            [
                'tool' => 'workflow_optimize_start',
                'arguments' => ['password' => 'never-export-this'],
                'result' => ['ok' => true, 'api_token' => 'also-never-export-this'],
            ],
            'conversation',
            'success',
        );

        $export = app(WorkflowCopilotLogExportService::class)->make($session->fresh());

        try {
            $this->assertFileExists($export['path']);
            $this->assertStringContainsString('workflow-copilot-log-', $export['filename']);

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($export['path']) === true);
            $this->assertNotFalse($zip->locateName('optimization/complete-log.json'));
            $this->assertNotFalse($zip->locateName('optimization/events.jsonl'));
            $this->assertNotFalse($zip->locateName('optimization/chat-and-tools.json'));
            $this->assertNotFalse($zip->locateName('workflow/final-workflow.json'));
            $this->assertNotFalse($zip->locateName('README.md'));

            $completeLog = (string) $zip->getFromName('optimization/complete-log.json');
            $chatAndTools = (string) $zip->getFromName('optimization/chat-and-tools.json');
            $zip->close();

            $this->assertStringContainsString('Ich starte jetzt die Vorschau;', $chatAndTools);
            $this->assertStringContainsString('workflow_optimize_start', $chatAndTools);
            $this->assertStringContainsString('[redacted]', $completeLog);
            $this->assertStringNotContainsString('never-export-this', $completeLog);
            $this->assertStringNotContainsString('also-never-export-this', $completeLog);
        } finally {
            @unlink($export['path']);
        }
    }
}
