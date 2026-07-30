<?php

namespace App\Console\Commands;

use App\Events\RealtimePing;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Diagnosebefehl fuer den Echtzeit-Transport (Spur X).
 *
 * Prueft in einem Schritt, ob Broadcast-Treiber, Reverb-Server und
 * Zugangsdaten zusammenpassen. Ob der Browser das Ereignis auch *empfaengt*,
 * zeigt erst die Konsole im angemeldeten Fenster — dieser Befehl belegt die
 * Serverseite.
 */
class SendRealtimePing extends Command
{
    protected $signature = 'realtime:ping
                            {user? : Benutzer-ID oder E-Mail; ohne Angabe der erste aktive Benutzer}
                            {--note= : Freitext, der im Ereignis mitgesendet wird}';

    protected $description = 'Sendet ein Diagnose-Ereignis ueber den Echtzeit-Transport an einen Benutzer.';

    public function handle(): int
    {
        $driver = (string) config('broadcasting.default');

        if ($driver === 'log' || $driver === 'null') {
            $this->warn("Broadcast-Treiber ist '{$driver}' — das Ereignis erreicht keinen Browser.");
        }

        $user = $this->resolveUser();

        if (! $user) {
            $this->error('Kein passender Benutzer gefunden.');

            return self::FAILURE;
        }

        $sentAt = now()->toIso8601String();

        try {
            RealtimePing::dispatch(
                (int) $user->getKey(),
                $sentAt,
                (string) ($this->option('note') ?? ''),
            );
        } catch (Throwable $exception) {
            $this->error('Senden fehlgeschlagen: '.$exception::class.' — '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Gesendet an Benutzer %d (%s) ueber Treiber "%s", Kanal "private-App.Models.User.%d", Ereignis "realtime.ping", Zeit %s.',
            $user->getKey(),
            $user->email,
            $driver,
            $user->getKey(),
            $sentAt,
        ));
        $this->line('Im angemeldeten Browser mitlesen:');
        $this->line(sprintf(
            "  window.Echo.private('App.Models.User.%d').listen('.realtime.ping', e => console.log('ping', e))",
            $user->getKey(),
        ));

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $argument = $this->argument('user');

        if ($argument === null) {
            return User::query()->where('status', true)->orderBy('id')->first();
        }

        return is_numeric($argument)
            ? User::find((int) $argument)
            : User::where('email', $argument)->first();
    }
}
