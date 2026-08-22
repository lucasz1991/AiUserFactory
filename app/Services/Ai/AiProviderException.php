<?php

namespace App\Services\Ai;

use RuntimeException;

class AiProviderException extends RuntimeException
{
    public function __construct(
        public readonly ?int $providerStatusCode = null,
    ) {
        parent::__construct('The AI provider request failed.');
    }
}
