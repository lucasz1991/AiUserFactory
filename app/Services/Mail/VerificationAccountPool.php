<?php

namespace App\Services\Mail;

use App\Models\PersonEmailAccount;
use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Verifizierungs-Postfaecher kommen aus den E-Mail-Konten der Personen. Infrage
 * kommen nur Anbieter, fuer die ein Workflow zum Auslesen des Codes bereitliegt.
 * Jeder Registrierungslauf bekommt beim Start ein freies Konto einer anderen
 * Person fest zugeordnet; bindet der Anbieter die Adresse an das neue Konto (oder
 * lehnt sie als schon benutzt ab), wird das am Konto vermerkt.
 */
class VerificationAccountPool
{
    /** Anbieter mit Workflow zum Auslesen des Verifizierungscodes. */
    public const SUPPORTED_PROVIDERS = ['gmx'];

    public const FINAL_RUN_STATUSES = ['completed', 'failed', 'cancelled', 'timed_out', 'lost'];

    /**
     * Konten mit unterstuetztem Anbieter und gespeichertem Passwort.
     */
    public function candidatesQuery(?int $excludePersonId = null): Builder
    {
        return PersonEmailAccount::query()
            ->whereIn('provider', self::SUPPORTED_PROVIDERS)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereNotNull('password_encrypted')
            ->where('password_encrypted', '!=', '')
            ->when($excludePersonId, fn (Builder $query) => $query->where('person_id', '!=', $excludePersonId))
            ->orderBy('id');
    }

    /**
     * Freies Konto fuer eine neue Registrierung: unverbraucht, nicht von der
     * registrierenden Person und von keinem anderen unbeendeten Lauf belegt.
     */
    public function available(?int $excludePersonId = null, ?int $exceptRunId = null): ?PersonEmailAccount
    {
        $pinned = $this->pinnedAccountIds($exceptRunId);

        return $this->candidatesQuery($excludePersonId)
            ->whereNull('verification_consumed_at')
            ->when($pinned !== [], fn (Builder $query) => $query->whereNotIn('id', $pinned))
            ->first();
    }

    /**
     * Postfach zum Lesen fuer Laeufe ohne feste Zuordnung (z. B. Webmail-Workflow
     * einzeln im Studio): bevorzugt ein freies, sonst irgendein passendes Konto.
     */
    public function forReading(?int $excludePersonId = null): ?PersonEmailAccount
    {
        return $this->available($excludePersonId)
            ?? $this->candidatesQuery($excludePersonId)->first();
    }

    /**
     * Das dem Lauf zugeordnete Konto, sonst ein Konto zum Lesen.
     */
    public function forRun(WorkflowRun $run, ?int $personId = null): ?PersonEmailAccount
    {
        $accountId = (int) data_get($run->context_json, 'verification_mailbox_guard.account_id', 0);

        if ($accountId > 0) {
            return PersonEmailAccount::query()->find($accountId);
        }

        return $this->forReading($personId ?: ((int) data_get($run->context_json, 'person_id', 0) ?: null));
    }

