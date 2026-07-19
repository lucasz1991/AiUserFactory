<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowOptimizationPlan;
use App\Models\WorkflowOptimizationPlanItem;
use App\Models\WorkflowRun;
use App\Models\WorkflowStudioSession;
use Illuminate\Support\Facades\DB;

class WorkflowOptimizationPlanService
{
    public function __construct(
        protected WorkflowRevisionService $revisions,
        protected WorkflowCopilotPlanningService $planning,
        protected WorkflowCopilotSessionService $sessions,
    ) {}

    public function create(WorkflowCopilotSession $session, array $blueprint): WorkflowOptimizationPlan
    {
        return DB::transaction(function () use ($session, $blueprint): WorkflowOptimizationPlan {
            $studio = WorkflowStudioSession::query()
                ->where('workflow_id', $session->workflow_id)
                ->whereNull('workflow_copilot_session_id')
                ->latest('id')
                ->first();
            $items = collect($blueprint['steps'] ?? [])->flatMap(function (array $step, int $stepIndex): array {
                return collect($step['tasks'] ?? [])->values()->map(fn (array $task, int $taskIndex): array => [
                    'step_index' => $stepIndex,
                    'task_index' => $taskIndex,
                    'step_action_key' => (string) $step['action_key'],
                    'task_key' => (string) $task['key'],
                    'catalog_task_key' => (string) $task['task_key'],
                    'blueprint_json' => ['step' => $step, 'task' => $task],
                ])->all();
            })->values();

            $plan = WorkflowOptimizationPlan::query()->create([
                'workflow_id' => $session->workflow_id,
                'workflow_copilot_session_id' => $session->id,
                'workflow_studio_session_id' => $studio?->id,
                'status' => 'planned',
                'goal_hash' => hash('sha256', (string) $session->goal.'|'.json_encode($session->success_criteria_json)),
                'plan_json' => $blueprint,
                'total_items' => $items->count(),
                'verified_items' => 0,
            ]);

            foreach ($items as $sequence => $item) {
                $plan->items()->create([...$item, 'sequence' => $sequence + 1, 'status' => 'planned']);
            }

            if ($studio) {
                $studio->forceFill(['workflow_copilot_session_id' => $session->id])->save();
            }

            return $plan->fresh('items') ?? $plan;
        });
    }

    public function active(WorkflowCopilotSession $session): ?WorkflowOptimizationPlan
    {
        return WorkflowOptimizationPlan::query()
            ->where('workflow_copilot_session_id', $session->id)
            ->whereNotIn('status', ['finalized', 'abandoned'])
            ->with('items')
            ->first();
    }

    public function materializeNext(WorkflowCopilotSession $session): ?WorkflowOptimizationPlanItem
    {
        $plan = $this->active($session);
        $item = $plan?->items->firstWhere('status', 'planned');

        if (! $plan || ! $item) {
            return null;
        }

        $blueprint = is_array($item->blueprint_json) ? $item->blueprint_json : [];
        $stepBlueprint = is_array($blueprint['step'] ?? null) ? $blueprint['step'] : [];
        $taskBlueprint = is_array($blueprint['task'] ?? null) ? $blueprint['task'] : [];
        $revision = $this->revisions->apply(
            $session,
            (int) $session->current_revision,
            'Plan-Task '.$item->sequence.' von '.$plan->total_items.' materialisiert: '.$item->task_key.'.',
            function (Workflow $workflow) use ($stepBlueprint, $taskBlueprint): void {
                $step = $workflow->steps()->where('action_key', (string) $stepBlueprint['action_key'])->first();

                if (! $step) {
                    $step = $workflow->steps()->create([
                        'name' => (string) ($stepBlueprint['name'] ?? 'Copilot-Schritt'),
                        'type' => (string) ($stepBlueprint['type'] ?? 'preparation'),
                        'action_key' => (string) $stepBlueprint['action_key'],
                        'position' => (((int) ($stepBlueprint['position'] ?? 0)) ?: (($workflow->steps()->max('position') ?? 0) + 10)),
                        'is_enabled' => true,
                        'config_json' => ['description' => $stepBlueprint['description'] ?? '', 'tasks' => [], 'routes' => []],
                    ]);
                }

                $config = is_array($step->config_json) ? $step->config_json : [];
                $tasks = collect(is_array($config['tasks'] ?? null) ? $config['tasks'] : []);

                if (! $tasks->contains(fn (array $task): bool => (string) ($task['key'] ?? '') === (string) ($taskBlueprint['key'] ?? ''))) {
                    $tasks->push($this->candidateTask($workflow, $step->action_key, $taskBlueprint));
                }

                $config['tasks'] = $tasks->values()->all();
                $config['routes'] = [];
                $step->forceFill(['config_json' => $config])->save();
            },
            'copilot-planner',
        );

        $item->forceFill([
            'status' => 'testing',
            'candidate_revision' => (int) $revision->revision_number,
            'attempts' => ((int) $item->attempts) + 1,
            'materialized_at' => now(),
        ])->save();
        $plan->forceFill(['status' => 'testing'])->save();
        $session = $session->fresh() ?? $session;
        $this->sessions->updateState($session, [
            'optimization_plan_id' => (int) $plan->id,
            'optimization_plan_item_id' => (int) $item->id,
            'current_step_name' => (string) ($stepBlueprint['name'] ?? $item->step_action_key),
            'current_task_key' => (string) $item->task_key,
            'last_action' => 'Naechste Plan-Task materialisiert',
            'next_action' => 'Task einzeln ausfuehren und Evidenz pruefen',
        ], 'executing');

        return $item->fresh() ?? $item;
    }

