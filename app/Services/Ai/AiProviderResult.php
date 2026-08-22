<?php

namespace App\Services\Ai;

final readonly class AiProviderResult
{
    public function __construct(
        public mixed $data,
        public array $usage = [],
        public ?string $model = null,
        public ?string $provider = null,
    ) {}

    public static function fromResponse(array $response, mixed $data): self
    {
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $model = is_string($response['model'] ?? null) ? trim($response['model']) : null;
        $provider = is_string($response['provider'] ?? null) ? trim($response['provider']) : null;

        return new self(
            data: $data,
            usage: $usage,
            model: $model !== '' ? $model : null,
            provider: $provider !== '' ? $provider : null,
        );
    }
}
