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

    public const TYPE_PREPARATION = 'preparation';

    public const TYPE_DATA_PROCESSING = 'data_processing';

    public const TYPE_BROWSER_CONTROL = 'browser_control';

    public const TYPE_INTERACTION = 'interaction';

    public const TYPE_DECISION = 'decision';

    public const TYPE_CLEANUP = 'cleanup';

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

    protected static function booted(): void
    {
        $syncWorkflowReferences = static function (WorkflowStep $step): void {
            Workflow::query()->find($step->workflow_id)?->syncIncludedWorkflowReferences();
        };

        static::saved($syncWorkflowReferences);
        static::deleted($syncWorkflowReferences);
    }

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
            self::TYPE_PREPARATION => 'Vorbereitung',
            self::TYPE_DATA_PROCESSING => 'Daten verarbeiten',
            self::TYPE_BROWSER_CONTROL => 'Browsersteuerung',
            self::TYPE_INTERACTION => 'Interaktion',
            self::TYPE_DECISION => 'Status pruefen',
            self::TYPE_CLEANUP => 'Abschluss',
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

        return trim((string) ($config['description'] ?? $config['label'] ?? 'Konfigurierbare Prozess-Aufgabe'));
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
                $order = max(0, (int) ($task['order_id'] ?? $task['position'] ?? (($index + 1) * 10)));

                return [
                    'key' => trim((string) ($task['key'] ?? 'task-'.$this->id.'-'.$index)),
                    'title' => trim((string) ($task['title'] ?? $task['label'] ?? 'Task')),
                    'description' => trim((string) ($task['description'] ?? '')),
                    'order_id' => $order,
                    'position' => $order,
                    'kind' => trim((string) ($task['kind'] ?? 'browser')),
                    'task_key' => trim((string) ($task['task_key'] ?? '')),
                    'workflow_id' => (int) ($task['workflow_id'] ?? 0),
                    'workflow_slug' => trim((string) ($task['workflow_slug'] ?? '')),
                    'runner' => trim((string) ($task['runner'] ?? '')),
                    'node_script' => trim((string) ($task['node_script'] ?? '')),
                    'php_handler' => trim((string) ($task['php_handler'] ?? '')),
                    'browser_window' => trim((string) ($task['browser_window'] ?? '')),
                    'browser_window_name' => trim((string) ($task['browser_window_name'] ?? $task['browser_window'] ?? '')),
                    'timeout_seconds' => max(0, (int) ($task['timeout_seconds'] ?? 0)),
                    'status' => trim((string) ($task['status'] ?? 'template')),
                    'selector' => trim((string) ($task['selector'] ?? '')),
                    'element_selector' => trim((string) ($task['element_selector'] ?? $task['selector'] ?? '')),
                    'input_selector' => trim((string) ($task['input_selector'] ?? '')),
                    'input' => trim((string) ($task['input'] ?? '')),
                    'value' => trim((string) ($task['value'] ?? '')),
                    'url' => trim((string) ($task['url'] ?? '')),
                    'mailbox_source' => trim((string) ($task['mailbox_source'] ?? '')),
                    'script_person_source' => trim((string) ($task['script_person_source'] ?? $task['mailbox_source'] ?? '')),
                    'success_payload' => $task['success_payload'] ?? null,
                    'failure_payload' => $task['failure_payload'] ?? null,
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
