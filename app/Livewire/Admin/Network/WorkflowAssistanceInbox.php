<?php

namespace App\Livewire\Admin\Network;

use App\Models\User;
use App\Models\WorkflowAssistanceRequest;
use App\Services\Workflows\WorkflowAssistanceService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class WorkflowAssistanceInbox extends Component
{
    use WithPagination;

    public string $selectedRequestUuid = '';

    public string $filter = 'open';

    public string $search = '';

    public string $browserText = '';

    public string $resolutionNote = '';

    public ?string $notice = null;

    public string $noticeType = 'info';

    protected array $queryString = [
        'filter' => ['except' => 'open'],
        'search' => ['except' => ''],
    ];

    public function mount(?string $requestUuid = null): void
    {
        $this->assertOperator();
        $this->selectedRequestUuid = trim((string) $requestUuid);
    }

    public function updatedFilter(): void
    {
        if (! in_array($this->filter, ['open', 'mine', 'history'], true)) {
            $this->filter = 'open';
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function selectRequest(string $uuid): void
    {
        $this->assertOperator();
        $exists = WorkflowAssistanceRequest::query()->where('request_uuid', $uuid)->exists();
        if (! $exists) {
            return;
        }

        $this->redirectRoute('network.workflow-assistance', ['requestUuid' => $uuid], navigate: true);
    }

    public function claim(): void
    {
        $this->perform(function (WorkflowAssistanceRequest $request, User $operator): string {
            app(WorkflowAssistanceService::class)->claim($request, $operator);

            return 'Die Aufgabe ist jetzt dir zugewiesen.';
        }, 'success');
    }

    public function release(): void
    {
        $this->perform(function (WorkflowAssistanceRequest $request, User $operator): string {
            app(WorkflowAssistanceService::class)->release($request, $operator);

            return 'Die Aufgabe ist wieder fuer alle Administratoren offen.';
        });
    }

    public function refreshBrowser(): void
    {
        $this->runProbe('refresh');
    }

    public function clickBrowser(float $xRatio, float $yRatio, string $capturedAt): void
    {
        $this->runProbe('click', [
            'x_ratio' => $xRatio,
            'y_ratio' => $yRatio,
            'captured_at' => $capturedAt,
        ]);
    }

    public function typeBrowserText(): void
    {
        $this->validate(['browserText' => ['required', 'string', 'max:500']]);
        $text = $this->browserText;
        $this->browserText = '';
        $this->runProbe('type', ['text' => $text]);
    }

    public function sendBrowserKey(string $key): void
    {
        if (! in_array($key, ['Enter', 'Tab'], true)) {
            throw new DomainException('Diese Taste ist in der Admin-Browseransicht nicht erlaubt.');
        }

        $this->runProbe('key', ['key' => $key]);
    }

    public function verifyCaptcha(): void
    {
        $this->runProbe('verify');
    }

    public function resolveAndResume(): void
    {
        $this->validate(['resolutionNote' => ['nullable', 'string', 'max:2000']]);
        $this->perform(function (WorkflowAssistanceRequest $request, User $operator): string {
            $response = app(WorkflowAssistanceService::class)->resolveAndResume(
                $request,
                $operator,
                $this->resolutionNote,
            );
            $this->resolutionNote = '';

            return (string) ($response['message'] ?? 'Der Workflow wurde fortgesetzt.');
        }, 'success');
    }

    public function render(): View
    {
        $operator = $this->assertOperator();
        $requests = $this->requestQuery($operator)->paginate(24);
        $selected = $this->selectedRequest();
        $service = app(WorkflowAssistanceService::class);
        $snapshot = $selected ? $service->latestBrowserSnapshot($selected) : [];
        $verification = $selected ? $service->verificationState($selected) : ['status' => 'none', 'message' => ''];

        return view('livewire.admin.network.workflow-assistance-inbox', [
            'requests' => $requests,
            'selected' => $selected,
            'snapshot' => $snapshot,
            'verification' => $verification,
            'openCount' => WorkflowAssistanceRequest::query()->open()->count(),
            'mineCount' => WorkflowAssistanceRequest::query()
                ->open()
                ->where('assigned_to_user_id', $operator->getKey())
                ->count(),
            'operator' => $operator,
        ])->layout('layouts.master');
    }

    protected function requestQuery(User $operator): Builder
    {
        return WorkflowAssistanceRequest::query()
            ->with(['workflow', 'assignedTo'])
            ->when($this->filter === 'open', fn (Builder $query): Builder => $query->open())
            ->when($this->filter === 'mine', fn (Builder $query): Builder => $query
                ->open()
                ->where('assigned_to_user_id', $operator->getKey()))
            ->when($this->filter === 'history', fn (Builder $query): Builder => $query
                ->whereNotIn('status', WorkflowAssistanceRequest::OPEN_STATUSES))
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $search = '%'.addcslashes(trim($this->search), '%_\\').'%';
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('title', 'like', $search)
                        ->orWhere('current_url', 'like', $search)
                        ->orWhereHas('workflow', fn (Builder $workflow): Builder => $workflow->where('name', 'like', $search));
                });
            })
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'claimed' THEN 1 ELSE 2 END")
            ->orderByDesc('requested_at');
    }

    protected function selectedRequest(): ?WorkflowAssistanceRequest
    {
        $query = WorkflowAssistanceRequest::query()->with([
            'workflow',
            'workflowRun.stepRuns.workflowStep',
            'workflowStep',
            'workflowStepRun',
            'assignedTo',
            'resolvedBy',
            'events.actor',
        ]);

        if ($this->selectedRequestUuid !== '') {
            return $query->where('request_uuid', $this->selectedRequestUuid)->first();
        }

        return $query->open()->orderByDesc('requested_at')->first();
    }

    protected function runProbe(string $action, array $payload = []): void
    {
        $this->perform(function (WorkflowAssistanceRequest $request, User $operator) use ($action, $payload): string {
            $response = app(WorkflowAssistanceService::class)->runBrowserProbe($request, $operator, $action, $payload);

            return (string) ($response['message'] ?? 'Die Browseraktion wurde gestartet.');
        });
    }

    protected function perform(callable $action, string $successType = 'info'): void
    {
        $this->resetErrorBag();
        $this->notice = null;

        try {
            $request = $this->selectedRequest();
            if (! $request) {
                throw new DomainException('Bitte zuerst eine Admin-Aufgabe auswaehlen.');
            }

            $this->notice = (string) $action($request, $this->assertOperator());
            $this->noticeType = $successType;
        } catch (DomainException $exception) {
            $this->addError('assistance', $exception->getMessage());
            $this->noticeType = 'error';
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('assistance', 'Die Aktion konnte nicht sicher ausgefuehrt werden.');
            $this->noticeType = 'error';
        }
    }

    protected function assertOperator(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin() && $user->isActive(), 403);

        return $user;
    }
}
