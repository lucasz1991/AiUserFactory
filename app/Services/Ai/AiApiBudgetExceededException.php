<?php

namespace App\Services\Ai;

use RuntimeException;

class AiApiBudgetExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $requestId,
    ) {
        parent::__construct('The daily AI API budget has been exhausted.');
    }
}
