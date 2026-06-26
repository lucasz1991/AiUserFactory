<?php

namespace App\Livewire\Admin\Network;

use App\Models\Workflow;
use Illuminate\Support\Str;
use Livewire\Component;

class WorkflowsIndex extends Component
{
    public string $activeGroup = 'all';

    public string $newWorkflowName = '';

    public string $newWorkflowDescription = '';

    public string $newWorkflowGroup = 'custom';

    public function render()
    {
        $workflows = Workflow::query()
            ->with(['steps'])
            ->withCount(['steps', 'runs'])
            ->orderBy('category')
            ->orderBy('name')
            ->get();
        $groups = $workflows
            ->pluck('category')
            ->map(fn (mixed $category): string => trim((string) $category) ?: 'custom')
            ->unique()
            ->sort()
            ->values();
        $visibleWorkflows = $this->activeGroup === 'all'
            ? $workflows
            : $workflows->where('category', $this->activeGroup)->values();

        return view('livewire.admin.network.workflows-index', [
            'workflows' => $workflows,
            'visibleWorkflows' => $visibleWorkflows,
            'groups' => $groups,
            'groupLabels' => $this->groupLabels($groups),
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
            'newWorkflowGroup' => ['required', 'string', 'max:80'],
        ]);

        $group = $this->normalizeGroup($validated['newWorkflowGroup']);
        $workflow = Workflow::query()->create([
            'name' => trim($validated['newWorkflowName']),
            'slug' => $this->uniqueSlug($validated['newWorkflowName']),
            'description' => trim((string) ($validated['newWorkflowDescription'] ?? '')),
            'category' => $group,
            'is_active' => true,
            'trigger_type' => 'manual',
            'settings_json' => [
                'created_from' => 'workflows-index',
            ],
        ]);

        $this->newWorkflowName = '';
        $this->newWorkflowDescription = '';
        $this->newWorkflowGroup = 'custom';
        $this->activeGroup = $group;

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

    protected function normalizeGroup(string $group): string
    {
        $group = Str::slug($group, '_');

        return $group !== '' ? $group : 'custom';
    }

    protected function groupLabels($groups): array
    {
        return collect($groups)
            ->mapWithKeys(fn (string $group): array => [$group => match ($group) {
                'mail' => 'E-Mail',
                'custom' => 'Eigene',
                'browser' => 'Browser',
                'data' => 'Daten',
                default => Str::of($group)->replace(['_', '-'], ' ')->title()->toString(),
            }])
            ->all();
    }
}
