<?php

namespace App\Services\Workflows;

use App\Models\WorkflowCopilotSession;

class WorkflowCopilotLaunchRequest
{
    public function __construct(
        public string $goal,
        public array $successCriteria,
        public array $workflowInputs = [],
        public ?int $personId = null,
        public array $budget = [],
        public string $permissionMode = 'ask_critical',
        public string $source = 'workflow-studio',
        public ?int $studioSessionId = null,
        public bool $unrestrictedWarningAcknowledged = false,
    ) {}

    public static function fromArray(array $attributes): self
    {
        $successCriteria = is_array($attributes['success_criteria'] ?? null)
            ? $attributes['success_criteria']
            : [];
        if (is_array($successCriteria['assertions'] ?? null)) {
            $successCriteria = $successCriteria['assertions'];
        }

        return new self(
            trim((string) ($attributes['goal'] ?? '')),
            collect($successCriteria)
                ->map(fn (mixed $item): string => trim(is_scalar($item)
                    ? (string) $item
                    : (json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')))
                ->filter()
                ->values()
                ->all(),
            is_array($attributes['workflow_inputs'] ?? null) ? $attributes['workflow_inputs'] : [],
            isset($attributes['person_id']) && (int) $attributes['person_id'] > 0 ? (int) $attributes['person_id'] : null,
            is_array($attributes['budget'] ?? null) ? $attributes['budget'] : [],
            trim((string) ($attributes['permission_mode'] ?? 'ask_critical')) ?: 'ask_critical',
            trim((string) ($attributes['source'] ?? 'workflow-studio')) ?: 'workflow-studio',
            isset($attributes['studio_session_id']) && (int) $attributes['studio_session_id'] > 0
                ? (int) $attributes['studio_session_id']
                : null,
            (bool) ($attributes['unrestricted_warning_acknowledged'] ?? false),
        );
    }

    public function sessionAttributes(?array $initialPlan = null): array
    {
        return [
            'person_id' => $this->personId,
            'execution_target' => WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM,
            'goal' => $this->goal,
            'success_criteria' => ['assertions' => $this->successCriteria],
            'workflow_inputs' => $this->workflowInputs,
            'state' => $initialPlan ? ['initial_plan' => $initialPlan] : [],
            'budget' => [
                ...$this->budget,
                'permission_mode' => $this->permissionMode,
            ],
        ];
    }
}
