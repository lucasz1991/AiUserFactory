<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowRecording extends Model
{
    protected $fillable = [
        'recording_uuid', 'user_id', 'workflow_id', 'name', 'start_url', 'status',
        'events_json', 'bindings_json', 'runtime_json', 'state_json', 'revision',
        'last_event_sequence', 'runtime_generation', 'started_at', 'finished_at', 'last_activity_at',
    ];

    // Runtime addresses, tokens, browser state and draft input values must never
    // enter an automatic Eloquent / Livewire JSON serialization.
    protected $hidden = ['events_json', 'bindings_json', 'runtime_json', 'state_json', 'runtime_generation'];

    protected $casts = [
        'events_json' => 'encrypted:array',
        'bindings_json' => 'encrypted:array',
        'runtime_json' => 'encrypted:array',
        'state_json' => 'encrypted:array',
        'revision' => 'integer',
        'last_event_sequence' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
