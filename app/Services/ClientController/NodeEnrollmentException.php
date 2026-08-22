<?php

namespace App\Services\ClientController;

use RuntimeException;

class NodeEnrollmentException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $statusCode = 401,
    ) {
        parent::__construct('Enrollment token is invalid, expired, consumed, or not valid for this node.');
    }
}
