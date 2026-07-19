<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowRevisionEvidence extends Model
{
    public $timestamps = false;

    protected $table = 'workflow_revision_evidence';

    protected $fillable = [
        'workflow_id', 'workflow_copilot_session_id', 'workflow_studio_session_id', 'workflow_run_id',
        'workflow_step_id', 'workflow_revision', 'task_key', 'logical_outcome', 'route_disposition',
        'successful', 'error_signature', 'evidence_json', 'created_at',
    ];

    protected $casts = [
        'workflow_revision' => 'integer', 'successful' => 'boolean', 'evidence_json' => 'array',
        'created_at' => 'datetime',
    ];
}
