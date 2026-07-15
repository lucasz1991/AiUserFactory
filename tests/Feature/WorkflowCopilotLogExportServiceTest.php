<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\Workflows\WorkflowCopilotLogExportService;
use App\Services\Workflows\WorkflowCopilotSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
            ['content' => 'Ich starte jetzt die Vorschau; der bekannte Wert never-export-this und eyJhbGciOiJIUzI1NiJ9.secret.signature werden nicht exportiert.'],
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
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'workflow_copilot_session_id' => $session->id,
            'workflow_revision' => 0,
            'status' => 'completed',
            'context_json' => [],
            'result_json' => [
                'diagnostic' => 'Provider lieferte eyJhbGciOiJIUzI1NiJ9.secret.signature zurueck.',
            ],
            'finished_at' => now(),
        ]);

        $export = app(WorkflowCopilotLogExportService::class)->make($session->fresh());
        $nestedPackagePath = null;

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
            $nestedPackageName = 'runs/workflow-run-'.$run->id.'-debug.zip';
            $this->assertNotFalse($zip->locateName($nestedPackageName));

            $completeLog = (string) $zip->getFromName('optimization/complete-log.json');
            $chatAndTools = (string) $zip->getFromName('optimization/chat-and-tools.json');
            $nestedPackage = (string) $zip->getFromName($nestedPackageName);
            $zip->close();

            $this->assertStringContainsString('Ich starte jetzt die Vorschau;', $chatAndTools);
            $this->assertStringContainsString('workflow_optimize_start', $chatAndTools);
            $this->assertStringContainsString('[redacted]', $completeLog);
            $this->assertStringNotContainsString('never-export-this', $completeLog);
            $this->assertStringNotContainsString('also-never-export-this', $completeLog);
            $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9.secret.signature', $completeLog);

            $nestedPackagePath = tempnam(sys_get_temp_dir(), 'workflow-run-debug-');
            $this->assertIsString($nestedPackagePath);
            $this->assertNotFalse(file_put_contents($nestedPackagePath, $nestedPackage));
            $nestedZip = new ZipArchive;
            $this->assertTrue($nestedZip->open($nestedPackagePath) === true);
            $runSnapshot = (string) $nestedZip->getFromName('run/workflow-run-'.$run->id.'.json');
            $nestedZip->close();
            $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9.secret.signature', $runSnapshot);
            $this->assertStringContainsString('[token redacted]', $runSnapshot);
        } finally {
            @unlink($export['path']);

            if (is_string($nestedPackagePath)) {
                @unlink($nestedPackagePath);
            }
        }
    }
}
