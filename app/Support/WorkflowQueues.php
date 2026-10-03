<?php

namespace App\Support;

final class WorkflowQueues
{
    public const CONTROL = 'workflow-control';

    public const AI = 'workflow-ai';

    public const CONTROL_CONNECTION = 'database-workflow-control';

    public const AI_CONNECTION = 'database-workflow-ai';

    /** @return array<string, string> */
    public static function lanes(): array
    {
        return [
            'default' => 'database',
            self::CONTROL => self::CONTROL_CONNECTION,
            self::AI => self::AI_CONNECTION,
        ];
    }

    public static function reservationSeconds(string $queue): int
    {
        $connection = match ($queue) {
            self::CONTROL => self::CONTROL_CONNECTION,
            self::AI => self::AI_CONNECTION,
            default => 'database',
        };

        return (int) config('queue.connections.'.$connection.'.retry_after', 1860);
    }
}
