<?php

namespace App\Services\Workflows;

use App\Exceptions\VerificationMailboxUnavailableException;
use App\Models\PersonEmailAccount;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\Mail\VerificationAccountPool;
use Illuminate\Support\Facades\DB;

/**
 * Proton bindet die Verifizierungs-Adresse an das neue Konto; eine verbrauchte
 * Adresse fuehrt nach der Code-Eingabe zu "Email address already used". Ein
 * Workflow, der das Postfach verbrauchen kann, bekommt deshalb beim Start ein
 * freies E-Mail-Konto einer anderen Person fest zugeordnet und laeuft nur damit
 * weiter. Workflows, die ein Postfach nur lesen, sind davon nicht betroffen.
 */
class VerificationMailboxStartGuard
{
    public const FINAL_RUN_STATUSES = VerificationAccountPool::FINAL_RUN_STATUSES;

    public function __construct(protected VerificationAccountPool $pool) {}

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
     * Ein Workflow braucht ein unverbrauchtes Postfach, wenn er selbst oder ein darin
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
     * Ordnet dem neuen Lauf ein freies Verifizierungs-Postfach zu.
     *
     * @return array{account_id: int, email: string, person_id: int}|null null, wenn der Lauf keine feste Zuordnung bekommt
     *
     * @throws VerificationMailboxUnavailableException
     */
    public function ensureAvailableFor(Workflow $workflow, array $context = []): ?array
    {
        if (! $this->requiresUnusedAddress($workflow)) {
            return null;
        }

        $personId = (int) ($context['person_id'] ?? $context['personId'] ?? 0) ?: null;
        $account = $this->pool->available($personId);

        if ($account) {
            return ['account_id' => (int) $account->id, 'email' => (string) $account->email, 'person_id' => (int) $account->person_id];
        }

        // Eine einzelne Studio-Task registriert in aller Regel nicht; sie darf auch
        // ohne freies Postfach laufen und liest dann mit irgendeinem passenden Konto.
        if (filter_var($context['studio_single_task'] ?? false, FILTER_VALIDATE_BOOL)) {
            return null;
        }

        throw VerificationMailboxUnavailableException::noFreeAccount(
            $this->pool->candidatesQuery($personId)->count(),
            VerificationAccountPool::SUPPORTED_PROVIDERS,
        );
    }

    /**
     * Darf ein Lauf mit fest zugeordnetem Postfach weitermachen (Fortsetzen oder
     * naechste Liste)? Nein, wenn das Konto geloescht oder inzwischen von einem
     * anderen Lauf verbraucht wurde. Hat der Lauf es selbst verbraucht (Konto
     * angelegt oder abgelehnt), laufen seine restlichen Listen weiter.
     */
    public function continuationBlockReason(WorkflowRun $run, string $situation = 'resume'): ?string
    {
        $context = is_array($run->context_json) ? $run->context_json : [];
        $accountId = (int) data_get($context, 'verification_mailbox_guard.account_id', 0);

        if ($accountId <= 0 || trim((string) data_get($context, 'verification_mailbox_consumed.email', '')) !== '') {
            return null;
        }

        $email = trim((string) data_get($context, 'verification_mailbox_guard.email', ''));
        $account = PersonEmailAccount::query()->find($accountId);

        if (! $account) {
            return VerificationMailboxUnavailableException::forDeletedAccount($email, $situation)->getMessage();
        }

        if (! $account->isVerificationConsumed()) {
            return null;
        }

        $runId = (int) $run->getKey();
        $entries = $account->verificationConsumptionEntries();

        if (collect($entries)->contains(fn (array $entry): bool => (int) ($entry['workflow_run_id'] ?? 0) === $runId)) {
            return null;
        }

        return VerificationMailboxUnavailableException::forConsumedContinuation(
            (string) $account->email,
            $this->pool->describeConsumption($entries),
            $situation,
        )->getMessage();
    }

    public function resumeBlockReason(WorkflowRun $run): ?string
    {
        return $this->continuationBlockReason($run, 'resume');
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
