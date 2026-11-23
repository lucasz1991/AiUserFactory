<?php

namespace App\Services\Workflows;

use App\Exceptions\VerificationMailboxUnavailableException;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\Mail\MailAccountRegistrationRunner;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Proton bindet die Verifizierungs-Adresse an das neue Konto; eine verbrauchte
 * Adresse fuehrt nach der Code-Eingabe zu "Email address already used". Ein
 * Workflow, der das Postfach verbrauchen kann, darf deshalb nur mit einer noch
 * unbenutzten Adresse starten und weiterlaufen. Workflows, die das Postfach nur
 * lesen (Webmail oeffnen, Mails suchen), sind davon nicht betroffen.
 */
class VerificationMailboxStartGuard
{
    public const FINAL_RUN_STATUSES = ['completed', 'failed', 'cancelled', 'timed_out', 'lost'];

    public function __construct(protected MailAccountRegistrationRunner $mail) {}

    /**
     * Offene Transaktionen der Anwendung. Die Test-Hilfe RefreshDatabase legt eine
     * eigene Huelle an, die der Transaktions-Manager dabei nicht mitzaehlt.
     */
    public static function insideApplicationTransaction(): bool
    {
        try {
            return app('db.transactions')->callbackApplicableTransactions()->isNotEmpty();
        } catch (\Throwable) {
            return DB::transactionLevel() > 0;
        }
    }

