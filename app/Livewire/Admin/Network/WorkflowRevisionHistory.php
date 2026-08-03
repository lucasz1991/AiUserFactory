<?php

namespace App\Livewire\Admin\Network;

use App\Models\Workflow;
use App\Models\WorkflowStudioRevision;
use App\Models\WorkflowStudioSession;
use App\Services\Workflows\WorkflowStudioDefinitionMutationPolicy;
use App\Services\Workflows\WorkflowStudioRevisionService;
use Livewire\Component;

class WorkflowRevisionHistory extends Component
{
    public int $workflowId;

    public int $studioSessionId;

    public string $selectedRevision = '';

    public string $compareRevision = '';

    public function mount(int $workflowId, int $studioSessionId): void
    {
        $this->workflowId = $workflowId;
        $this->studioSessionId = $studioSessionId;
    }

    public function restoreRevision(int $revisionNumber): void
    {
        $workflow = Workflow::query()->findOrFail($this->workflowId);
        $session = WorkflowStudioSession::query()
            ->where('workflow_id', $workflow->getKey())
            ->findOrFail($this->studioSessionId);
        app(WorkflowStudioDefinitionMutationPolicy::class)->assertCanMutate(
            $workflow,
            $session,
            false,
        );
        app(WorkflowStudioRevisionService::class)->restore(
            $session,
            $revisionNumber,
            (int) $workflow->copilot_revision,
            'Revision '.$revisionNumber.' wurde als neuer aktueller Stand wiederhergestellt.',
            'user:'.auth()->id(),
            fn (Workflow $lockedWorkflow) => app(WorkflowStudioDefinitionMutationPolicy::class)
                ->assertCanMutate($lockedWorkflow, $session),
        );
        session()->flash('success', 'Revision '.$revisionNumber.' wurde als neue Revision wiederhergestellt.');
        $this->dispatch('workflow-studio-revision-restored');
    }

    public function render()
    {
        $revisions = WorkflowStudioRevision::query()
            ->where('workflow_id', $this->workflowId)
            ->latest('revision_number')
            ->get();
        $selected = $this->selectedRevision !== '' ? $revisions->firstWhere('revision_number', (int) $this->selectedRevision) : null;
        $compare = $this->compareRevision !== '' ? $revisions->firstWhere('revision_number', (int) $this->compareRevision) : null;
        $comparison = $selected && $compare
            ? app(WorkflowStudioRevisionService::class)->diffSnapshots($compare->after_snapshot_json, $selected->after_snapshot_json)
            : [];

        return view('livewire.admin.network.workflow-revision-history', compact('revisions', 'selected', 'compare', 'comparison'));
    }
}
