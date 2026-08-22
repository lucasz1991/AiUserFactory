<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeEnrollmentToken extends Model
{
    protected $fillable = [
        'token_hash',
        'label',
        'network_node_id',
        'created_by_user_id',
        'consumed_by_network_node_id',
        'expires_at',
        'consumed_at',
        'revoked_at',
        'last_attempt_at',
        'attempt_count',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'attempt_count' => 'integer',
    ];

    public function node(): BelongsTo
    {
        return $this->belongsTo(NetworkNode::class, 'network_node_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function consumedByNode(): BelongsTo
    {
        return $this->belongsTo(NetworkNode::class, 'consumed_by_network_node_id');
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
