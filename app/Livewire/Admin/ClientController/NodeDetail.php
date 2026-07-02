<?php

namespace App\Livewire\Admin\ClientController;

use App\Models\NetworkNode;
use App\Services\ClientController\ClientControllerReleaseService;
use App\Services\ClientController\NetworkJobDispatcher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;

class NodeDetail extends Component
{
    public NetworkNode $node;

    public string $name = '';

    public string $status = 'active';

    public bool $allowServerRebind = true;

    public ?string $currentServerDomain = null;

    public ?array $latestRelease = null;

    public ?string $releaseError = null;

    public function mount(NetworkNode $node, ClientControllerReleaseService $releases): void
    {
        $this->node = $node;
        $this->fillForm();

        try {
            $this->latestRelease = $releases->latestRelease();
        } catch (Throwable $exception) {
            $this->releaseError = $exception->getMessage();
        }
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:active,paused,disabled'],
            'allowServerRebind' => ['boolean'],
            'currentServerDomain' => ['nullable', 'url', 'max:2048'],
        ]);

        $this->node->update([
            'name' => $validated['name'],
            'status' => $validated['status'],
            'allow_server_rebind' => $validated['allowServerRebind'],
            'current_server_domain' => $validated['currentServerDomain'],
        ]);

        session()->flash('success', 'Node-Einstellungen wurden gespeichert.');
    }

    public function queueCommand(string $command, NetworkJobDispatcher $dispatcher): void
    {
        $commands = [
            'ping' => 'Verbindungstest',
            'node_diagnostics' => 'Diagnose',
            'node_outbox_list' => 'Outbox abrufen',
            'node_outbox_clear' => 'Outbox leeren',
            'node_discover_devices' => 'Geräte suchen',
            'node_sync' => 'Vollständige Synchronisierung',
        ];

        if (! isset($commands[$command])) {
            $this->addError('command', 'Unbekannter Node-Befehl.');

            return;
        }

        $existingJob = $this->node->jobs()
            ->where('type', $command)
            ->whereIn('status', ['pending', 'dispatched'])
            ->latest('id')
            ->first();

        if ($existingJob) {
            session()->flash('info', $commands[$command].' ist bereits in der Warteschlange.');

            return;
        }

        $job = $dispatcher->dispatch(
            node: $this->node,
            type: $command,
            payload: [
                'command' => $command,
                'requested_at' => now()->toIso8601String(),
            ],
            requestedBy: Auth::user()?->email,
            expiresAt: now()->addMinutes(15),
        );

        session()->flash('success', $commands[$command].' wurde als Job '.$job->job_uuid.' eingeplant.');
    }

    public function regenerateApiKey(): void
    {
        $this->node->update([
            'api_key' => Str::random(60),
            'node_secret' => Str::random(60),
            'is_online' => false,
        ]);

        session()->flash('success', 'Der API-Key wurde erneuert. Der Autopilot registriert den Node beim nächsten 401 automatisch neu.');
    }

    public function queueUpdate(ClientControllerReleaseService $releases): void
    {
        try {
            $job = $releases->queueUpdate($this->node, Auth::user()?->email);
            session()->flash('success', 'Updateauftrag '.$job->job_uuid.' wurde eingeplant.');
        } catch (Throwable $exception) {
            $this->addError('update', $exception->getMessage());
        }
    }

    public function cancelJob(int $jobId): void
    {
        $job = $this->node->jobs()->whereKey($jobId)->firstOrFail();

        if (in_array($job->status, ['pending', 'dispatched'], true)) {
            $job->update([
                'status' => 'cancelled',
                'completed_at' => now(),
            ]);
        }
    }

    public function render(ClientControllerReleaseService $releases)
    {
        NetworkNode::expireStale();
        $this->node->refresh();

        return view('livewire.admin.client-controller.node-detail', [
            'devices' => $this->node->devices()->orderByDesc('last_seen_at')->get(),
            'jobs' => $this->node->jobs()->latest('id')->limit(30)->get(),
            'heartbeats' => $this->node->heartbeats()->latest('received_at')->limit(20)->get(),
            'nodeIsOnline' => $this->node->isAvailable(),
            'updateAvailable' => $this->latestRelease && $releases->updateAvailable($this->node->version, $this->latestRelease['version']),
        ])->layout('layouts.master');
    }

    protected function fillForm(): void
    {
        $this->name = (string) $this->node->name;
        $this->status = (string) ($this->node->status ?: 'active');
        $this->allowServerRebind = (bool) $this->node->allow_server_rebind;
        $this->currentServerDomain = $this->node->current_server_domain;
    }
}
