@props([
    'workflowRun',
    'process' => null,
    'activeStepId' => null,
    'activeTaskKey' => null,
])

<div {{ $attributes }}>
    <livewire:admin.network.workflow-run-preview
        :workflow-run-id="$workflowRun?->id"
        :active-step-id="$activeStepId"
        :active-task-key="$activeTaskKey"
        :process-pid="$process?->pid"
        :process-type="$process?->process_type"
        :process-status="$process?->status"
        :key="'workflow-run-preview-'.($workflowRun?->id ?? 'empty')"
    />
</div>
