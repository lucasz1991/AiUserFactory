<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

class LocalAssistantVoiceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reasonCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
