<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\MonitorWorkflowStepRunJob;
use App\Models\WorkflowStepRun;
use Illuminate\Http\JsonResponse;

class WorkflowRuntimeCallbackController extends Controller
{
    /**
     * Verarbeitet ausschliesslich ein signiertes Fertig-Signal. Die fachlichen
     * Daten werden weiterhin aus den privaten Status-/Resultatdateien gelesen;
     * der Node-Prozess kann ueber den Callback also kein Ergebnis einschleusen.
     */
    public function completed(
        WorkflowStepRun $workflowStepRun,
        string $externalRunId,
    ): JsonResponse {
        if ($workflowStepRun->external_run_type !== 'workflow-task'
            || ! hash_equals((string) $workflowStepRun->external_run_id, $externalRunId)) {
            return response()->json([
                'ok' => false,
                'code' => 'runtime_callback_mismatch',
                'message' => 'Das signierte Fertig-Signal gehoert nicht zu diesem Workflow-Step-Run.',
            ], 409);
        }

        if (! in_array($workflowStepRun->status, ['running', 'waiting'], true)) {
            return response()->json([
                'ok' => true,
                'duplicate' => true,
                'workflow_step_run_id' => (int) $workflowStepRun->id,
                'status' => $workflowStepRun->status,
            ]);
        }

        // Erst antworten, dann im selben PHP-Lebenszyklus auswerten. Der
        // Abschluss kann den anfragenden Node-Prozess schliessen und darf daher
        // nicht innerhalb dessen noch offener HTTP-Anfrage blockieren.
        MonitorWorkflowStepRunJob::dispatchAfterResponse((int) $workflowStepRun->id);

        return response()->json([
            'ok' => true,
            'accepted' => true,
            'duplicate' => false,
            'workflow_step_run_id' => (int) $workflowStepRun->id,
            'status' => $workflowStepRun->status,
        ], 202);
    }
}
