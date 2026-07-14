<?php

namespace App\Exceptions;

use RuntimeException;

class WorkflowRevisionConflictException extends RuntimeException
{
    public function __construct(
        public readonly int $expectedRevision,
        public readonly int $actualRevision,
    ) {
        parent::__construct(
            "Workflow-Revision {$expectedRevision} wurde erwartet, aktuell ist Revision {$actualRevision} gespeichert.",
        );
    }
}
