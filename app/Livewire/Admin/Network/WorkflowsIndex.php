<?php

namespace App\Livewire\Admin\Network;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowTransferService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class WorkflowsIndex extends Component
{
    use WithFileUploads;

    public string $activeGroup = 'all';

    public string $activeSubcategory = 'all';

    public string $newWorkflowName = '';

    public string $newWorkflowDescription = '';

    public string $newWorkflowGroup = 'custom';

    public string $newWorkflowSubcategory = '';

    public bool $showCreateWorkflowModal = false;

    public bool $showEditWorkflowModal = false;

    public ?int $editingWorkflowId = null;

    public string $editingWorkflowName = '';

    public string $editingWorkflowDescription = '';

    public string $editingWorkflowGroup = 'custom';

    public string $editingWorkflowSubcategory = '';

    public bool $editingWorkflowActive = true;

    public bool $editingWorkflowLocked = false;

    public bool $editingWorkflowIncluded = false;

    public bool $editingWorkflowEffectiveLocked = false;

    public array $selectedWorkflowIds = [];

    public mixed $workflowImportFile = null;

    public bool $showImportWorkflowModal = false;

    public function render()
    {
        $workflows = Workflow::query()
            ->with(['steps', 'includedByWorkflows'])
            ->withCount([
                'steps',
                'runs',
                'runs as successful_runs_count' => fn ($query) => $query->where('status', 'completed'),
                'runs as failed_runs_count' => fn ($query) => $query->where('status', 'failed'),
            ])
            ->orderBy('category')
            ->orderBy('subcategory')
            ->orderBy('name')
            ->get();
        $groups = $workflows
            ->pluck('category')
            ->map(fn (mixed $category): string => trim((string) $category) ?: 'custom')
            ->unique()
            ->sort()
            ->values();
        $groupWorkflows = $this->activeGroup === 'all'
            ? $workflows
            : $workflows->where('category', $this->activeGroup)->values();
        $subcategories = $groupWorkflows
            ->pluck('subcategory')
            ->map(fn (mixed $subcategory): string => trim((string) $subcategory))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($this->activeSubcategory !== 'all' && ! $subcategories->contains($this->activeSubcategory)) {
            $this->activeSubcategory = 'all';
        }

        $visibleWorkflows = $this->activeSubcategory === 'all'
            ? $groupWorkflows
            : $groupWorkflows->where('subcategory', $this->activeSubcategory)->values();

        return view('livewire.admin.network.workflows-index', [
            'workflows' => $workflows,
            'groupWorkflows' => $groupWorkflows,
            'visibleWorkflows' => $visibleWorkflows,
            'groups' => $groups,
            'subcategories' => $subcategories,
            'groupLabels' => $this->groupLabels($groups),
            'summary' => [
                'workflows' => $workflows->count(),
                'active_workflows' => $workflows->where('is_active', true)->count(),
                'lists' => $workflows->sum('steps_count'),
                'task_cards' => $workflows->sum(fn (Workflow $workflow): int => $this->taskCardCount($workflow)),
            ],
        ])->layout('layouts.master');
    }

    public function selectWorkflowGroup(string $group): void
    {
        $this->activeGroup = trim($group) !== '' ? trim($group) : 'all';
        $this->activeSubcategory = 'all';
    }

    public function toggleSelectAllVisibleWorkflows(): void
    {
        $visibleIds = $this->visibleWorkflowQuery()->pluck('id')->map(fn ($id): string => (string) $id);
        $selectedIds = collect($this->selectedWorkflowIds)->map(fn ($id): string => (string) $id);
        $allVisibleSelected = $visibleIds->isNotEmpty() && $visibleIds->every(fn (string $id): bool => $selectedIds->contains($id));

        $this->selectedWorkflowIds = $allVisibleSelected
            ? $selectedIds->reject(fn (string $id): bool => $visibleIds->contains($id))->values()->all()
            : $selectedIds->merge($visibleIds)->unique()->values()->all();
    }

    public function clearWorkflowSelection(): void
    {
        $this->selectedWorkflowIds = [];
    }

