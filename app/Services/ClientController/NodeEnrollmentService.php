<?php

namespace App\Services\ClientController;

use App\Models\NetworkNode;
use App\Models\NodeEnrollmentToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NodeEnrollmentService
{
    public function __construct(
        private readonly NetworkNodeCredentialService $credentials,
        private readonly NodeCredentialAuditService $audit,
    ) {}

    public function issue(
        ?NetworkNode $node,
        ?User $actor,
        string $label = 'manual-enrollment',
        int $ttlMinutes = 15,
    ): string {
        $ttlMinutes = max(5, min(60, $ttlMinutes));
        $plainTextToken = 'ffe_'.Str::random(64);
        $expiresAt = now()->addMinutes($ttlMinutes);

        if ($node) {
            NodeEnrollmentToken::query()
                ->where('network_node_id', $node->id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }

        NodeEnrollmentToken::query()->create([
            'token_hash' => hash('sha256', $plainTextToken),
            'label' => mb_substr(trim($label), 0, 191),
            'network_node_id' => $node?->id,
            'created_by_user_id' => $actor?->id,
            'expires_at' => $expiresAt,
        ]);

        $this->audit->record(
            eventType: 'enrollment_token.issued',
            outcome: 'success',
            node: $node,
            actor: $actor,
            metadata: [
                'label' => $label,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        );

        return $plainTextToken;
    }

    /**
     * @return array{node: NetworkNode, api_key: string}
     */
    public function consume(string $plainTextToken, array $attributes, Request $request): array
    {
        $nodeUuid = trim((string) ($attributes['node_uuid'] ?? ''));
        $tokenHash = hash('sha256', trim($plainTextToken));

        $result = DB::transaction(function () use ($tokenHash, $nodeUuid, $attributes, $request): array {
            $token = NodeEnrollmentToken::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $token) {
                return ['error' => 'unknown_token', 'status' => 401];
            }

            $token->forceFill([
                'last_attempt_at' => now(),
                'attempt_count' => ((int) $token->attempt_count) + 1,
            ])->save();

            if (! $token->isUsable()) {
                return ['error' => 'unusable_token', 'status' => 401];
            }

            $existingNode = NetworkNode::query()
                ->where('node_uuid', $nodeUuid)
                ->lockForUpdate()
                ->first();

            if ($token->network_node_id !== null) {
                $node = NetworkNode::query()->lockForUpdate()->find($token->network_node_id);

                if (! $node || ! hash_equals((string) $node->node_uuid, $nodeUuid)) {
                    return ['error' => 'node_binding_mismatch', 'status' => 409];
                }
            } else {
                if ($existingNode) {
                    return ['error' => 'existing_node_requires_bound_token', 'status' => 409];
                }

                $node = new NetworkNode;
                $node->forceFill([
                    'name' => $attributes['name'],
                    'node_uuid' => $nodeUuid,
                    'status' => 'active',
                ]);
            }

            $node->forceFill([
                'name' => $node->exists ? $node->name : $attributes['name'],
                'version' => $attributes['version'] ?? $node->version,
                'os' => $attributes['os'] ?? $node->os,
                'public_ip' => $attributes['public_ip'] ?? $request->ip() ?? $node->public_ip,
                'country' => $attributes['country'] ?? $node->country,
                'city' => $attributes['city'] ?? $node->city,
                'current_server_domain' => $attributes['current_server_domain'] ?? $node->current_server_domain,
                'last_successful_server_domain' => $attributes['last_successful_server_domain'] ?? $node->last_successful_server_domain,
                'capabilities_json' => $attributes['capabilities'] ?? $node->capabilities_json,
                'is_online' => true,
                'last_seen_at' => now(),
            ]);

            $plainTextApiKey = $this->credentials->activate($node);
            $node->forceFill([
                'is_online' => true,
                'last_seen_at' => now(),
            ])->save();

            $token->forceFill([
                'consumed_at' => now(),
                'consumed_by_network_node_id' => $node->id,
            ])->save();

            return [
                'node' => $node,
                'api_key' => $plainTextApiKey,
            ];
        });

        if (isset($result['error'])) {
            $this->audit->record(
                eventType: 'enrollment.rejected',
                outcome: 'rejected',
                request: $request,
                nodeUuid: $nodeUuid,
                metadata: ['reason' => $result['error']],
            );

            throw new NodeEnrollmentException((string) $result['error'], (int) $result['status']);
        }

        $this->audit->record(
            eventType: 'enrollment.consumed',
            outcome: 'success',
            node: $result['node'],
            request: $request,
        );

        return $result;
    }
}
