<?php

namespace App\Services\Ai;

use RuntimeException;

class AiApiRequestFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $requestId,
        public readonly int $httpStatus = 502,
    ) {
        parent::__construct('The AI API request could not be completed.');
    }
}
