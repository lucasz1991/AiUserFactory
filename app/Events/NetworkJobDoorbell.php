<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NetworkJobDoorbell implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $channel,
        public readonly string $nodeUuid,
        public readonly string $signaledAt,
        public readonly string $reason,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channel);
    }

    public function broadcastAs(): string
    {
        return 'client-controller.job-poll';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return [
            'node_uuid' => $this->nodeUuid,
            'signaled_at' => $this->signaledAt,
            'reason' => $this->reason,
        ];
    }
}