    /**
     * Konten, die unbeendete Laeufe fest zugeordnet haben.
     *
     * @return list<int>
     */
    public function pinnedAccountIds(?int $exceptRunId = null): array
    {
        return WorkflowRun::query()
            ->whereNotIn('status', self::FINAL_RUN_STATUSES)
            ->whereNotNull('context_json->verification_mailbox_guard->account_id')
            ->when($exceptRunId, fn (Builder $query) => $query->where('id', '!=', $exceptRunId))
            ->get(['id', 'context_json'])
            ->map(fn (WorkflowRun $run): int => (int) data_get($run->context_json, 'verification_mailbox_guard.account_id', 0))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Vermerkt den Verbrauch am Konto, hoechstens einmal pro Lauf. Mit
     * Zeilensperre, damit parallele Laeufe keinen Eintrag verlieren.
     */
    public function recordConsumption(int $accountId, string $reason, ?int $workflowRunId = null): bool
    {
        return DB::transaction(function () use ($accountId, $reason, $workflowRunId): bool {
            $account = PersonEmailAccount::query()->lockForUpdate()->find($accountId);

            if (! $account) {
                return false;
            }

            $entries = $account->verificationConsumptionEntries();
            $already = collect($entries)->contains(fn (array $entry): bool => $workflowRunId !== null
                ? (int) ($entry['workflow_run_id'] ?? 0) === $workflowRunId
                : ($entry['workflow_run_id'] ?? null) === null && (string) ($entry['reason'] ?? '') === trim($reason));

            if ($already) {
                return false;
            }

            $entries[] = [
                'reason' => trim($reason),
                'workflow_run_id' => $workflowRunId,
                'consumed_at' => now()->toIso8601String(),
            ];
            $account->forceFill([
                'verification_consumption' => array_slice($entries, -50),
                'verification_consumed_at' => $account->verification_consumed_at ?? now(),
            ])->save();

            return true;
        });
    }

    /**
     * Gibt ein Konto wieder frei (wenn der Anbieter die Adresse nachweislich
     * wieder annimmt). Die Historie bleibt fuer die Nachvollziehbarkeit erhalten.
     */
    public function release(int $accountId): bool
    {
        $account = PersonEmailAccount::query()->find($accountId);

        if (! $account || ! $account->isVerificationConsumed()) {
            return false;
        }

        $account->forceFill(['verification_consumed_at' => null])->save();
        Log::info('Verifizierungs-Postfach wurde manuell freigegeben.', [
            'person_email_account_id' => $account->id,
            'workflow_run_ids' => collect($account->verificationConsumptionEntries())->pluck('workflow_run_id')->filter()->values()->all(),
        ]);

        return true;
    }

    /**
     * Ueberblick fuer die Einstellungen: alle Konten mit unterstuetztem Anbieter.
     *
     * @return Collection<int, array{id: int, email: string, provider: string, person_id: int, person_name: string, has_password: bool, consumed: bool, pinned: bool, events: list<array>}>
     */
    public function overview(): Collection
    {
        $pinned = $this->pinnedAccountIds();

        return PersonEmailAccount::query()
            ->with('person')
            ->whereIn('provider', self::SUPPORTED_PROVIDERS)
            ->orderBy('id')
            ->get()
            ->map(fn (PersonEmailAccount $account): array => [
                'id' => (int) $account->id,
                'email' => (string) $account->email,
                'provider' => (string) $account->provider,
                'person_id' => (int) $account->person_id,
                'person_name' => (string) ($account->person?->display_name ?? ('Person #'.$account->person_id)),
                'person_key' => (string) ($account->person?->profile_key ?? $account->person_id),
                'has_password' => $account->hasStoredPassword(),
                'consumed' => $account->isVerificationConsumed(),
                'pinned' => in_array((int) $account->id, $pinned, true),
                'events' => array_map(fn (array $entry): array => [
                    ...$entry,
                    'consumed_at_label' => $this->formatDate($entry['consumed_at'] ?? null),
                ], array_reverse($account->verificationConsumptionEntries())),
            ]);
    }

    public function availableCount(?int $excludePersonId = null): int
    {
        $pinned = $this->pinnedAccountIds();

        return $this->candidatesQuery($excludePersonId)
            ->whereNull('verification_consumed_at')
            ->when($pinned !== [], fn (Builder $query) => $query->whereNotIn('id', $pinned))
            ->count();
    }

    /**
     * Rohdaten des Kontos im Format des frueheren Einstellungs-Postfachs; der
     * Laufzeit-Kontext entschluesselt daraus Passwort und Sitzungen.
     */
    public function mailboxData(?PersonEmailAccount $account): array
    {
        if (! $account) {
            return [];
        }

        return [
            'id' => (int) $account->id,
            'person_id' => (int) $account->person_id,
            'enabled' => true,
            'email' => trim((string) $account->email),
            'provider' => strtolower(trim((string) $account->provider)) ?: 'gmx',
            'username' => trim((string) $account->username),
            'password_encrypted' => $account->password_encrypted,
            'webmail_url' => trim((string) $account->webmail_url),
            'webmail_session' => is_array($account->webmail_session) ? $account->webmail_session : null,
            'browser_sessions' => is_array($account->verification_browser_sessions) ? $account->verification_browser_sessions : [],
        ];
    }

    /**
     * "Lauf #641 am 23.11.2026, Lauf #639 am 16.11.2026" (neueste zuerst, hoechstens drei).
     */
    public function describeConsumption(array $entries, int $limit = 3): string
    {
        $parts = collect(array_reverse($entries))
            ->unique(fn (array $entry): string => ($entry['workflow_run_id'] ?? null) !== null
                ? 'run:'.$entry['workflow_run_id']
                : 'at:'.($entry['consumed_at'] ?? ''))
            ->map(function (array $entry): string {
                $label = ($entry['workflow_run_id'] ?? null) ? 'Lauf #'.$entry['workflow_run_id'] : 'ohne Laufnummer';

                try {
                    if (trim((string) ($entry['consumed_at'] ?? '')) !== '') {
                        $label .= ' am '.Carbon::parse((string) $entry['consumed_at'])->timezone(config('app.timezone'))->format('d.m.Y');
                    }
                } catch (\Throwable) {
                    // Ein unlesbares Datum bleibt weg.
                }

                return $label;
            })
            ->values();

        $more = $parts->count() - $limit;

        return $parts->take($limit)->implode(', ').($more > 0 ? ' und '.$more.' weitere' : '');
    }

    protected function formatDate(mixed $value): ?string
    {
        try {
            return trim((string) $value) !== ''
                ? Carbon::parse((string) $value)->timezone(config('app.timezone'))->format('d.m.Y H:i')
                : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
