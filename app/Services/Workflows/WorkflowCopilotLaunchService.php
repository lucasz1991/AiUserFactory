<?php

namespace App\Services\Workflows;

use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Models\Workflow;
use App\Services\Ai\WorkflowCopilotAiUsageTracker;
use DomainException;

class WorkflowCopilotLaunchService
{
    public function __construct(
        protected WorkflowCopilotPlanningService $planning,
        protected WorkflowDefinitionValidator $validator,
        protected WorkflowCopilotSessionService $sessions,
        protected WorkflowCopilotAiUsageTracker $usageTracker,
        protected WorkflowOptimizationPlanService $optimizationPlans,
    ) {}

    /** @return array{session:\App\Models\WorkflowCopilotSession,initial_plan:?array,validation:array} */
    public function start(Workflow $workflow, WorkflowCopilotLaunchRequest $request): array
    {
        if ($request->goal === '') {
            throw new DomainException('Fuer die Copilot-Optimierung ist ein konkretes Ziel erforderlich.');
        }
        if ($request->successCriteria === []) {
            throw new DomainException('Mindestens ein pruefbares Erfolgskriterium ist erforderlich.');
        }
        if ($request->workflowInputs !== [] && array_is_list($request->workflowInputs)) {
            throw new DomainException('Workflow-Eingaben muessen ein Objekt mit benannten Werten sein.');
        }

        $initialPlan = null;
        $initialAiUsage = [];
        if ($this->planning->needsInitialPlan($workflow)) {
            $this->usageTracker->beginCapture();
            try {
                $initialPlan = $this->planning->plan(
                    $workflow,
                    $request->goal,
                    $request->successCriteria,
                    $request->workflowInputs,
                );
            } finally {
                $initialAiUsage = $this->usageTracker->finishCapture();
            }
        }

        $validation = $initialPlan
            ? [
                'valid' => true,
                'stage' => 'blueprint',
                'message' => 'Der Gesamtplan ist gespeichert; Tasks werden einzeln materialisiert und getestet.',
                'task_count' => (int) ($initialPlan['task_count'] ?? 0),
            ]
            : $this->validator->assertValid($workflow, $request->successCriteria, $request->workflowInputs);
        $attributes = $request->sessionAttributes($initialPlan);
        $attributes['state']['definition_validation'] = $validation;
        $attributes['state']['launch_source'] = $request->source;
        $session = $this->sessions->start($workflow, $attributes);

        if ($initialAiUsage !== []) {
            $session = $this->sessions->recordAiUsage($session, $initialAiUsage, 'initial_planning');
        }
        if ($initialPlan) {
            $plan = $this->optimizationPlans->create($session, $initialPlan);
            $this->sessions->appendEvent(
                $session,
                'plan.blueprint_created',
                'Der Gesamtplan wurde gespeichert. Die erste Task wird erst durch den Supervisor eingesetzt und einzeln getestet.',
                ['plan_id' => $plan->id, 'plan' => $initialPlan, 'validation' => $validation],
                'planning',
                'success',
                true,
            );
        }

        WorkflowCopilotSupervisorJob::dispatch((int) $session->getKey());

        return [
            'session' => $session,
            'initial_plan' => $initialPlan,
            'validation' => $validation,
        ];
    }
}
