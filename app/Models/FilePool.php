<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FilePool extends Model
{
    protected $fillable = [
        'title',
        'type',
        'description',
    ];

    public function filepoolable(): MorphTo
    {
        return $this->morphTo();
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }
}
