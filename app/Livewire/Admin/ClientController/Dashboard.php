<?php

namespace App\Livewire\Admin\ClientController;

use App\Models\Device;
use App\Models\NetworkJob;
use App\Models\NetworkNode;
use App\Models\NetworkTarget;
use App\Services\ClientController\ClientControllerReleaseService;
use Livewire\Component;
use Throwable;

class Dashboard extends Component
{
    public ?array $latestRelease = null;

    public ?string $releaseError = null;

    public function mount(ClientControllerReleaseService $releases): void
    {
        $this->loadRelease($releases);
    }

    public function refreshRelease(ClientControllerReleaseService $releases): void
    {
        $this->loadRelease($releases, true);
    }

    public function render(ClientControllerReleaseService $releases)
    {
        NetworkNode::expireStale();
        $nodes = NetworkNode::query()->latest('last_seen_at')->limit(8)->get();
        $outdated = $this->latestRelease
            ? NetworkNode::query()->get()->filter(fn (NetworkNode $node): bool => $releases->updateAvailable($node->version, $this->latestRelease['version']))->count()
            : 0;

        return view('livewire.admin.client-controller.dashboard', [
            'stats' => [
                'nodes_total' => NetworkNode::query()->count(),
                'nodes_online' => NetworkNode::query()->available()->count(),
                'nodes_outdated' => $outdated,
                'devices_total' => Device::query()->count(),
                'jobs_pending' => NetworkJob::query()->whereIn('status', ['pending', 'dispatched'])->count(),
                'targets_total' => NetworkTarget::query()->count(),
            ],
            'nodes' => $nodes,
        ])->layout('layouts.master');
    }

    protected function loadRelease(ClientControllerReleaseService $releases, bool $refresh = false): void
    {
        try {
            $this->latestRelease = $releases->latestRelease($refresh);
            $this->releaseError = null;
        } catch (Throwable $exception) {
            $this->latestRelease = null;
            $this->releaseError = $exception->getMessage();
        }
    }
}
