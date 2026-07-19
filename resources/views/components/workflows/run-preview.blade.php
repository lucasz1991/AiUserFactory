@props([
    'workflowRun',
    'process' => null,
    'activeStepId' => null,
    'activeTaskKey' => null,
    'selectedStepId' => null,
    'selectedTaskKey' => null,
    'selectableTasks' => false,
    'expanded' => false,
    'diagramOnly' => false,
])

<div {{ $attributes }}>
    <livewire:admin.network.workflow-run-preview
        :workflow-run-id="$workflowRun?->id"
        :active-step-id="$activeStepId"
        :active-task-key="$activeTaskKey"
        :selected-step-id="$selectedStepId"
        :selected-task-key="$selectedTaskKey"
        :selectable-tasks="$selectableTasks"
        :expanded="$expanded"
        :diagram-only="$diagramOnly"
        :process-pid="$process?->pid"
        :process-type="$process?->process_type"
        :process-status="$process?->status"
        :key="'workflow-run-preview-'.($workflowRun?->id ?? 'empty').'-'.($selectedStepId ?? 'none').'-'.($selectedTaskKey ?: 'none')"
    />
</div>
