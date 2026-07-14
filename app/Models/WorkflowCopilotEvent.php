<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class WorkflowCopilotEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workflow_copilot_session_id',
        'sequence',
        'event_type',
        'phase',
        'level',
        'message',
        'payload_json',
        'is_milestone',
        'occurred_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'payload_json' => 'array',
        'is_milestone' => 'boolean',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Workflow-Copilot-Ereignisse sind unveraenderlich.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Workflow-Copilot-Ereignisse duerfen nicht geloescht werden.');
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkflowCopilotSession::class, 'workflow_copilot_session_id');
    }
}
