<?php

namespace App\Livewire\Admin\Network;

use App\Models\Workflow;
use Illuminate\Support\Str;
use Livewire\Component;

class WorkflowsIndex extends Component
{
    public string $newWorkflowName = '';

    public string $newWorkflowDescription = '';

    public function render()
    {
        $workflows = Workflow::query()
            ->with(['steps'])
            ->withCount(['steps', 'runs'])
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return view('livewire.admin.network.workflows-index', [
            'workflows' => $workflows,
            'summary' => [
                'workflows' => $workflows->count(),
                'active_workflows' => $workflows->where('is_active', true)->count(),
                'lists' => $workflows->sum('steps_count'),
                'task_cards' => $workflows->sum(fn (Workflow $workflow): int => $this->taskCardCount($workflow)),
            ],
        ])->layout('layouts.master');
    }

    public function createWorkflow(): void
    {
        $validated = $this->validate([
            'newWorkflowName' => ['required', 'string', 'max:160'],
            'newWorkflowDescription' => ['nullable', 'string', 'max:1000'],
        ]);

        $workflow = Workflow::query()->create([
            'name' => trim($validated['newWorkflowName']),
            'slug' => $this->uniqueSlug($validated['newWorkflowName']),
            'description' => trim((string) ($validated['newWorkflowDescription'] ?? '')),
            'category' => 'custom',
            'is_active' => true,
            'trigger_type' => 'manual',
            'settings_json' => [
                'created_from' => 'workflows-index',
            ],
        ]);

        $this->newWorkflowName = '';
        $this->newWorkflowDescription = '';

        session()->flash('success', 'Workflow wurde erstellt.');

        $this->redirectRoute('network.workflows.manage', ['workflow' => $workflow->id]);
    }

    public function deleteWorkflow(int $workflowId): void
    {
        $workflow = Workflow::query()->find($workflowId);

        if (! $workflow) {
            return;
        }

        $workflow->delete();

        session()->flash('success', 'Workflow wurde geloescht. Du kannst ihn jetzt per Seeder neu erzeugen.');
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workflow';
        $slug = $base;
        $counter = 2;

        while (Workflow::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }

    protected function taskCardCount(Workflow $workflow): int
    {
        return $workflow->steps->sum(fn ($step): int => count($step->task_cards));
    }
}
