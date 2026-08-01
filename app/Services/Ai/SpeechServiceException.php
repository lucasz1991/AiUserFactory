<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

class SpeechServiceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reasonCode,
        public readonly ?int $providerStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
