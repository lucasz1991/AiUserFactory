<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeCredentialEvent extends Model
{
    protected $fillable = [
        'network_node_id',
        'actor_user_id',
        'node_uuid',
        'event_type',
        'outcome',
        'ip_hash',
        'metadata_json',
        'occurred_at',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function node(): BelongsTo
    {
        return $this->belongsTo(NetworkNode::class, 'network_node_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
