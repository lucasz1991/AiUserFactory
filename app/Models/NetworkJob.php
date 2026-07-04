<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkJob extends Model
{
    use HasFactory;

    protected $hidden = [
        'lease_token_hash',
    ];

    protected $fillable = [
        'job_uuid',
        'network_node_id',
        'device_id',
        'person_action_id',
        'network_target_id',
        'workflow_run_id',
        'type',
        'payload_version',
        'payload_json',
        'signature',
        'lease_token_hash',
        'expires_at',
        'lease_expires_at',
        'status',
        'requested_by',
        'queued_at',
        'dispatched_at',
        'last_progress_at',
        'last_sequence',
        'attempt_count',
        'control_command',
        'control_sequence',
        'control_payload_json',
        'control_requested_at',
        'control_acknowledged_at',
        'control_deadline_at',
        'unreachable_at',
        'completed_at',
        'error_message',
        'result_json',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'result_json' => 'array',
        'payload_version' => 'integer',
        'last_sequence' => 'integer',
        'attempt_count' => 'integer',
        'control_sequence' => 'integer',
        'control_payload_json' => 'array',
        'expires_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'queued_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'last_progress_at' => 'datetime',
        'control_requested_at' => 'datetime',
        'control_acknowledged_at' => 'datetime',
        'control_deadline_at' => 'datetime',
        'unreachable_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function networkNode(): BelongsTo
    {
        return $this->belongsTo(NetworkNode::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function personAction(): BelongsTo
    {
        return $this->belongsTo(PersonAction::class);
    }

    public function networkTarget(): BelongsTo
    {
        return $this->belongsTo(NetworkTarget::class);
    }

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(ActionExecution::class);
    }

    public function progressEvents(): HasMany
    {
        return $this->hasMany(NetworkJobProgressEvent::class)->orderBy('sequence');
    }
}
