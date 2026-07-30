<?php

namespace App\Support\Push;

use App\Models\User;
use App\Notifications\FactoryWebPushNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Einstiegspunkt fuer alle Web-Push-Ausloeser.
 *
 * RailTime bindet an dieser Stelle seine eigenen Domaenen (Nachrichten, Chat,
 * Anrufe) fest ein. FollowFlow hat diese Modelle nicht, deshalb bleibt die
 * Klasse hier bewusst generisch: sie kapselt das *Verfahren* — Vorschautext
 * kuerzen, Zustellung einplanen, Fehler beim Einplanen schlucken und
 * protokollieren statt den ausloesenden Vorgang mitzureissen.
 *
 * Aufruf aus beliebigem Anwendungscode:
 *
 *     app(PushDelivery::class)->send(
 *         $user,
 *         PushCategory::Workflows,
 *         'workflow-run:'.$run->getKey(),
 *         'Workflow fertig',
 *         $run->workflow?->name ?? '',
 *         'netzwerk/workflows/'.$run->workflow_id,
 *     );
 *
 * Die URL ist bewusst **relativ** zum Manifest-Scope. Der Service Worker loest
 * sie gegen seinen Registrierungs-Scope auf und verwirft alles, was aus dem
 * Scope herausfuehrt — ein absoluter Fremdlink kann so nicht angeklickt werden.
 */
class PushDelivery
{
    public function send(
        User $recipient,
        PushCategory $category,
        string $notificationId,
        string $title,
        string $body = '',
        string $url = '',
        ?int $ttlOverride = null,
        ?int $badgeCount = null,
    ): void {
        $this->notify($recipient, new FactoryWebPushNotification(
            notificationId: $notificationId,
            title: $this->previewText($title, 70) ?: config('app.name', 'FollowFlow'),
            body: $this->previewText($body),
            url: $url,
            category: $category,
            ttlOverride: $ttlOverride,
            badgeCount: $badgeCount,
        ));
    }

    /**
     * @param  iterable<User>  $recipients
     */
    public function sendToMany(
        iterable $recipients,
        PushCategory $category,
        string $notificationId,
        string $title,
        string $body = '',
        string $url = '',
        ?int $ttlOverride = null,
    ): void {
        foreach ($recipients as $recipient) {
            $this->send(
                $recipient,
                $category,
                $notificationId,
                $title,
                $body,
                $url,
                $ttlOverride,
            );
        }
    }

    protected function notify(User $recipient, FactoryWebPushNotification $notification): void
    {
        try {
            $recipient->notify($notification);
        } catch (Throwable $exception) {
            // Ein nicht einplanbarer Push darf den ausloesenden Vorgang
            // (Workflow-Lauf, Copilot-Checkpoint) niemals abbrechen.
            Log::notice('Web-Push konnte nicht eingeplant werden.', [
                'notification_id' => $notification->notificationId,
                'user_id' => $recipient->getKey(),
                'error_class' => $exception::class,
            ]);
        }
    }

    protected function previewText(?string $value, int $limit = 160): string
    {
        return Str::of((string) $value)
            ->stripTags()
            ->squish()
            ->limit($limit)
            ->toString();
    }
}
