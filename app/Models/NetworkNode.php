<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'update_status',
        'update_target_version',
        'update_requested_at',
        'update_installed_at',
        'update_error',
        'is_online',
        'last_seen_at',
        'capabilities_json',
        'settings_json',
        'allow_server_rebind',
        'status',
        'workflow_reservation_run_id',
    ];

    protected $casts = [
        'is_online' => 'boolean',
        'allow_server_rebind' => 'boolean',
        'last_seen_at' => 'datetime',
        'update_requested_at' => 'datetime',
        'update_installed_at' => 'datetime',
        'capabilities_json' => 'array',
        'settings_json' => 'array',
    ];

    public static function heartbeatTimeoutSeconds(): int
    {
        $settings = Setting::getValue('client_controller', 'server');
        $heartbeatInterval = (int) data_get($settings, 'default_heartbeat_interval_seconds', 30);

        return max(60, min(10800, $heartbeatInterval * 3));
    }

    public static function expireStale(): int
    {
        return static::query()
            ->where('is_online', true)
            ->where(function (Builder $query): void {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subSeconds(static::heartbeatTimeoutSeconds()));
            })
            ->update(['is_online' => false]);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'active'
            && $this->is_online
            && $this->last_seen_at !== null
            && $this->last_seen_at->gte(now()->subSeconds(static::heartbeatTimeoutSeconds()));
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where('is_online', true)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subSeconds(static::heartbeatTimeoutSeconds()));
    }

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
