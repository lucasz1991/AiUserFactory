<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workflow extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'category',
        'is_active',
        'trigger_type',
        'settings_json',
        'last_run_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings_json' => 'array',
        'last_run_at' => 'datetime',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('position')->orderBy('id');
    }

    public function enabledSteps(): HasMany
    {
        return $this->steps()->where('is_enabled', true);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class)->latest('id');
    }
}
