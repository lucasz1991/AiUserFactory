<?php

namespace App\Livewire\Admin\ClientController;

use App\Models\NetworkNode;
use App\Services\ClientController\ClientControllerReleaseService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class NodeIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $name = '';

    public string $currentServerDomain = '';

    public bool $allowServerRebind = true;

    public ?array $latestRelease = null;

    public ?string $releaseError = null;

    protected $queryString = ['search' => ['except' => '']];

    public function mount(ClientControllerReleaseService $releases): void
    {
        $this->loadRelease($releases);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function createNode(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'currentServerDomain' => ['nullable', 'url', 'max:2048'],
            'allowServerRebind' => ['boolean'],
        ]);

        NetworkNode::query()->create([
            'name' => $validated['name'],
            'node_uuid' => (string) Str::uuid(),
            'api_key' => Str::random(60),
            'node_secret' => Str::random(60),
            'current_server_domain' => $validated['currentServerDomain'] ?: null,
            'last_successful_server_domain' => $validated['currentServerDomain'] ?: null,
            'allow_server_rebind' => $validated['allowServerRebind'],
            'is_online' => false,
        ]);

        $this->reset(['name', 'currentServerDomain']);
        $this->allowServerRebind = true;
        session()->flash('success', 'Node wurde angelegt.');
    }

    public function regenerateApiKey(int $nodeId): void
    {
        NetworkNode::query()->findOrFail($nodeId)->update([
            'api_key' => Str::random(60),
            'node_secret' => Str::random(60),
            'is_online' => false,
        ]);

        session()->flash('success', 'Node API-Key wurde neu erzeugt.');
    }

    public function deleteNode(int $nodeId): void
    {
        NetworkNode::query()->findOrFail($nodeId)->delete();
        session()->flash('success', 'Node wurde gelöscht.');
    }

    public function queueUpdate(int $nodeId, ClientControllerReleaseService $releases): void
    {
        try {
            $job = $releases->queueUpdate(NetworkNode::query()->findOrFail($nodeId), Auth::user()?->email);
            session()->flash('success', 'Updateauftrag '.$job->job_uuid.' wurde für die nächste Node-Kommunikation eingeplant.');
        } catch (Throwable $exception) {
            $this->addError('update', $exception->getMessage());
        }
    }

    public function refreshRelease(ClientControllerReleaseService $releases): void
    {
        $this->loadRelease($releases, true);
    }

    public function render(ClientControllerReleaseService $releases)
    {
        NetworkNode::expireStale();
        $search = trim($this->search);
        $nodes = NetworkNode::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('node_uuid', 'like', '%'.$search.'%')
                        ->orWhere('public_ip', 'like', '%'.$search.'%')
                        ->orWhere('os', 'like', '%'.$search.'%');
                });
            })
            ->latest('id')
            ->paginate(20);

        return view('livewire.admin.client-controller.node-index', [
            'nodes' => $nodes,
            'releaseService' => $releases,
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
