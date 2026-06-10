<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NetworkTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'url',
        'is_active',
        'allow_browser',
        'allow_api',
        'allow_screenshots',
        'timeout',
        'settings_json',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'allow_browser' => 'boolean',
        'allow_api' => 'boolean',
        'allow_screenshots' => 'boolean',
        'timeout' => 'integer',
        'settings_json' => 'array',
    ];
}
