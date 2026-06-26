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
    public const TYPE_BROWSER_TASK = 'browser_task';
    public const TYPE_DATA_TASK = 'data_task';

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
            self::TYPE_BROWSER_TASK => 'Browser Task',
            self::TYPE_DATA_TASK => 'Daten Task',
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
            return (string) ($config['automation_summary'] ?? ('Provider: '.(trim((string) ($config['provider_key'] ?? '')) ?: 'Standard')));
        }

        if ($this->type === self::TYPE_WEBMAIL_LOGIN) {
            return (string) ($config['automation_summary'] ?? ('Provider: '.(trim((string) ($config['provider'] ?? '')) ?: 'aus Person')));
        }

        if ($this->type === self::TYPE_WAIT) {
            return max(0, (int) ($config['seconds'] ?? $this->wait_after_seconds)).' Sekunden';
        }

        return '';
    }

    public function getTaskCardsAttribute(): array
    {
        $tasks = data_get($this->config_json, 'tasks', []);

        if (! is_array($tasks)) {
            return [];
        }

        return collect($tasks)
            ->filter(fn (mixed $task): bool => is_array($task))
            ->map(function (array $task, int $index): array {
                return [
                    'key' => trim((string) ($task['key'] ?? 'task-'.$this->id.'-'.$index)),
                    'title' => trim((string) ($task['title'] ?? $task['label'] ?? 'Task')),
                    'description' => trim((string) ($task['description'] ?? '')),
                    'kind' => trim((string) ($task['kind'] ?? 'browser')),
                    'task_key' => trim((string) ($task['task_key'] ?? '')),
                    'runner' => trim((string) ($task['runner'] ?? '')),
                    'node_script' => trim((string) ($task['node_script'] ?? '')),
                    'php_handler' => trim((string) ($task['php_handler'] ?? '')),
                    'timeout_seconds' => max(0, (int) ($task['timeout_seconds'] ?? 0)),
                    'status' => trim((string) ($task['status'] ?? 'template')),
                    'selector' => trim((string) ($task['selector'] ?? '')),
                    'input' => trim((string) ($task['input'] ?? '')),
                    'next' => is_array($task['next'] ?? null) ? $task['next'] : null,
                    'on_partial' => is_array($task['on_partial'] ?? null) ? $task['on_partial'] : null,
                    'on_error' => is_array($task['on_error'] ?? null) ? $task['on_error'] : null,
                    'status_routes' => is_array($task['status_routes'] ?? null) ? $task['status_routes'] : [],
                ];
            })
            ->values()
            ->toArray();
    }

    public function getRoutesAttribute(): array
    {
        $routes = data_get($this->config_json, 'routes', []);

        return is_array($routes) ? $routes : [];
    }
}
