<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiApiDailyBudget extends Model
{
    protected $fillable = [
        'usage_date',
        'scope_type',
        'scope_id',
        'committed_cost_microusd',
        'reserved_tokens',
        'request_count',
    ];

    protected $casts = [
        'usage_date' => 'date',
        'scope_id' => 'integer',
        'committed_cost_microusd' => 'integer',
        'reserved_tokens' => 'integer',
        'request_count' => 'integer',
    ];
}
