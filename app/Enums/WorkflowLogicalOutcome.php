<?php

namespace App\Enums;

enum WorkflowLogicalOutcome: string
{
    case SUCCESS = 'success';
    case CONDITION_TRUE = 'condition_true';
    case CONDITION_FALSE = 'condition_false';
    case PARTIAL = 'partial';
    case TIMEOUT = 'timeout';
    case TECHNICAL_ERROR = 'technical_error';

    public static function fromResult(array $result, string $routeOutcome): self
    {
        $explicit = strtolower(trim((string) ($result['logicalOutcome'] ?? $result['logical_outcome'] ?? '')));
        $known = self::tryFrom($explicit);

        if ($known) {
            return $known;
        }

        $branchOutcome = strtolower(trim((string) ($result['branchOutcome'] ?? $result['branch_outcome'] ?? '')));
        $conditionMatched = $result['conditionMatched'] ?? $result['condition_matched'] ?? null;

        if ($branchOutcome === 'failed' || $conditionMatched === false) {
            return self::CONDITION_FALSE;
        }

        if ($conditionMatched === true) {
            return self::CONDITION_TRUE;
        }

        return match ($routeOutcome) {
            'timeout' => self::TIMEOUT,
            'partial' => self::PARTIAL,
            'failed' => self::TECHNICAL_ERROR,
            default => self::SUCCESS,
        };
    }
}
