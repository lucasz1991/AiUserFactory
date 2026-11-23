<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\PersonEmailAccount;
use App\Services\Mail\MailAccountRegistrationRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Einmaliger Umzug: Das frueher in den Einstellungen hinterlegte Verifizierungs-
 * Postfach wird ein E-Mail-Konto einer Person. Passwort und Sitzungen werden
 * verschluesselt uebernommen, die Verbrauchs-Historie bleibt am Konto erhalten.
 */
class MoveVerificationMailboxToPerson extends Command
{
    protected $signature = 'mail:move-verification-mailbox
        {person : ID, Profil-Key oder voller Name der Person}
        {--drop-reserve : Reserve-Adressen aus den Einstellungen verwerfen (sonst bricht der Umzug ab, wenn welche hinterlegt sind)}
        {--dry-run : Nur anzeigen, was passieren wuerde}';

    protected $description = 'Zieht das Verifizierungs-Postfach aus den Einstellungen als E-Mail-Konto zu einer Person um.';

    public function handle(MailAccountRegistrationRunner $runner): int
    {
        $legacy = $runner->legacyVerificationMailbox();
        $mailbox = $legacy['mailbox'];
        $email = trim((string) ($mailbox['email'] ?? ''));

        if ($email === '') {
            $this->warn('In den Einstellungen ist kein Verifizierungs-Postfach hinterlegt; nichts zu tun.');

            return self::SUCCESS;
        }

        $person = $this->findPerson((string) $this->argument('person'));

        if (! $person) {
            return self::FAILURE;
        }

        $reserve = array_values(array_filter($legacy['reserve'], fn (array $entry): bool => trim((string) ($entry['email'] ?? '')) !== ''));

        if ($reserve !== [] && ! $this->option('drop-reserve')) {
            $this->error('In den Einstellungen sind noch '.count($reserve).' Reserve-Adresse(n) hinterlegt ('
                .implode(', ', array_column($reserve, 'email')).'). Bitte zuerst bei Personen als E-Mail-Konten anlegen '
                .'oder mit --drop-reserve verwerfen.');

            return self::FAILURE;
        }

        $entries = collect($legacy['consumed'])
            ->filter(fn (array $entry): bool => strtolower(trim((string) ($entry['email'] ?? ''))) === strtolower($email))
            ->map(fn (array $entry): array => [
                'reason' => (string) ($entry['reason'] ?? ''),
                'workflow_run_id' => is_numeric($entry['workflow_run_id'] ?? null) ? (int) $entry['workflow_run_id'] : null,
                'consumed_at' => $entry['consumed_at'] ?? null,
            ])
            ->unique(fn (array $entry): string => ($entry['workflow_run_id'] ?? null) !== null ? 'run:'.$entry['workflow_run_id'] : 'at:'.$entry['consumed_at'])
            ->values()
            ->all();
        $existing = $person->emailAccounts()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

        $this->line('Postfach: '.$email.' ('.($mailbox['provider'] ?? 'gmx').')');
        $this->line('Person: #'.$person->id.' '.$person->display_name.' ('.$person->profile_key.')');
        $this->line($existing ? 'Vorhandenes E-Mail-Konto #'.$existing->id.' wird ergaenzt.' : 'Neues E-Mail-Konto wird angelegt.');
        $this->line($entries === []
            ? 'Verbrauch: keiner vermerkt.'
            : 'Verbrauch: '.collect($entries)->map(fn (array $entry): string => ($entry['reason'] ?: 'verbraucht').($entry['workflow_run_id'] ? ' (Lauf #'.$entry['workflow_run_id'].')' : ''))->implode(', '));

        if ($this->option('dry-run')) {
            $this->info('Probelauf: nichts geaendert.');

            return self::SUCCESS;
        }

        $account = DB::transaction(function () use ($person, $existing, $mailbox, $email, $entries): PersonEmailAccount {
            $account = $existing ?? new PersonEmailAccount([
                'person_id' => $person->id,
                'sort_order' => (int) $person->emailAccounts()->max('sort_order') + 1,
                'is_primary' => ! $person->emailAccounts()->exists(),
            ]);
            $previousEntries = $existing ? $existing->verificationConsumptionEntries() : [];
            $mergedEntries = collect([...$previousEntries, ...$entries])
                ->unique(fn (array $entry): string => ($entry['workflow_run_id'] ?? null) !== null ? 'run:'.$entry['workflow_run_id'] : 'at:'.($entry['consumed_at'] ?? ''))
                ->sortBy(fn (array $entry): string => (string) ($entry['consumed_at'] ?? ''))
                ->values()
                ->all();
            $consumedAt = collect($mergedEntries)->pluck('consumed_at')->filter()->sort()->first();

            $account->forceFill([
                'email' => $email,
                'provider' => in_array($mailbox['provider'] ?? '', ['proton', 'gmx', 'custom'], true) ? $mailbox['provider'] : 'gmx',
                'username' => trim((string) ($mailbox['username'] ?? '')) ?: ($account->username ?: $email),
                'password_encrypted' => ($mailbox['password_encrypted'] ?? null) ?: $account->password_encrypted,
                'webmail_url' => trim((string) ($mailbox['webmail_url'] ?? '')) ?: $account->webmail_url,
                'webmail_session' => is_array($mailbox['webmail_session'] ?? null) ? $mailbox['webmail_session'] : $account->webmail_session,
                'verification_browser_sessions' => is_array($mailbox['browser_sessions'] ?? null) && $mailbox['browser_sessions'] !== []
                    ? $mailbox['browser_sessions']
                    : $account->verification_browser_sessions,
                'verification_consumption' => $mergedEntries !== [] ? $mergedEntries : null,
                'verification_consumed_at' => $mergedEntries !== []
                    ? ($account->verification_consumed_at ?? ($consumedAt ? Carbon::parse($consumedAt)->timezone(config('app.timezone')) : now()))
                    : $account->verification_consumed_at,
            ])->save();

            // Wie im Personen-Formular: Der primaere Account wird nach persons.metadata
            // gespiegelt, damit Automatisierung und Workflow-Leser ihn finden.
            if ($account->is_primary) {
                $metadata = is_array($person->metadata) ? $person->metadata : [];
                $metadata['email_account'] = $account->toMetadataAccount();
                $person->forceFill(['metadata' => $metadata, 'person_email' => $account->email])->save();
            }

            return $account;
        });

        $runner->clearLegacyVerificationMailbox();

        $this->info('E-Mail-Konto #'.$account->id.' bei '.$person->display_name.' gespeichert'
            .($account->isVerificationConsumed() ? ' (als Verifizierungs-Postfach verbraucht)' : '')
            .'. Das Postfach wurde aus den Einstellungen entfernt.');

        return self::SUCCESS;
    }

    protected function findPerson(string $needle): ?Person
    {
        $needle = trim($needle);

        if ($needle === '') {
            $this->error('Bitte eine Person angeben.');

            return null;
        }

        if (ctype_digit($needle) && ($person = Person::query()->find((int) $needle))) {
            return $person;
        }

        if ($person = Person::query()->where('profile_key', $needle)->first()) {
            return $person;
        }

        $matches = Person::query()
            ->get()
            ->filter(fn (Person $person): bool => strcasecmp(trim($person->display_name), $needle) === 0)
            ->values();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        $this->error($matches->isEmpty()
            ? 'Keine Person "'.$needle.'" gefunden (ID, Profil-Key oder voller Name).'
            : 'Mehrere Personen heissen "'.$needle.'" (IDs '.$matches->pluck('id')->implode(', ').'); bitte die ID angeben.');

        return null;
    }
}
