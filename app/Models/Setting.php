<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Setting extends Model
{
    use HasFactory;
    // Erlaubte Felder, die in der Datenbank gespeichert werden können
    protected $fillable = ['type', 'key', 'value'];

    // JSON-Daten als Array speichern und abrufen
    protected $casts = [
        'value' => 'array',
    ];

    /**
     * Get the value of a setting by key.
     *
     * @param string $key
     * @return mixed
     */
    public static function getValue($type, $key)
    {
        $setting = self::where('type', $type)->where('key', $key)->first();
        return $setting ? $setting->value : null;
    }

    /**
     * Set or update the value of a setting by key.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public static function setValue($type, $key, $value)
    {
        self::updateOrCreate(
            ['type' => $type, 'key' => $key,],
            ['value' => $value]
        );
    }
}

