<?php

namespace App\Http\Controllers\Workflows;

use App\Http\Controllers\Controller;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunArtifact;
use App\Services\Workflows\WorkflowDebugArtifactService;
use Symfony\Component\HttpFoundation\Response;

class WorkflowRunArtifactController extends Controller
{
    public function show(
        WorkflowRun $run,
        WorkflowRunArtifact $artifact,
        WorkflowDebugArtifactService $artifacts,
    ): Response {
        $this->assertArtifactBelongsToRun($run, $artifact);

        $path = $artifacts->absolutePath($artifact);

        abort_unless($path !== null, 404);

        $headers = [
            'Content-Type' => $artifacts->mimeType($artifact),
            'Content-Disposition' => 'inline; filename="'.$artifacts->downloadName($artifact).'"',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($artifact->artifact_type === 'dom') {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; img-src data: blob:; style-src 'unsafe-inline'";
        }

        return response()->file($path, $headers);
    }

    public function download(
        WorkflowRun $run,
        WorkflowRunArtifact $artifact,
        WorkflowDebugArtifactService $artifacts,
    ): Response {
        $this->assertArtifactBelongsToRun($run, $artifact);

        $path = $artifacts->absolutePath($artifact);

        abort_unless($path !== null, 404);

        return response()->download($path, $artifacts->downloadName($artifact), [
            'Content-Type' => $artifacts->mimeType($artifact),
        ]);
    }

    protected function assertArtifactBelongsToRun(WorkflowRun $run, WorkflowRunArtifact $artifact): void
    {
        abort_unless((int) $artifact->workflow_run_id === (int) $run->id, 404);
    }
}
