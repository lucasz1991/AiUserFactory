<?php

namespace App\Services\Workflows;

use App\Models\WorkflowRun;
use App\Models\WorkflowStep;

class ClientWorkflowBundleCompiler
{
    public function __construct(
        protected WorkflowTaskRunner $workflowTasks,
    ) {}

    public function compile(WorkflowRun $run): array
    {
        $run->loadMissing(['workflow.steps', 'stepRuns']);
        $steps = $run->workflow->steps
            ->filter(fn (WorkflowStep $step): bool => $step->is_enabled)
            ->values();
        $context = is_array($run->context_json) ? $run->context_json : [];
        $compiledSteps = [];
        $reasons = [];

        foreach ($steps as $index => $step) {
            $stepRun = $run->stepRuns->firstWhere('workflow_step_id', $step->id);

            if (! $stepRun) {
                $reasons[] = 'Step-Run fehlt: '.$step->name;
                continue;
            }

            $runtime = $this->workflowTasks->remoteRuntime($run, $step, $stepRun, $context);
            $tasks = is_array($runtime['tasks'] ?? null) ? $runtime['tasks'] : [];

            if ($tasks === [] && $step->type !== WorkflowStep::TYPE_WAIT) {
                $reasons[] = 'Schritt hat keine portable Client-Task: '.$step->name;
            }

            foreach ($tasks as $task) {
                $runner = strtolower(trim((string) ($task['runner'] ?? '')));
                $nodeScript = trim((string) ($task['node_script'] ?? ''));
                $phpHandler = trim((string) ($task['php_handler'] ?? ''));

                if ($runner === 'php' || $phpHandler !== '' || ($runner !== 'node' && $nodeScript === '')) {
                    $reasons[] = 'Nicht portable Task: '.$step->name.' / '.($task['title'] ?? $task['key'] ?? 'Task');
                }
            }

            $nextStep = $steps->get($index + 1);
            $compiledSteps[] = [
                'workflowStepId' => $step->id,
                'workflowStepRunId' => $stepRun->id,
                'actionKey' => $step->action_key,
                'name' => $step->name,
                'type' => $step->type,
                'retryAttempts' => max(0, (int) $step->retry_attempts),
                'waitAfterSeconds' => max(0, (int) $step->wait_after_seconds),
                'waitSeconds' => $step->type === WorkflowStep::TYPE_WAIT
                    ? max(0, (int) (data_get($step->config_json, 'seconds') ?: $step->wait_after_seconds))
                    : 0,
                'routes' => $step->routes,
                'defaultNext' => $nextStep?->action_key,
                'runtime' => $runtime,
            ];
        }

        return [
            'portable' => $reasons === [] && $compiledSteps !== [],
            'reasons' => array_values(array_unique($reasons)),
            'bundle' => [
                'schemaVersion' => 1,
                'workflowRunId' => $run->id,
                'workflowRunUuid' => $run->run_uuid,
                'workflowId' => $run->workflow_id,
                'workflowName' => $run->workflow->name,
                'startActionKey' => data_get($compiledSteps, '0.actionKey'),
                'context' => $context,
                'maxTransitions' => max(100, count($compiledSteps) * 20),
                'steps' => $compiledSteps,
            ],
        ];
    }
}
