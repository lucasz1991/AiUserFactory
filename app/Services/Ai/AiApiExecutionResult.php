<?php

namespace App\Services\Ai;

final readonly class AiApiExecutionResult
{
    public function __construct(
        public string $requestId,
        public mixed $data,
    ) {}
}
