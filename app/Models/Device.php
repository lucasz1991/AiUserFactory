<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_node_id',
        'name',
        'platform',
        'device_uuid',
        'adb_serial',
        'appium_endpoint',
        'status',
        'last_seen_at',
        'settings_json',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'settings_json' => 'array',
    ];

    public function networkNode(): BelongsTo
    {
        return $this->belongsTo(NetworkNode::class);
    }
}
