<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActionExecution extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_job_id',
        'person_action_id',
        'network_node_id',
        'device_id',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'logs_json',
        'result_json',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'logs_json' => 'array',
        'result_json' => 'array',
    ];

    public function networkJob(): BelongsTo
    {
        return $this->belongsTo(NetworkJob::class);
    }

    public function personAction(): BelongsTo
    {
        return $this->belongsTo(PersonAction::class);
    }

    public function networkNode(): BelongsTo
    {
        return $this->belongsTo(NetworkNode::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function screenshots(): HasMany
    {
        return $this->hasMany(Screenshot::class);
    }
}
