<?php

namespace App\Exceptions;

use RuntimeException;

class WorkflowSupervisorInterruptedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Steuerung oder Entscheidungsgrundlage wurde waehrend der KI-Anfrage geaendert; Ergebnis verworfen.');
    }
}
