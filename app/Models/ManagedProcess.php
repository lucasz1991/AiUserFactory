<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ManagedProcess extends Model
{
    use HasFactory;

    protected $fillable = [
        'pid',
        'parent_pid',
        'family_root_pid',
        'process_key',
        'run_id',
        'run_type',
        'process_role',
        'process_type',
        'executable',
        'script_name',
        'command',
        'short_command',
        'runtime_config_path',
        'status_path',
        'status',
        'is_managed',
        'is_root',
        'is_idle_suspect',
        'cpu_percent',
        'memory_mb',
        'elapsed_seconds',
        'started_at',
        'detected_at',
        'last_seen_at',
        'heartbeat_at',
        'heartbeat_age_seconds',
        'last_stage',
        'last_message',
        'restart_count',
        'last_restart_at',
        'supervisor_checked_at',
        'exited_at',
        'last_action_at',
        'action_message',
        'metadata',
    ];

    protected $casts = [
        'pid' => 'integer',
        'parent_pid' => 'integer',
        'family_root_pid' => 'integer',
        'is_managed' => 'boolean',
        'is_root' => 'boolean',
        'is_idle_suspect' => 'boolean',
        'cpu_percent' => 'decimal:2',
        'memory_mb' => 'decimal:2',
        'elapsed_seconds' => 'integer',
        'started_at' => 'datetime',
        'detected_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'heartbeat_age_seconds' => 'integer',
        'restart_count' => 'integer',
        'last_restart_at' => 'datetime',
        'supervisor_checked_at' => 'datetime',
        'exited_at' => 'datetime',
        'last_action_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function isRunning(): bool
    {
        return in_array($this->status, ['running', 'terminate_requested', 'kill_requested'], true);
    }
}