    public static function routeConsumesMailbox(mixed $route): bool
    {
        return is_array($route)
            && filter_var($route['consume_verification_mailbox'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * Ein Workflow braucht eine unbenutzte Adresse, wenn er selbst oder ein darin
     * eingebetteter Workflow eine Route mit consume_verification_mailbox besitzt
     * oder in seinen Einstellungen requires_unused_verification_mailbox setzt. Nur
     * aktive Listen zaehlen; Zyklen werden ueber die besuchten IDs abgefangen.
     */
    public function requiresUnusedAddress(Workflow $workflow): bool
    {
        $visited = [];
        $pending = [$workflow];

        while ($pending !== []) {
            $current = array_shift($pending);
            $currentId = (int) $current->getKey();

            if (isset($visited[$currentId])) {
                continue;
            }

            $visited[$currentId] = true;

            if (filter_var(data_get($current->settings_json, 'requires_unused_verification_mailbox', false), FILTER_VALIDATE_BOOL)) {
                return true;
            }

            foreach ($current->enabledSteps()->get() as $step) {
                if ($this->stepConsumesMailbox($step)) {
                    return true;
                }

                foreach ($step->task_cards as $task) {
                    $childId = $this->embeddedWorkflowId($task);

                    if ($childId > 0 && ! isset($visited[$childId])) {
                        $child = Workflow::query()->find($childId);

                        if ($child) {
                            $pending[] = $child;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Prueft vor dem Start und laesst bei Bedarf eine Reserve-Adresse nachruecken.
     *
     * @return array{email: string, promoted_from: ?string}|null null, wenn der Lauf keinen Postfach-Stempel bekommt
     *
     * @throws VerificationMailboxUnavailableException
     */
    public function ensureAvailableFor(Workflow $workflow, array $context = []): ?array
    {
        if (! $this->requiresUnusedAddress($workflow)) {
            return null;
        }

        $state = $this->mail->verificationMailboxAvailability();

        if ($state['status'] === 'ok') {
            return ['email' => $state['active'], 'promoted_from' => null];
        }

        // Eine einzelne Studio-Task registriert in aller Regel nicht; sie darf mit
        // dem aktuellen Postfach laufen, ohne Stempel und ohne Wechsel.
        if (filter_var($context['studio_single_task'] ?? false, FILTER_VALIDATE_BOOL)) {
            return null;
        }

        if ($state['status'] === 'promotable') {
            // Ein Lauf, der die Adresse gerade verbraucht hat, nutzt das Postfach noch
            // fuer seine weiteren Listen (z. B. Zugangsdaten, Mails loeschen); ein
            // Wechsel jetzt tauschte es unter ihm aus.
            $consumers = $this->pendingConsumerRunIds($state['active']);

            if ($consumers !== []) {
                throw VerificationMailboxUnavailableException::stillInUse($state['active'], $consumers);
            }

            // Die Postfach-Sperre ist ein Cache-Lock und endet vor dem Commit einer
            // umgebenden Transaktion; ein Wechsel darin koennte ueberschrieben oder
            // zurueckgerollt werden.
            if (self::insideApplicationTransaction()) {
                throw VerificationMailboxUnavailableException::promotionInsideTransaction($state['active'], (string) $state['next']);
            }

            try {
                $state = $this->mail->ensureUsableVerificationMailbox();
            } catch (LockTimeoutException) {
                throw VerificationMailboxUnavailableException::lockBusy();
            }
        }

        if ($state['status'] === 'unavailable') {
            throw VerificationMailboxUnavailableException::forStart($state['active'], $this->mail->describeConsumedEntries($state['consumed']));
        }

        if ($state['status'] === 'promoted') {
            Log::info('Verbrauchte Verifizierungs-Adresse vor dem Start durch eine Reserve-Adresse ersetzt.', [
                'workflow_id' => $workflow->getKey(),
                'previous' => $state['previous'],
                'active' => $state['active'],
            ]);

            try {
                app(WorkflowTaskRunner::class)->forgetVerificationMailboxBrowserProfile();
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return ['email' => $state['active'], 'promoted_from' => $state['previous'] ?? null];
    }

    /**
     * Darf ein gestempelter Lauf weitermachen (Fortsetzen oder naechste Liste)?
     * Nein, wenn seine Adresse nicht mehr aktiv ist oder ein anderer Lauf sie
     * inzwischen verbraucht hat: Der Code laege im falschen Postfach oder Proton
     * antwortete "Email address already used". Hat der Lauf seine Adresse selbst
     * verbraucht (Konto angelegt), laeuft er mit seinen restlichen Listen weiter.
     */
    public function continuationBlockReason(WorkflowRun $run, string $situation = 'resume'): ?string
    {
        $context = is_array($run->context_json) ? $run->context_json : [];
        $startedWith = trim((string) data_get($context, 'verification_mailbox_guard.email', ''));

        if ($startedWith === '' || trim((string) data_get($context, 'verification_mailbox_consumed.email', '')) !== '') {
            return null;
        }

        $settings = $this->mail->settings();
        $consumed = $this->mail->consumedEntriesFor($startedWith, $settings);
        $runId = (int) $run->getKey();

        if (collect($consumed)->contains(fn (array $entry): bool => (int) ($entry['workflow_run_id'] ?? 0) === $runId)) {
            return null;
        }

        $active = trim((string) ($settings['verification_mailbox']['email'] ?? ''));

        if (strtolower($startedWith) !== strtolower($active)) {
            return VerificationMailboxUnavailableException::forContinuation($startedWith, $active, $situation)->getMessage();
        }

        if ($consumed !== []) {
            return VerificationMailboxUnavailableException::forConsumedContinuation(
                $startedWith,
                $this->mail->describeConsumedEntries($consumed),
                $situation,
            )->getMessage();
        }

        return null;
    }

    public function resumeBlockReason(WorkflowRun $run): ?string
    {
        return $this->continuationBlockReason($run, 'resume');
    }

    /**
     * Noch nicht beendete Laeufe, die diese Adresse verbraucht haben.
     *
     * @return list<int>
     */
    public function pendingConsumerRunIds(string $email, ?int $exceptRunId = null): array
    {
        $runIds = collect($this->mail->consumedEntriesFor($email))
            ->pluck('workflow_run_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->reject(fn (int $id): bool => $exceptRunId !== null && $id === $exceptRunId)
            ->unique()
            ->values()
            ->all();

        if ($runIds === []) {
            return [];
        }

        return WorkflowRun::query()
            ->whereIn('id', $runIds)
            ->whereNotIn('status', self::FINAL_RUN_STATUSES)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    protected function stepConsumesMailbox(WorkflowStep $step): bool
    {
        foreach ($step->routes as $route) {
            if (self::routeConsumesMailbox($route)) {
                return true;
            }
        }

        foreach ($step->task_cards as $task) {
            $routes = [$task['next'] ?? null, $task['on_error'] ?? null, $task['on_partial'] ?? null];

            foreach (is_array($task['status_routes'] ?? null) ? $task['status_routes'] : [] as $route) {
                $routes[] = $route;
            }

            foreach ($routes as $route) {
                if (self::routeConsumesMailbox($route)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function embeddedWorkflowId(array $task): int
    {
        if ((string) ($task['runner'] ?? '') === 'workflow') {
            return (int) ($task['workflow_id'] ?? 0);
        }

        $taskKey = trim((string) ($task['task_key'] ?? ''));

        return preg_match('/^workflow\.include\.(\d+)$/', $taskKey, $matches) ? (int) $matches[1] : 0;
    }
}
