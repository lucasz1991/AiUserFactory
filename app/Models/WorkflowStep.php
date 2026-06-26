<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowStep extends Model
{
    use HasFactory;

    public const TYPE_MAIL_ACCOUNT_REGISTRATION = 'mail_account_registration';
    public const TYPE_WEBMAIL_LOGIN = 'webmail_login';
    public const TYPE_PLANNED_ACTION = 'planned_action';
    public const TYPE_WAIT = 'wait';

    protected $fillable = [
        'workflow_id',
        'name',
        'type',
        'action_key',
        'position',
        'is_enabled',
        'config_json',
        'retry_attempts',
        'wait_after_seconds',
    ];

    protected $casts = [
        'position' => 'integer',
        'is_enabled' => 'boolean',
        'config_json' => 'array',
        'retry_attempts' => 'integer',
        'wait_after_seconds' => 'integer',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowStepRun::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_MAIL_ACCOUNT_REGISTRATION => 'E-Mail registrieren',
            self::TYPE_WEBMAIL_LOGIN => 'Webmail Login',
            self::TYPE_PLANNED_ACTION => 'Geplante Aktion',
            self::TYPE_WAIT => 'Warten',
            default => (string) str($this->type)->replace('_', ' ')->title(),
        };
    }

    public function getConfigSummaryAttribute(): string
    {
        $config = is_array($this->config_json) ? $this->config_json : [];

        if ($this->type === self::TYPE_PLANNED_ACTION) {
            return trim((string) ($config['label'] ?? $config['action'] ?? $this->action_key ?? 'Interne Aktion'));
        }

        if ($this->type === self::TYPE_MAIL_ACCOUNT_REGISTRATION) {
            return 'Provider: '.(trim((string) ($config['provider_key'] ?? '')) ?: 'Standard');
        }

        if ($this->type === self::TYPE_WEBMAIL_LOGIN) {
            return 'Provider: '.(trim((string) ($config['provider'] ?? '')) ?: 'aus Person');
        }

        if ($this->type === self::TYPE_WAIT) {
            return max(0, (int) ($config['seconds'] ?? $this->wait_after_seconds)).' Sekunden';
        }

        return '';
    }
}
