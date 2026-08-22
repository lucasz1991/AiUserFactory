<?php

namespace Tests\Concerns;

use App\Models\NetworkNode;
use App\Services\ClientController\NetworkNodeCredentialService;

trait CreatesNetworkNodes
{
    /** @var array<int, string> */
    private array $plainTextNetworkNodeKeys = [];

    protected function createNetworkNode(array $attributes): NetworkNode
    {
        unset($attributes['api_key'], $attributes['api_key_hash'], $attributes['node_secret']);

        $node = new NetworkNode;
        $node->forceFill($attributes);
        $plainTextKey = app(NetworkNodeCredentialService::class)->activate($node, 'test-fixture');
        $this->plainTextNetworkNodeKeys[$node->id] = $plainTextKey;

        return $node;
    }

    protected function networkNodeApiKey(NetworkNode $node): string
    {
        return $this->plainTextNetworkNodeKeys[$node->id]
            ?? throw new \LogicException('Kein Klartext-Testkey fuer diesen NetworkNode registriert.');
    }
}
