<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiApiRequestAudit extends Model
{
    protected $fillable = [
        'request_id',
        'user_id',
        'team_id',
        'usage_date',
        'endpoint',
        'profile',
        'status',
        'model',
        'provider',
        'provider_status_code',
        'error_code',
        'reserved_tokens',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'reservation_cost_microusd',
        'charged_cost_microusd',
        'provider_cost_microusd',
        'provider_upstream_cost_microusd',
        'duration_ms',
        'completed_at',
    ];

    protected $casts = [
        'usage_date' => 'date',
        'team_id' => 'integer',
        'provider_status_code' => 'integer',
        'reserved_tokens' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'reservation_cost_microusd' => 'integer',
        'charged_cost_microusd' => 'integer',
        'provider_cost_microusd' => 'integer',
        'provider_upstream_cost_microusd' => 'integer',
        'duration_ms' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
