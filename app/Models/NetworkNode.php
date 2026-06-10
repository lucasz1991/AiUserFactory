<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkNode extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'node_uuid',
        'api_key',
        'node_secret',
        'current_server_domain',
        'last_successful_server_domain',
        'public_ip',
        'country',
        'city',
        'os',
        'version',
        'is_online',
        'last_seen_at',
        'capabilities_json',
        'settings_json',
        'allow_server_rebind',
        'status',
    ];

    protected $casts = [
        'is_online' => 'boolean',
        'allow_server_rebind' => 'boolean',
        'last_seen_at' => 'datetime',
        'capabilities_json' => 'array',
        'settings_json' => 'array',
    ];

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(NetworkJob::class);
    }

    public function heartbeats(): HasMany
    {
        return $this->hasMany(NodeHeartbeat::class);
    }

    public function serverBindings(): HasMany
    {
        return $this->hasMany(NodeServerBinding::class);
    }

    public function rebindLogs(): HasMany
    {
        return $this->hasMany(NodeRebindLog::class);
    }
}
