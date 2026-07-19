<?php

namespace App\Services\Workflows;

use App\Models\User;
use App\Models\WorkflowStudioSession;
use DomainException;

class WorkflowStudioControlService
{
    public const MODE_INTERACTIVE = 'interactive';

    public const MODE_AUTONOMOUS = 'autonomous';

    public function choose(WorkflowStudioSession $session, string $mode, ?User $user): WorkflowStudioSession
    {
        $mode = $this->normalize($mode);
        $session->refresh();

        if ($session->mode_locked_at) {
            throw new DomainException('Der Testmodus ist fuer diese Sitzung bereits festgelegt. Starte eine neue Testsitzung, um den Modus zu wechseln.');
        }

        if ($mode === self::MODE_AUTONOMOUS && ! $user?->isAdmin() && $session->permission_mode === 'unrestricted') {
            throw new DomainException('Uneingeschraenkter autonomer Zugriff ist nur fuer Admins verfuegbar.');
        }

        $session->forceFill([
            'mode' => $mode === self::MODE_AUTONOMOUS ? 'autonomous' : 'manual',
            'control_owner' => $mode === self::MODE_AUTONOMOUS ? 'copilot' : 'user',
            'last_activity_at' => now(),
        ])->save();

        return $session->fresh() ?? $session;
    }

    public function lock(WorkflowStudioSession $session, string $mode, ?User $user): WorkflowStudioSession
    {
        $mode = $this->normalize($mode);
        $session->refresh();

        if ($session->mode_locked_at) {
            $current = $session->mode === 'autonomous' ? self::MODE_AUTONOMOUS : self::MODE_INTERACTIVE;

            if ($current === $mode) {
                return $session;
            }

            throw new DomainException('Der Testmodus ist fuer diese Sitzung bereits festgelegt.');
        }

        $session = $this->choose($session, $mode, $user);
        $session->forceFill(['mode_locked_at' => now(), 'last_activity_at' => now()])->save();

        app(WorkflowStudioSessionService::class)->appendEvent(
            $session,
            'control.locked',
            $mode === self::MODE_AUTONOMOUS
                ? 'Die Sitzung wird exklusiv durch den Copiloten gesteuert.'
                : 'Die Sitzung wird interaktiv durch den Benutzer gesteuert.',
            ['mode' => $mode, 'control_owner' => $session->control_owner],
        );

        return $session->fresh() ?? $session;
    }

    public function assertUserControl(WorkflowStudioSession $session): void
    {
        $session->refresh();

        if ($session->mode === 'autonomous' && $session->mode_locked_at) {
            throw new DomainException('Der autonome Testmodus ist fest aktiv. Laufsteuerung und Workflow-Aenderungen gehoeren bis zum Sitzungsende dem Copiloten.');
        }
    }

    public function isAutonomous(WorkflowStudioSession $session): bool
    {
        return $session->mode === 'autonomous';
    }

    public function normalize(string $mode): string
    {
        return in_array($mode, [self::MODE_AUTONOMOUS, 'autonomous'], true)
            ? self::MODE_AUTONOMOUS
            : self::MODE_INTERACTIVE;
    }
}
