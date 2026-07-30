<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Diagnose-Ereignis fuer den Echtzeit-Transport (Spur X).
 *
 * Es traegt bewusst **keine** fachlichen Daten. Zweck ist ausschliesslich der
 * Nachweis, dass die Kette Anwendung -> Reverb -> Apache-Proxy -> Browser
 * geschlossen ist. Der Kanal ist privat und an genau einen Benutzer gebunden;
 * ein oeffentlicher Diagnosekanal waere in Produktion eine unnoetige
 * Informationsflaeche.
 *
 * `ShouldBroadcastNow` statt `ShouldBroadcast` ist hier Absicht: ein
 * Diagnose-Ereignis soll den **Transport** pruefen, nicht die Queue. Mit
 * `ShouldBroadcast` landet es als `BroadcastEvent`-Job in der Datenbank und
 * kommt ohne laufenden Worker nie an — der Test wuerde dann eine kaputte
 * Verbindung melden, obwohl nur der Worker fehlt. Fachliche Ereignisse
 * sollten umgekehrt sehr wohl ueber die Queue laufen.
 *
 * Ausloesen: `php artisan realtime:ping {userId}`
 */
class RealtimePing implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $sentAt,
        public readonly string $note = '',
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'realtime.ping';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'sent_at' => $this->sentAt,
            'note' => $this->note,
        ];
    }
}
