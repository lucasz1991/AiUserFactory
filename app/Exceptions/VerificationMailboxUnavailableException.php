<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Eine Registrierung braucht eine noch unbenutzte Verifizierungs-Adresse. Die
 * Meldung richtet sich an den Admin; sie ist kein Systemfehler und wird deshalb
 * nicht an das Error-Reporting gemeldet.
 */
class VerificationMailboxUnavailableException extends RuntimeException implements ShouldntReport
{
    public static function forStart(string $email, string $consumedRuns): self
    {
        if (trim($email) === '') {
            return new self(
                'Registrierung nicht gestartet: Es ist kein Verifizierungs-Postfach eingetragen und keine Reserve-Adresse vorhanden. '
                .'Bitte unter Einstellungen > Mail-Registrierung eine GMX-Adresse mit Passwort eintragen.'
            );
        }

        return new self(
            'Registrierung nicht gestartet: Die Verifizierungs-Adresse '.$email.' wurde von Proton bereits abgelehnt'
            .($consumedRuns !== '' ? ' ('.$consumedRuns.')' : '')
            .', und es ist keine unverbrauchte Reserve-Adresse hinterlegt. '
            .'Bitte unter Einstellungen > Mail-Registrierung eine neue GMX-Adresse eintragen.'
        );
    }

    /**
     * @param  list<int>  $runIds
     */
    public static function stillInUse(string $email, array $runIds): self
    {
        $runs = implode(', ', array_map(fn (int $id): string => '#'.$id, array_slice($runIds, 0, 3)))
            .(count($runIds) > 3 ? ' und '.(count($runIds) - 3).' weitere' : '');

        return new self(
            'Registrierung nicht gestartet: Die Verifizierungs-Adresse '.$email.' wurde gerade von Lauf '.$runs
            .' verbraucht, und dieser Lauf ist noch nicht beendet. Gewechselt wird an seinem Ende; bitte danach erneut starten '
            .'oder den Lauf beenden.'
        );
    }

    public static function promotionInsideTransaction(string $email, string $next): self
    {
        return new self(
            'Registrierung nicht gestartet: Die Verifizierungs-Adresse '.$email.' ist verbraucht; die Reserve-Adresse '.$next
            .' konnte hier nicht automatisch aktiviert werden. Bitte die Reserve unter Einstellungen > Mail-Registrierung '
            .'aktivieren oder die Registrierung direkt starten.'
        );
    }

    public static function forDirectRegistration(string $email, string $next): self
    {
        return new self(
            'Registrierung nicht gestartet: Die Verifizierungs-Adresse '.$email.' ist verbraucht. Bitte zuerst die Reserve-Adresse '
            .$next.' unter Einstellungen > Mail-Registrierung aktivieren.'
        );
    }

    public static function lockBusy(): self
    {
        return new self('Das Verifizierungs-Postfach wird gerade aktualisiert. Bitte in einigen Sekunden erneut starten.');
    }

    public static function forContinuation(string $startedWith, string $active, string $situation = 'resume'): self
    {
        return new self(
            self::continuationLead($situation).'Dieser Lauf wurde mit der Verifizierungs-Adresse '.$startedWith.' gestartet; inzwischen ist '
            .($active !== '' ? $active : 'keine Adresse').' aktiv. Proton schickt den Code an die alte Adresse, '
            .'deshalb bitte den Lauf neu starten.'
        );
    }

    public static function forConsumedContinuation(string $email, string $consumedRuns, string $situation = 'resume'): self
    {
        return new self(
            self::continuationLead($situation).'Dieser Lauf wurde mit der Verifizierungs-Adresse '.$email
            .' gestartet; sie wurde inzwischen von einem anderen Lauf verbraucht'
            .($consumedRuns !== '' ? ' ('.$consumedRuns.')' : '')
            .'. Bitte den Lauf neu starten; dann rueckt eine Reserve-Adresse nach.'
        );
    }

    /**
     * @deprecated Alias fuer forContinuation (Fortsetzen eines pausierten Laufs).
     */
    public static function forResume(string $startedWith, string $active): self
    {
        return self::forContinuation($startedWith, $active, 'resume');
    }

    protected static function continuationLead(string $situation): string
    {
        return $situation === 'list' ? 'Naechste Liste nicht gestartet: ' : '';
    }
}
