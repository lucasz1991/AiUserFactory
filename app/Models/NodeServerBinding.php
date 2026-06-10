<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeServerBinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_node_id',
        'server_domain',
        'status',
        'bound_at',
        'last_successful_contact_at',
        'settings_json',
    ];

    protected $casts = [
        'bound_at' => 'datetime',
        'last_successful_contact_at' => 'datetime',
        'settings_json' => 'array',
    ];

    public function networkNode(): BelongsTo
    {
        return $this->belongsTo(NetworkNode::class);
    }
}
