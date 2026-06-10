<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Screenshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'action_execution_id',
        'network_job_id',
        'path',
        'disk',
        'mime_type',
        'size',
        'captured_at',
        'meta_json',
    ];

    protected $casts = [
        'size' => 'integer',
        'captured_at' => 'datetime',
        'meta_json' => 'array',
    ];

    public function actionExecution(): BelongsTo
    {
        return $this->belongsTo(ActionExecution::class);
    }

    public function networkJob(): BelongsTo
    {
        return $this->belongsTo(NetworkJob::class);
    }
}
