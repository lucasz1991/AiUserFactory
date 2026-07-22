<?php

namespace App\Services\Workflows;

use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowStudioSession;
use App\Services\Ai\WorkflowCopilotAiUsageTracker;
use DomainException;
use Throwable;

class WorkflowCopilotLaunchService
{
    public function __construct(
        protected WorkflowCopilotPlanningService $planning,
        protected WorkflowDefinitionValidator $validator,
        protected WorkflowRetryRouteAutoRepairService $retryRouteRepair,
        protected WorkflowCopilotSessionService $sessions,
        protected WorkflowCopilotPreflightService $preflight,
        protected WorkflowCopilotAiUsageTracker $usageTracker,
        protected WorkflowOptimizationPlanService $optimizationPlans,
        protected WorkflowStudioSessionService $studioSessions,
        protected WorkflowStudioControlService $studioControl,
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

        $needsInitialPlan = $this->planning->needsInitialPlan($workflow);
        $autoRepairedRoutes = $needsInitialPlan ? [] : $this->retryRouteRepair->repair($workflow);
        $validation = $needsInitialPlan
            ? [
                'valid' => true,
                'stage' => 'preflight',
                'message' => 'Historie wird vor der Erstplanung ausgewertet.',
                'task_count' => 0,
            ]
            : $this->validator->assertValid($workflow, $request->successCriteria, $request->workflowInputs);
        if ($autoRepairedRoutes !== []) {
            $validation['auto_repaired_routes'] = $autoRepairedRoutes;
        }
        $attributes = $request->sessionAttributes();
        $attributes['state']['definition_validation'] = $validation;
        $attributes['state']['launch_source'] = $request->source;
        $session = $this->sessions->start($workflow, $attributes);
        $initialPlan = null;

        try {
            $session = $this->attachStudioBeforeDispatch($workflow, $session, $request);

            if ($needsInitialPlan) {
                $preflight = $this->preflight->prepare($session, false);
                if (! $preflight['ready']) {
                    throw new DomainException('Die historische Vorab-Analyse konnte nicht sicher abgeschlossen werden.');
                }
                $session = $preflight['session']->fresh() ?? $preflight['session'];
                $historyPreflight = is_array(data_get($session->state_json, 'history_preflight'))
                    ? data_get($session->state_json, 'history_preflight')
                    : [];

                $this->usageTracker->beginCapture();
                try {
                    $initialPlan = $this->planning->plan(
                        $workflow,
                        $request->goal,
                        $request->successCriteria,
                        $request->workflowInputs,
                        $historyPreflight,
                    );
                } finally {
                    $initialAiUsage = $this->usageTracker->finishCapture();
                }

                if ($initialAiUsage !== []) {
                    $session = $this->sessions->recordAiUsage($session, $initialAiUsage, 'initial_planning');
                }
                $validation = [
                    'valid' => true,
                    'stage' => 'blueprint',
                    'message' => 'Der Gesamtplan ist gespeichert; Tasks werden einzeln materialisiert und getestet.',
                    'task_count' => (int) ($initialPlan['task_count'] ?? 0),
                ];
                $session = $this->sessions->updateState($session, [
                    'initial_plan' => $initialPlan,
                    'definition_validation' => $validation,
                ], 'planning');
            }
        } catch (Throwable $exception) {
            $this->sessions->stop(
                $session->fresh() ?? $session,
                'Start vor dem ersten Browser-Test abgebrochen: '.mb_substr(trim($exception->getMessage()), 0, 900),
            );

            throw $exception;
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

    protected function attachStudioBeforeDispatch(
        Workflow $workflow,
        WorkflowCopilotSession $session,
        WorkflowCopilotLaunchRequest $request,
    ): WorkflowCopilotSession {
        $actor = auth()->user();
        $studio = $request->studioSessionId
            ? WorkflowStudioSession::query()
                ->where('workflow_id', $workflow->getKey())
                ->when(
                    $actor && ! $actor->isAdmin(),
                    fn ($query) => $query->where('user_id', $actor->getKey()),
                )
                ->when(! $actor, fn ($query) => $query->whereNull('user_id'))
                ->findOrFail($request->studioSessionId)
            : WorkflowStudioSession::query()
                ->where('workflow_id', $workflow->getKey())
                ->whereNull('workflow_copilot_session_id')
                ->whereNull('finished_at')
                ->where(function ($query): void {
                    $query->whereNull('mode_locked_at')->orWhere('mode', 'autonomous');
                })
                ->when(
                    $actor,
                    fn ($query) => $query->where('user_id', $actor->getKey()),
                    fn ($query) => $query->whereNull('user_id'),
                )
                ->latest('last_activity_at')
                ->latest('id')
                ->first();

        $studio ??= $this->studioSessions->open(
            $workflow,
            $actor,
            'autonomous',
            $request->permissionMode,
            [
                'person_id' => $request->personId,
                'goal' => $request->goal,
                'success_criteria' => $request->successCriteria,
                'workflow_inputs' => $request->workflowInputs,
                'budget' => $request->budget,
                'execution_target' => WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM,
                'unrestricted_warning_acknowledged' => $request->unrestrictedWarningAcknowledged,
            ],
        );

        if (! $studio->mode_locked_at) {
            $studio = $this->studioControl->lock($studio, 'autonomous', $actor);
        } elseif ($studio->mode !== 'autonomous') {
            throw new DomainException('Die ausgewaehlte Studio-Sitzung ist bereits im interaktiven Modus gesperrt.');
        }

        $studio = $this->studioSessions->attachCopilotSession($studio, $session);
        $budget = is_array($session->budget_json) ? $session->budget_json : [];
        $budget['permission_mode'] = $studio->permission_mode;
        $budget['auto_execute_workflow_actions'] = $studio->permission_mode !== 'ask_all';
        $session->forceFill(['budget_json' => $budget, 'last_activity_at' => now()])->save();

        return $session->fresh(['workflow']) ?? $session;
    }
}
