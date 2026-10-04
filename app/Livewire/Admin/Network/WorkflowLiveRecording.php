<?php

namespace App\Livewire\Admin\Network;

use App\Models\User;
use App\Models\WorkflowRecording;
use App\Services\Workflows\WorkflowRecordingService;
use App\Services\Workflows\WorkflowTaskCatalog;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class WorkflowLiveRecording extends Component
{
    public string $name = '';

    public string $url = '';

    #[Locked]
    public ?int $recordingId = null;

    #[Locked]
    public string $state = 'draft';

    #[Locked]
    public array $events = [];

    #[Locked]
    public ?string $selectedEventId = null;

    #[Locked]
    public string $commandUrl = '';

    #[Locked]
    public string $stateUrl = '';

    #[Locked]
    public string $frameUrl = '';

    public string $eventTitle = '';

    public string $eventSelector = '';

    public string $eventValueSource = 'literal';

    public string $eventValue = '';

    public string $eventWorkflowVariable = '';

    #[Locked]
    public string $error = '';

    public function mount(): void
    {
        $this->user();
        if ($id = filter_var(request()->query('recording'), FILTER_VALIDATE_INT)) {
            $this->sync($this->service()->owned($this->user(), $id));
        }
    }

    public function startRecording(): void
    {
        $this->user();
        $this->validate(['name' => 'required|string|max:160', 'url' => 'required|string|max:2048']);
        $this->perform(function (): WorkflowRecording {
            if ($this->recordingId !== null && in_array($this->state, ['recording', 'paused', 'starting'], true)) {
                throw ValidationException::withMessages(['url' => 'Die laufende Aufnahme zuerst beenden.']);
            }
            $draft = $this->service()->create($this->user(), $this->name, $this->url);
            // Preserve the owned draft ID even if browser startup fails.
            $this->sync($draft);

            return $this->service()->start($this->user(), $draft->id);
        });
    }

    public function pauseRecording(): void
    {
        $this->perform(fn () => $this->service()->pause($this->user(), $this->recordingKey()));
    }

    public function resumeRecording(): void
    {
        $this->perform(fn () => $this->service()->resume($this->user(), $this->recordingKey()));
    }

    public function stopRecording(): void
    {
        $this->perform(fn () => $this->service()->stop($this->user(), $this->recordingKey()));
    }

    public function refreshRecording(): void
    {
        if ($this->recordingId !== null) {
            $this->perform(fn () => $this->service()->poll($this->user(), $this->recordingKey()));
        }
    }

    public function selectEvent(string $eventId): void
    {
        $recording = $this->service()->owned($this->user(), $this->recordingKey());
        $this->sync($recording);
        $event = collect($this->events)->firstWhere('id', $eventId);
        abort_unless(is_array($event), 404);
        $this->selectedEventId = $eventId;
        $this->eventTitle = (string) ($event['label'] ?? '');
        $this->eventSelector = (string) ($event['selector'] ?? '');
        $this->eventValueSource = (string) ($event['value_source'] ?? 'literal');
        $this->eventValue = ($event['sensitive'] ?? false) && $this->eventValueSource === 'literal'
            ? '' : (string) ($event['value'] ?? '');
        $this->eventWorkflowVariable = (string) ($event['workflow_variable'] ?? '');
    }

    public function updateEvent(): void
    {
        if ($this->selectedEventId === null) {
            return;
        }
        $this->perform(fn () => $this->service()->updateEvent($this->user(), $this->recordingKey(), $this->selectedEventId, [
            'label' => $this->eventTitle,
            'selector' => $this->eventSelector,
            'value_source' => $this->eventValueSource,
            'value' => $this->eventValue,
            'workflow_variable' => $this->eventWorkflowVariable,
        ]));
    }

    public function removeEvent(string $eventId): void
    {
        $this->perform(fn () => $this->service()->removeEvent($this->user(), $this->recordingKey(), $eventId));
        if ($this->selectedEventId === $eventId) {
            $this->selectedEventId = null;
            $this->reset(['eventTitle', 'eventSelector', 'eventValue', 'eventWorkflowVariable']);
        }
    }

    public function moveEvent(string $eventId, int $direction): void
    {
        $this->perform(fn () => $this->service()->moveEvent($this->user(), $this->recordingKey(), $eventId, $direction));
    }

    public function saveWorkflow(): void
    {
        $this->user();
        $this->validate(['name' => 'required|string|max:160']);
        try {
            $workflow = $this->service()->save($this->user(), $this->recordingKey(), $this->name);
            session()->flash('success', 'Live Aufnahme wurde als neuer, inaktiver Workflow gespeichert.');
            $this->redirectRoute('network.workflows.manage', ['workflow' => $workflow->id]);
        } catch (ValidationException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->error = 'Workflow konnte nicht gespeichert werden. Aufnahme und Pflichtfelder prüfen.';
        }
    }

    public function render()
    {
        $this->user();
        if ($this->recordingId !== null) {
            $this->sync($this->service()->owned($this->user(), $this->recordingKey()));
        }

        return view('livewire.admin.network.workflow-live-recording', [
            'valueOptions' => app(WorkflowTaskCatalog::class)->inputFillDataValueGroups(),
            'initialBrowserState' => $this->recordingId === null ? []
                : $this->service()->publicState($this->service()->owned($this->user(), $this->recordingKey())),
        ])->layout('layouts.master');
    }

    private function sync(WorkflowRecording $recording): void
    {
        $public = $this->service()->publicState($recording);
        $this->recordingId = $recording->id;
        $this->state = (string) ($public['state'] ?? $recording->status);
        $this->events = (array) ($public['events'] ?? []);
        if ($this->name === '') {
            $this->name = $recording->name;
        }
        if ($this->url === '') {
            $this->url = (string) ($public['url'] ?? $recording->start_url);
        }
        $this->commandUrl = route('network.live-recording.command', $recording);
        $this->stateUrl = route('network.live-recording.state', $recording);
        $this->frameUrl = route('network.live-recording.frame', $recording);
    }

    private function perform(callable $action): void
    {
        $this->user();
        $this->error = '';
        try {
            $this->sync($action());
        } catch (ValidationException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->recordingId !== null) {
                $this->sync($this->service()->owned($this->user(), $this->recordingKey()));
            }
            $this->error = 'Aufnahmeaktion fehlgeschlagen. Browser-Konfiguration, Verbindung und Aufnahmezustand prüfen.';
        }
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isAdmin() && $user->isActive(), 403);

        return $user;
    }

    private function recordingKey(): int
    {
        abort_if($this->recordingId === null, 404);

        return $this->recordingId;
    }

    private function service(): WorkflowRecordingService
    {
        return app(WorkflowRecordingService::class);
    }
}
