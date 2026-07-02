<?php

namespace App\Livewire\Admin\ClientController;

use App\Models\NetworkNode;
use App\Models\Setting;
use App\Services\ClientController\ClientControllerReleaseService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Throwable;

class UpdateSettings extends Component
{
    public string $githubRepository = '';

    public string $manifestUrl = '';

    public string $updaterPublicKey = '';

    public int $checkIntervalMinutes = 15;

    public ?array $latestRelease = null;

    public ?string $releaseError = null;

    public function mount(ClientControllerReleaseService $releases): void
    {
        $settings = $releases->settings();
        $this->githubRepository = $settings['github_repository'];
        $this->manifestUrl = $settings['manifest_url'];
        $this->updaterPublicKey = $settings['updater_public_key'];
        $this->checkIntervalMinutes = $settings['check_interval_minutes'];
        $this->loadRelease($releases);
    }

    public function save(ClientControllerReleaseService $releases): void
    {
        $validated = $this->validate([
            'githubRepository' => ['required', 'regex:/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', 'max:255'],
            'manifestUrl' => ['required', 'url:https', 'max:2048'],
            'updaterPublicKey' => ['required', 'string', 'max:10000'],
            'checkIntervalMinutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        Setting::setValue('client_controller', 'updates', [
            'github_repository' => trim($validated['githubRepository']),
            'manifest_url' => trim($validated['manifestUrl']),
            'updater_public_key' => trim($validated['updaterPublicKey']),
            'check_interval_minutes' => (int) $validated['checkIntervalMinutes'],
        ]);

        session()->flash('success', 'ClientController-Update-Einstellungen wurden gespeichert.');
        $this->loadRelease($releases, true);
    }

    public function checkRelease(ClientControllerReleaseService $releases): void
    {
        $this->loadRelease($releases, true);
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

    public function queueAllOutdated(ClientControllerReleaseService $releases): void
    {
        $queued = 0;
        $failed = [];

        foreach (NetworkNode::query()->where('status', 'active')->get() as $node) {
            if (! $this->latestRelease || ! $releases->updateAvailable($node->version, $this->latestRelease['version'])) {
                continue;
            }

            try {
                $releases->queueUpdate($node, Auth::user()?->email);
                $queued++;
            } catch (Throwable $exception) {
                $failed[] = $node->name.': '.$exception->getMessage();
            }
        }

        if ($failed !== []) {
            $this->addError('bulkUpdate', implode(' | ', $failed));
        }

        session()->flash('success', $queued.' Updateauftrag/Updateaufträge wurden explizit eingeplant.');
    }

    public function render()
    {
        return view('livewire.admin.client-controller.update-settings', [
            'outdatedCount' => $this->latestRelease
                ? NetworkNode::query()->get()->filter(fn (NetworkNode $node): bool => app(ClientControllerReleaseService::class)->updateAvailable($node->version, $this->latestRelease['version']))->count()
                : 0,
        ]);
    }
}
