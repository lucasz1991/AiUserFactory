<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkJobProgressEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_job_id',
        'sequence',
        'kind',
        'payload_json',
        'screenshot_relative_path',
        'received_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'payload_json' => 'array',
        'received_at' => 'datetime',
    ];

    public function networkJob(): BelongsTo
    {
        return $this->belongsTo(NetworkJob::class);
    }
}
