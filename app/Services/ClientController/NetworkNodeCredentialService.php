<?php

namespace App\Services\ClientController;

use App\Models\NetworkNode;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class NetworkNodeCredentialService
{
    public function __construct(
        private readonly NodeCredentialAuditService $audit,
    ) {}

    public function activate(NetworkNode $node, string $reason = 'enrollment'): string
    {
        $plainTextKey = 'ffn_'.Str::random(64);
        $hash = $this->hash($plainTextKey);

        $node->forceFill([
            // `api_key` ist eine Legacy-Spalte. Sie enthaelt bewusst denselben
            // irreversiblen Hash und niemals wieder den uebertragenen Key.
            'api_key' => $hash,
            'api_key_hash' => $hash,
            'api_key_last_four' => substr($plainTextKey, -4),
            'node_secret' => null,
            'signing_secret_encrypted' => $plainTextKey,
            'api_key_rotated_at' => now(),
            'api_key_revoked_at' => null,
            'last_authenticated_at' => null,
        ])->save();

        $this->audit->record(
            eventType: 'api_key.rotated',
            outcome: 'success',
            node: $node,
            metadata: ['reason' => $reason],
        );

        return $plainTextKey;
    }

    public function initializeRevoked(NetworkNode $node): void
    {
        $this->activate($node, 'pending-enrollment');
        $node->forceFill([
            'api_key_revoked_at' => now(),
            'is_online' => false,
        ])->save();
    }

    public function revoke(NetworkNode $node, ?User $actor = null, string $reason = 'manual-rotation'): void
    {
        $node->forceFill([
            'api_key_revoked_at' => now(),
            'is_online' => false,
        ])->save();

        $this->audit->record(
            eventType: 'api_key.revoked',
            outcome: 'success',
            node: $node,
            actor: $actor,
            metadata: ['reason' => $reason],
        );
    }

    public function authenticate(?string $plainTextKey): ?NetworkNode
    {
        $plainTextKey = trim((string) $plainTextKey);

        if ($plainTextKey === '') {
            return null;
        }

        $hash = $this->hash($plainTextKey);
        $node = NetworkNode::query()
            ->where('api_key_hash', $hash)
            ->whereNull('api_key_revoked_at')
            ->where('status', '!=', 'disabled')
            ->first();

        if (! $node || ! hash_equals((string) $node->api_key_hash, $hash)) {
            return null;
        }

        if ($node->last_authenticated_at === null || $node->last_authenticated_at->lt(now()->subMinutes(5))) {
            $node->forceFill(['last_authenticated_at' => now()])->saveQuietly();
        }

        return $node;
    }

    public function signingSecret(NetworkNode $node): string
    {
        $secret = trim((string) $node->signing_secret_encrypted);

        if ($secret === '') {
            throw new RuntimeException('Der ClientController-Node besitzt kein aktives Signaturgeheimnis.');
        }

        return $secret;
    }

    public function hash(string $plainTextKey): string
    {
        return hash('sha256', $plainTextKey);
    }
}
