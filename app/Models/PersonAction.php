<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'person_id',
        'network_target_id',
        'action_type',
        'action_payload',
        'is_active',
        'schedule_expression',
        'last_run_at',
    ];

    protected $casts = [
        'action_payload' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function networkTarget(): BelongsTo
    {
        return $this->belongsTo(NetworkTarget::class);
    }
}
