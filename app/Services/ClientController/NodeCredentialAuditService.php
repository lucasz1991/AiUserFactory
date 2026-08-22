<?php

namespace App\Services\ClientController;

use App\Models\NetworkNode;
use App\Models\NodeCredentialEvent;
use App\Models\User;
use Illuminate\Http\Request;

class NodeCredentialAuditService
{
    public function record(
        string $eventType,
        string $outcome,
        ?NetworkNode $node = null,
        ?User $actor = null,
        ?Request $request = null,
        ?string $nodeUuid = null,
        array $metadata = [],
    ): NodeCredentialEvent {
        return NodeCredentialEvent::query()->create([
            'network_node_id' => $node?->id,
            'actor_user_id' => $actor?->id,
            'node_uuid' => $node?->node_uuid ?: $nodeUuid,
            'event_type' => $eventType,
            'outcome' => $outcome,
            'ip_hash' => $request?->ip() ? hash('sha256', (string) $request->ip()) : null,
            'metadata_json' => $this->safeMetadata($metadata),
            'occurred_at' => now(),
        ]);
    }

    private function safeMetadata(array $metadata): array
    {
        return array_filter([
            'reason' => $this->shortString($metadata['reason'] ?? null),
            'label' => $this->shortString($metadata['label'] ?? null),
            'expires_at' => $this->shortString($metadata['expires_at'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function shortString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 191);
    }
}
