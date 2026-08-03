<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonBlueprint extends Model
{
    protected $fillable = [
        'name',
        'description',
        'is_active',
        'platform',
        'target_count',
        'per_day',
        'created_count',
        'countries',
        'languages',
        'genders',
        'age_min',
        'age_max',
        'profile_prompt',
        'generate_avatar',
        'account_types',
        'onboarding_workflow_id',
        'schedule_templates',
        'next_run_at',
        'last_run_at',
        'created_today',
        'created_today_date',
        'last_error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'target_count' => 'integer',
        'per_day' => 'integer',
        'created_count' => 'integer',
        'countries' => 'array',
        'languages' => 'array',
        'genders' => 'array',
        'age_min' => 'integer',
        'age_max' => 'integer',
        'generate_avatar' => 'boolean',
        'account_types' => 'array',
        'schedule_templates' => 'array',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'created_today' => 'integer',
        'created_today_date' => 'date',
    ];

    public function onboardingWorkflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'onboarding_workflow_id');
    }

    public function persons(): HasMany
    {
        return $this->hasMany(Person::class, 'person_blueprint_id')->latest('id');
    }

    /**
     * Der Bauplan ist erschoepft, sobald die Zielzahl erreicht ist. `0` bedeutet
     * bewusst unbegrenzt.
     */
    public function getIsExhaustedAttribute(): bool
    {
        return $this->target_count > 0 && $this->created_count >= $this->target_count;
    }

    public function getRemainingCountAttribute(): ?int
    {
        return $this->target_count > 0
            ? max(0, $this->target_count - $this->created_count)
            : null;
    }

    public function resetDailyCounterIfNeeded(string $timezone): void
    {
        $today = now($timezone)->toDateString();

        if ($this->created_today_date?->toDateString() === $today) {
            return;
        }

        $this->created_today = 0;
        $this->created_today_date = $today;
    }
}
