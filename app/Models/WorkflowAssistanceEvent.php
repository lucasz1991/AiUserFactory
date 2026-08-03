<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowAssistanceEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workflow_assistance_request_id', 'sequence', 'event_type', 'actor_user_id',
        'message', 'payload_json', 'occurred_at', 'created_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'payload_json' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function assistanceRequest(): BelongsTo
    {
        return $this->belongsTo(WorkflowAssistanceRequest::class, 'workflow_assistance_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
