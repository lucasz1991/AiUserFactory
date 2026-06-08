<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Person extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'persons';

    protected $fillable = [
        'platform',
        'profile_key',
        'profile_label',
        'person_first_name',
        'person_last_name',
        'person_alias',
        'person_date_of_birth',
        'person_gender',
        'person_email',
        'person_phone',
        'person_address_line1',
        'person_address_line2',
        'person_postal_code',
        'person_state',
        'person_country',
        'person_city',
        'person_timezone',
        'person_notes',
        'avatar_path',
        'identity_profile',
        'bot_profile',
        'bot_status',
        'social_accounts',
        'browser_profile_path',
        'cookie_file_path',
        'persistent_profile_enabled',
        'headless_enabled',
        'auto_login_enabled',
        'login_username',
        'login_password_encrypted',
        'login_password_base_encrypted',
        'navigation_timeout_seconds',
        'post_login_wait_ms',
        'typing_delay_ms',
        'relationship_list_process_timeout_seconds',
        'relationship_list_max_scroll_rounds',
        'follower_list_max_items',
        'following_list_max_items',
        'is_primary',
        'is_active',
        'sort_order',
        'cookie_payload',
        'cookie_payload_hash',
        'cookie_count',
        'session_cookie_present',
        'cookies_synced_at',
        'scrape_blocked_at',
        'scrape_blocked_until',
        'scrape_blocked_reason',
        'base_sync_status',
        'base_synced_at',
        'base_sync_error',
        'metadata',
    ];

    protected $casts = [
        'persistent_profile_enabled' => 'boolean',
        'person_date_of_birth' => 'date',
        'identity_profile' => 'array',
        'bot_profile' => 'array',
        'social_accounts' => 'array',
        'headless_enabled' => 'boolean',
        'auto_login_enabled' => 'boolean',
        'navigation_timeout_seconds' => 'integer',
        'post_login_wait_ms' => 'integer',
        'typing_delay_ms' => 'integer',
        'relationship_list_process_timeout_seconds' => 'integer',
        'relationship_list_max_scroll_rounds' => 'integer',
        'follower_list_max_items' => 'integer',
        'following_list_max_items' => 'integer',
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'cookie_count' => 'integer',
        'session_cookie_present' => 'boolean',
        'cookies_synced_at' => 'datetime',
        'scrape_blocked_at' => 'datetime',
        'scrape_blocked_until' => 'datetime',
        'base_synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function getDisplayNameAttribute(): string
    {
        $name = trim(collect([$this->person_first_name, $this->person_last_name])->filter()->implode(' '));

        return $name !== ''
            ? $name
            : ($this->person_alias ?: $this->profile_label);
    }

    public function getDisplayHandleAttribute(): string
    {
        return $this->login_username ? '@'.ltrim($this->login_username, '@') : 'Kein Instagram-Login';
    }

    public function getIsScrapeBlockedAttribute(): bool
    {
        return $this->scrape_blocked_until !== null && $this->scrape_blocked_until->isFuture();
    }

    public function filePool(): MorphOne
    {
        return $this->morphOne(FilePool::class, 'filepoolable');
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }
}
