<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeRebindLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_node_id',
        'old_server_domain',
        'new_server_domain',
        'status',
        'requested_by',
        'requested_at',
        'completed_at',
        'error_message',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function networkNode(): BelongsTo
    {
        return $this->belongsTo(NetworkNode::class);
    }
}