    public function selectAllWorkflows(): void
    {
        $this->selectedWorkflowIds = Workflow::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    public function exportSelectedWorkflows(WorkflowTransferService $transferService): mixed
    {
        $ids = collect($this->selectedWorkflowIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            session()->flash('error', 'Bitte mindestens einen Workflow fuer den Export auswaehlen.');

            return null;
        }

        $workflows = Workflow::query()
            ->with(['steps' => fn ($query) => $query->ordered()])
            ->whereKey($ids->all())
            ->orderBy('name')
            ->get();

        if ($workflows->isEmpty()) {
            session()->flash('error', 'Die ausgewaehlten Workflows wurden nicht gefunden.');

            return null;
        }

        $export = $transferService->zip(
            $workflows,
            'workflows-'.$workflows->count().'-'.now()->format('Y-m-d-His'),
        );

        return response()->download($export['path'], $export['filename'])->deleteFileAfterSend(true);
    }

    public function importWorkflows(WorkflowTransferService $transferService): void
    {
        $this->validate([
            'workflowImportFile' => ['required', 'file', 'max:10240'],
        ]);

        try {
            $result = $transferService->importFile(
                $this->workflowImportFile->getRealPath(),
                $this->workflowImportFile->getClientOriginalName(),
            );
        } catch (Throwable $exception) {
            $this->addError('workflowImportFile', $exception->getMessage());

            return;
        }

        $this->reset('workflowImportFile', 'selectedWorkflowIds');
        $this->showImportWorkflowModal = false;

        session()->flash(
            'success',
            $result['total'].' Workflows importiert: '.$result['created'].' neu, '.$result['updated'].' aktualisiert.',
        );
    }

    public function createWorkflow(): void
    {
        $validated = $this->validate([
            'newWorkflowName' => ['required', 'string', 'max:160'],
            'newWorkflowDescription' => ['nullable', 'string', 'max:1000'],
            'newWorkflowGroup' => ['required', 'string', 'max:80'],
            'newWorkflowSubcategory' => ['nullable', 'string', 'max:80'],
        ]);

        $group = $this->normalizeGroup($validated['newWorkflowGroup']);
        $subcategory = $this->normalizeSubcategory($validated['newWorkflowSubcategory'] ?? '');
        $workflow = Workflow::query()->create([
            'name' => trim($validated['newWorkflowName']),
            'slug' => $this->uniqueSlug($validated['newWorkflowName']),
            'description' => trim((string) ($validated['newWorkflowDescription'] ?? '')),
            'category' => $group,
            'subcategory' => $subcategory,
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [
                'created_from' => 'workflows-index',
            ],
        ]);

        $this->newWorkflowName = '';
        $this->newWorkflowDescription = '';
        $this->newWorkflowGroup = 'custom';
        $this->newWorkflowSubcategory = '';
        $this->showCreateWorkflowModal = false;
        $this->activeGroup = $group;
        $this->activeSubcategory = $subcategory ?: 'all';

        session()->flash('success', 'Workflow wurde erstellt.');

        $this->redirectRoute('network.workflows.manage', ['workflow' => $workflow->id]);
    }

    public function openEditWorkflow(int $workflowId): void
    {
        $workflow = Workflow::query()->with('includedByWorkflows')->find($workflowId);

        if (! $workflow) {
            return;
        }

        $this->editingWorkflowId = $workflow->id;
        $this->editingWorkflowName = $workflow->name;
        $this->editingWorkflowDescription = (string) $workflow->description;
        $this->editingWorkflowGroup = trim((string) $workflow->category) ?: 'custom';
        $this->editingWorkflowSubcategory = trim((string) $workflow->subcategory);
        $this->editingWorkflowActive = (bool) $workflow->is_active;
        $this->editingWorkflowLocked = (bool) $workflow->is_edit_locked;
        $this->editingWorkflowIncluded = (bool) $workflow->is_included;
        $this->editingWorkflowEffectiveLocked = (bool) $workflow->is_edit_locked;
        $this->showEditWorkflowModal = true;
    }

    public function saveEditWorkflow(): void
    {
        $workflow = $this->editingWorkflowId
            ? Workflow::query()->with('includedByWorkflows')->find($this->editingWorkflowId)
            : null;

        if (! $workflow) {
            return;
        }

        $validated = $this->validate([
            'editingWorkflowName' => ['required', 'string', 'max:160'],
            'editingWorkflowDescription' => ['nullable', 'string', 'max:1000'],
            'editingWorkflowGroup' => ['required', 'string', 'max:80'],
            'editingWorkflowSubcategory' => ['nullable', 'string', 'max:80'],
            'editingWorkflowActive' => ['boolean'],
            'editingWorkflowLocked' => ['boolean'],
        ]);

        if ($workflow->is_included) {
            session()->flash('error', 'Dieser Workflow ist in anderen Workflows enthalten und kann dort erst nach Entfernen der Referenz entsperrt werden.');

            return;
        }

        if ($workflow->is_edit_locked) {
            if (! $validated['editingWorkflowLocked']) {
                $workflow->forceFill(['is_locked' => false])->save();
                $this->editingWorkflowEffectiveLocked = false;
                $this->showEditWorkflowModal = false;
                session()->flash('success', 'Workflow wurde entsperrt.');
            }

            return;
        }

        $group = $this->normalizeGroup($validated['editingWorkflowGroup']);
        $subcategory = $this->normalizeSubcategory($validated['editingWorkflowSubcategory'] ?? '');

        $workflow->forceFill([
            'name' => trim($validated['editingWorkflowName']),
            'description' => trim((string) ($validated['editingWorkflowDescription'] ?? '')),
            'category' => $group,
            'subcategory' => $subcategory,
            'is_active' => (bool) $validated['editingWorkflowActive'],
            'is_locked' => (bool) $validated['editingWorkflowLocked'],
        ])->save();

        $this->activeGroup = $group;
        $this->activeSubcategory = $subcategory ?: 'all';
        $this->showEditWorkflowModal = false;

        session()->flash('success', 'Workflow wurde gespeichert.');
    }

    public function deleteWorkflow(int $workflowId): void
    {
        $workflow = Workflow::query()->with('includedByWorkflows')->find($workflowId);

        if (! $workflow) {
            return;
        }

        if ($workflow->is_edit_locked) {
            session()->flash('error', 'Gesperrte Workflows koennen nicht geloescht werden. '.$workflow->lock_reason);

            return;
        }

        $workflow->delete();

        session()->flash('success', 'Workflow wurde geloescht. Du kannst ihn jetzt per Seeder neu erzeugen.');
    }

    public function duplicateWorkflow(int $workflowId): void
    {
        $source = Workflow::query()
            ->with(['steps' => fn ($query) => $query->ordered()])
            ->find($workflowId);

        if (! $source) {
            return;
        }

        $duplicate = DB::transaction(function () use ($source): Workflow {
            $name = $this->uniqueDuplicateName($source->name);
            $settings = is_array($source->settings_json) ? $source->settings_json : [];

            $duplicate = Workflow::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'description' => (string) $source->description,
                'category' => trim((string) $source->category) ?: 'custom',
                'subcategory' => trim((string) $source->subcategory) ?: null,
                'is_active' => (bool) $source->is_active,
                'is_locked' => false,
                'trigger_type' => trim((string) $source->trigger_type) ?: 'manual',
                'settings_json' => array_replace($settings, [
                    'seeded' => false,
                    'duplicated_from_workflow_id' => $source->id,
                    'duplicated_from_workflow_slug' => $source->slug,
                ]),
            ]);

            foreach ($source->steps as $step) {
                if (! $step instanceof WorkflowStep) {
                    continue;
                }

                $duplicate->steps()->create([
                    'name' => $step->name,
                    'type' => $step->type,
                    'action_key' => $step->action_key,
                    'position' => (int) $step->position,
                    'is_enabled' => (bool) $step->is_enabled,
                    'config_json' => is_array($step->config_json) ? $step->config_json : [],
                    'retry_attempts' => max(0, (int) $step->retry_attempts),
                    'wait_after_seconds' => max(0, (int) $step->wait_after_seconds),
                ]);
            }

            return $duplicate;
        });

