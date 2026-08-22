<?php

namespace App\Livewire\Admin\Network;

use App\Models\WorkflowPortalProfile;
use App\Services\Workflows\WorkflowPortalProfileService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class PortalProfiles extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = 'all';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        if (! in_array($this->status, ['all', 'active', 'disabled', 'expired', 'conflicts'], true)) {
            $this->status = 'all';
        }

        $this->resetPage();
    }

    public function approveProfile(int $profileId, WorkflowPortalProfileService $profiles): void
    {
        $profiles->approve(WorkflowPortalProfile::query()->findOrFail($profileId), auth()->id());
        session()->flash('success', 'Portal-Profil wurde freigegeben und aktiviert.');
    }

    public function rollbackProfile(int $profileId, WorkflowPortalProfileService $profiles): void
    {
        $profiles->rollback(
            WorkflowPortalProfile::query()->findOrFail($profileId),
            auth()->id(),
            'Manueller Rollback im Portal-Profil-Dashboard.',
        );
        session()->flash('success', 'Portal-Profil wurde zurückgerollt. Der Copilot verwendet diesen Selector nicht mehr.');
    }

    public function render()
    {
        $conflicts = $this->conflictPairs();
        $query = WorkflowPortalProfile::query()
            ->with(['approvedByUser:id,name', 'disabledByUser:id,name'])
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($this->search)).'%';
                $query->where(function (Builder $search) use ($term): void {
                    $search->where('domain', 'like', $term)
                        ->orWhere('role', 'like', $term)
                        ->orWhere('selector', 'like', $term);
                });
            })
            ->when($this->status === 'active', fn (Builder $query): Builder => $query
                ->where('is_active', true)
                ->where('is_approved', true)
                ->where(fn (Builder $usable): Builder => $usable
                    ->where('miss_count', '<', 2)
                    ->orWhereColumn('miss_count', '<=', 'hit_count')))
            ->when($this->status === 'disabled', fn (Builder $query): Builder => $query->where('is_active', false))
            ->when($this->status === 'expired', fn (Builder $query): Builder => $query
                ->where('miss_count', '>=', 2)
                ->whereColumn('miss_count', '>', 'hit_count'))
            ->when($this->status === 'conflicts', function (Builder $query) use ($conflicts): void {
                $query->where(function (Builder $pairs) use ($conflicts): void {
                    foreach ($conflicts as $conflict) {
                        $pairs->orWhere(function (Builder $pair) use ($conflict): void {
                            $pair->where('domain', $conflict['domain'])->where('role', $conflict['role']);
                        });
                    }

                    if ($conflicts === []) {
                        $pairs->whereRaw('1 = 0');
                    }
                });
            })
            ->orderBy('domain')
            ->orderBy('role')
            ->orderByDesc('is_active')
            ->orderByDesc('hit_count')
            ->paginate(20);

        $all = WorkflowPortalProfile::query();

        return view('livewire.admin.network.portal-profiles', [
            'profiles' => $query,
            'conflictPairs' => $conflicts,
            'summary' => [
                'total' => (clone $all)->count(),
                'active' => (clone $all)->where('is_active', true)->where('is_approved', true)->count(),
                'disabled' => (clone $all)->where('is_active', false)->count(),
                'expired' => (clone $all)->where('miss_count', '>=', 2)->whereColumn('miss_count', '>', 'hit_count')->count(),
                'conflicts' => count($conflicts),
            ],
        ])->layout('layouts.master');
    }

    /** @return list<array{domain:string, role:string, selectors:int}> */
    private function conflictPairs(): array
    {
        return WorkflowPortalProfile::query()
            ->select(['domain', 'role'])
            ->selectRaw('COUNT(*) as selectors')
            ->where('is_active', true)
            ->where('is_approved', true)
            ->where(fn (Builder $query): Builder => $query
                ->where('miss_count', '<', 2)
                ->orWhereColumn('miss_count', '<=', 'hit_count'))
            ->groupBy('domain', 'role')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('domain')
            ->orderBy('role')
            ->get()
            ->map(fn (WorkflowPortalProfile $profile): array => [
                'domain' => $profile->domain,
                'role' => $profile->role,
                'selectors' => (int) $profile->getAttribute('selectors'),
            ])
            ->all();
    }
}
