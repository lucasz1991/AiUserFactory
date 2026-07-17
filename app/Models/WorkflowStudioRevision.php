<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStudioRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['workflow_studio_session_id', 'workflow_id', 'revision_number', 'parent_revision_number', 'actor', 'reason', 'before_snapshot_json', 'after_snapshot_json', 'diff_json', 'is_verified', 'verified_at'];

    protected $casts = ['revision_number' => 'integer', 'parent_revision_number' => 'integer', 'before_snapshot_json' => 'array', 'after_snapshot_json' => 'array', 'diff_json' => 'array', 'is_verified' => 'boolean', 'verified_at' => 'datetime'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkflowStudioSession::class, 'workflow_studio_session_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