        $this->activeGroup = trim((string) $duplicate->category) ?: 'custom';
        $this->activeSubcategory = trim((string) $duplicate->subcategory) ?: 'all';

        session()->flash('success', 'Workflow wurde als "'.$duplicate->name.'" dupliziert.');
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

    protected function uniqueDuplicateName(string $name): string
    {
        $name = trim($name) ?: 'Workflow';
        $base = $name;
        $start = 1;

        if (preg_match('/^(.*\S)\s+(\d{2,})$/', $name, $matches)) {
            $base = trim($matches[1]);
            $start = max(1, (int) $matches[2] + 1);
        }

        $base = trim($base) ?: 'Workflow';

        for ($index = $start; $index < 1000; $index++) {
            $suffix = sprintf('%02d', $index);
            $candidate = Str::limit($base, 160 - strlen($suffix) - 1, '').' '.$suffix;

            if (! Workflow::query()->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        return Str::limit($base, 150, '').' '.Str::lower(Str::random(8));
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

    protected function normalizeSubcategory(string $subcategory): ?string
    {
        $subcategory = Str::slug($subcategory, '_');

        return $subcategory !== '' ? $subcategory : null;
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

    protected function visibleWorkflowQuery()
    {
        return Workflow::query()
            ->when($this->activeGroup !== 'all', fn ($query) => $query->where('category', $this->activeGroup))
            ->when(
                $this->activeSubcategory !== 'all',
                fn ($query) => $query->where('subcategory', $this->activeSubcategory),
            );
    }
}
