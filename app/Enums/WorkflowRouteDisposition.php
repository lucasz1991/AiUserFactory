<?php

namespace App\Enums;

enum WorkflowRouteDisposition: string
{
    case CONTINUE = 'continue';
    case COMPLETE = 'complete';
    case FAIL = 'fail';
    case INVALID = 'invalid';

    public static function fromRoute(?array $route): self
    {
        if (! is_array($route) || $route === []) {
            return self::INVALID;
        }

        $type = strtolower(trim((string) ($route['type'] ?? '')));
        $step = strtolower(trim((string) ($route['action_key'] ?? $route['step'] ?? '')));
        $card = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if ($type === 'fail' || $step === 'fail') {
            return self::FAIL;
        }

        if ($type === 'end' || $step === 'end') {
            return self::COMPLETE;
        }

        if ($type === 'card') {
            return $card !== '' ? self::CONTINUE : self::INVALID;
        }

        return $step !== '' ? self::CONTINUE : self::INVALID;
    }
}