    public function resumeContext(WorkflowCopilotSession $session): array
    {
        $item = $this->active($session)?->items->firstWhere('status', 'testing');

        return $item ? [
            'next_step_action_key' => $item->step_action_key,
            'next_task_key' => $item->task_key,
            'optimization_plan_item_id' => (int) $item->id,
        ] : [];
    }

    /** @return array{continued:bool,finalized:bool,resume_context:array} */
    public function advanceAfterCompletedRun(WorkflowCopilotSession $session, WorkflowRun $run): array
    {
        $plan = $this->active($session);
        $item = $plan?->items->firstWhere('status', 'testing');

        if (! $plan || ! $item) {
            return ['continued' => false, 'finalized' => false, 'resume_context' => []];
        }

        $item->forceFill(['status' => 'verified', 'verified_at' => now()])->save();
        $verified = $plan->items()->where('status', 'verified')->count();
        $plan->forceFill(['verified_items' => $verified, 'status' => 'materializing'])->save();
        $session->forceFill(['active_workflow_run_id' => null, 'last_activity_at' => now()])->save();
        $this->sessions->appendEvent(
            $session,
            'plan.task_verified',
            'Plan-Task `'.$item->task_key.'` wurde erfolgreich getestet. Erst jetzt wird die naechste Task eingesetzt.',
            ['plan_item_id' => $item->id, 'sequence' => $item->sequence, 'workflow_run_id' => $run->id],
            'planning',
            'success',
            true,
        );

        if ($plan->items()->where('status', 'planned')->exists()) {
            $next = $this->materializeNext($session->fresh() ?? $session);

            return ['continued' => (bool) $next, 'finalized' => false, 'resume_context' => $next ? $this->resumeContext($session->fresh() ?? $session) : []];
        }

        $this->finalize($session->fresh() ?? $session, $plan->fresh('items') ?? $plan);

        return ['continued' => false, 'finalized' => true, 'resume_context' => []];
    }

    protected function finalize(WorkflowCopilotSession $session, WorkflowOptimizationPlan $plan): void
    {
        $blueprint = is_array($plan->plan_json) ? $plan->plan_json : [];
        $revision = $this->revisions->apply(
            $session,
            (int) $session->current_revision,
            'Alle einzeln bestaetigten Plan-Tasks als vollstaendige Definition mit finalen Routen verbunden.',
            fn (Workflow $workflow) => $this->planning->applyPlan(
                $workflow,
                (string) $session->goal,
                $blueprint,
                is_array($session->success_criteria_json) ? $session->success_criteria_json : [],
                is_array($session->workflow_inputs_json) ? $session->workflow_inputs_json : [],
                true,
            ),
            'copilot-planner',
        );
        $plan->forceFill([
            'status' => 'finalized',
            'finalized_revision' => (int) $revision->revision_number,
            'finalized_at' => now(),
        ])->save();
        $this->sessions->appendEvent(
            $session->fresh() ?? $session,
            'plan.finalized',
            'Der vollstaendige Workflow wurde verbunden. Jetzt startet der unveraenderliche Kontrolllauf.',
            ['revision' => (int) $revision->revision_number, 'verified_items' => (int) $plan->verified_items],
            'verifying',
            'success',
            true,
        );
    }

    protected function candidateTask(Workflow $workflow, string $sourceStep, array $task): array
    {
        foreach (['next', 'on_partial', 'on_error'] as $field) {
            if (! isset($task[$field]) || $this->routeTargetExists($workflow, $sourceStep, $task[$field])) {
                continue;
            }

            $task[$field] = ['type' => 'end'];
        }

        if (is_array($task['status_routes'] ?? null)) {
            $task['status_routes'] = collect($task['status_routes'])
                ->map(fn (mixed $route): mixed => $this->routeTargetExists($workflow, $sourceStep, $route) ? $route : ['type' => 'end'])
                ->all();
        }

        return $task;
    }

    protected function routeTargetExists(Workflow $workflow, string $sourceStep, mixed $route): bool
    {
        if (! is_array($route)) {
            return false;
        }

        $type = (string) ($route['type'] ?? '');
        if (in_array($type, ['end', 'fail'], true)) {
            return true;
        }

        $stepKey = (string) ($route['action_key'] ?? $route['step'] ?? $sourceStep);
        $step = $workflow->steps()->where('action_key', $stepKey)->first();
        if (! $step) {
            return false;
        }

        if ($type !== 'card') {
            return true;
        }

        $card = (string) ($route['card_key'] ?? $route['card'] ?? '');

        return collect($step->task_cards)->contains(fn (array $task): bool => (string) ($task['key'] ?? '') === $card);
    }
}
