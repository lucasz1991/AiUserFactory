<?php

namespace App\Services\ClientController;

use App\Models\Device;
use App\Models\NetworkJob;
use App\Models\NetworkNode;
use App\Models\NetworkTarget;
use Illuminate\Support\Str;

class NetworkJobDispatcher
{
    public function dispatch(
        NetworkNode $node,
        string $type,
        array $payload,
        ?Device $device = null,
        ?NetworkTarget $target = null,
        ?string $requestedBy = null,
        mixed $expiresAt = null,
    ): NetworkJob {
        if ($device && (int) $device->network_node_id !== (int) $node->id) {
            throw new \InvalidArgumentException('Das gewaehlte Geraet gehoert nicht zum ausgewaehlten ClientController-Node.');
        }

        $canonicalPayload = json_encode($this->canonicalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return NetworkJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'network_node_id' => $node->id,
            'device_id' => $device?->id,
            'network_target_id' => $target?->id,
            'type' => $type,
            'payload_json' => $payload,
            'signature' => hash_hmac('sha256', $canonicalPayload ?: '[]', (string) $node->api_key),
            'status' => 'pending',
            'queued_at' => now(),
            'expires_at' => $expiresAt,
            'requested_by' => $requestedBy,
        ]);
    }

    protected function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => is_array($item) ? $this->canonicalize($item) : $item, $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
