<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Eine Registrierung braucht ein noch unverbrauchtes Verifizierungs-Postfach. Die
 * Meldung richtet sich an den Admin; sie ist kein Systemfehler und wird deshalb
 * nicht an das Error-Reporting gemeldet.
 */
class VerificationMailboxUnavailableException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  list<string>  $providers
     */
    public static function noFreeAccount(int $candidates, array $providers): self
    {
        $providerLabel = strtoupper(implode('/', $providers));

        return new self(
            'Registrierung nicht gestartet: Es gibt kein freies '.$providerLabel.'-Postfach als Verifizierungs-Adresse. '
            .($candidates > 0
                ? 'Alle '.$candidates.' '.$providerLabel.'-Konten anderer Personen sind bereits verbraucht oder von laufenden Registrierungen belegt. '
                : 'Bei keiner anderen Person ist ein '.$providerLabel.'-Konto mit Passwort hinterlegt. ')
            .'Bitte bei einer Person (nicht der zu registrierenden) ein weiteres '.$providerLabel.'-Konto unter E-Mail-Konten anlegen.'
        );
    }

    public static function forDeletedAccount(string $email, string $situation = 'resume'): self
    {
        return new self(
            self::continuationLead($situation).'Das diesem Lauf zugeordnete Verifizierungs-Postfach '
            .($email !== '' ? $email.' ' : '').'existiert nicht mehr. Bitte den Lauf neu starten.'
        );
    }

    public static function forConsumedContinuation(string $email, string $consumedRuns, string $situation = 'resume'): self
    {
        return new self(
            self::continuationLead($situation).'Dieser Lauf wurde mit dem Verifizierungs-Postfach '.$email
            .' gestartet; es wurde inzwischen von einem anderen Lauf verbraucht'
            .($consumedRuns !== '' ? ' ('.$consumedRuns.')' : '')
            .'. Bitte den Lauf neu starten; dann wird ein freies Postfach zugeordnet.'
        );
    }

    protected static function continuationLead(string $situation): string
    {
        return $situation === 'list' ? 'Naechste Liste nicht gestartet: ' : '';
    }
}
