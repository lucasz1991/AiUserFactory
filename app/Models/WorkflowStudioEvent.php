<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStudioEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['workflow_studio_session_id', 'sequence', 'event_type', 'level', 'message', 'payload_json', 'occurred_at'];

    protected $casts = ['sequence' => 'integer', 'payload_json' => 'array', 'occurred_at' => 'datetime'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkflowStudioSession::class, 'workflow_studio_session_id');
    }
}
