<?php

namespace App\Enums;

enum WorkflowCopilotPermissionMode: string
{
    case ASK_ALL = 'ask_all';
    case ASK_CRITICAL = 'ask_critical';
    case UNRESTRICTED = 'unrestricted';

    public function label(): string
    {
        return match ($this) {
            self::ASK_ALL => 'Immer nachfragen',
            self::ASK_CRITICAL => 'Kritisch nachfragen',
            self::UNRESTRICTED => 'Uneingeschraenkter Zugriff',
        };
    }

    public static function normalize(mixed $value): self
    {
        return self::tryFrom(trim((string) $value)) ?? self::ASK_CRITICAL;
    }
}
