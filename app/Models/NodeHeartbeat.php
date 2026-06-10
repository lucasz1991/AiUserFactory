<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeHeartbeat extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_node_id',
        'status',
        'payload_json',
        'received_at',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'received_at' => 'datetime',
    ];

    public function networkNode(): BelongsTo
    {
        return $this->belongsTo(NetworkNode::class);
    }
}
