<?php

namespace App\Exceptions;

use RuntimeException;

class WorkflowRunConflictException extends RuntimeException
{
    public function __construct(string $path)
    {
        // Only the field path is included: context values can contain credentials.
        parent::__construct('Der Workflow-Lauf wurde parallel geaendert ('.$path.'); veraltete Aenderung verworfen.');
    }
}
