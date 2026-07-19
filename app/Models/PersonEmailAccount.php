<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonEmailAccount extends Model
{
    protected $fillable = [
        'person_id',
        'email',
        'provider',
        'username',
        'password_encrypted',
        'recovery_email',
        'recovery_phone',
        'webmail_url',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'notes',
        'is_primary',
        'webmail_session',
        'sort_order',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'imap_port' => 'integer',
        'smtp_port' => 'integer',
        'sort_order' => 'integer',
        'webmail_session' => 'array',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function hasStoredPassword(): bool
    {
        return is_string($this->password_encrypted) && trim($this->password_encrypted) !== '';
    }

    public function hasWebmailSession(): bool
    {
        return is_array($this->webmail_session) && $this->webmail_session !== [];
    }

    /**
     * Baut den Metadaten-Spiegel (persons.metadata['email_account']) fuer diesen
     * Account – exakt in der Form, die Automatisierung/Workflow-Leser erwarten.
     */
    public function toMetadataAccount(): array
    {
        return [
            'email' => $this->email,
            'provider' => $this->provider,
            'username' => $this->username,
            'password_encrypted' => $this->password_encrypted,
            'recovery_email' => $this->recovery_email,
            'recovery_phone' => $this->recovery_phone,
            'webmail_url' => $this->webmail_url,
            'imap' => [
                'host' => $this->imap_host,
                'port' => $this->imap_port,
                'encryption' => $this->imap_encryption,
            ],
            'smtp' => [
                'host' => $this->smtp_host,
                'port' => $this->smtp_port,
                'encryption' => $this->smtp_encryption,
            ],
            'notes' => $this->notes,
            'webmail_session' => is_array($this->webmail_session) ? $this->webmail_session : null,
            'updated_at' => optional($this->updated_at)->toIso8601String() ?? now()->toIso8601String(),
        ];
    }
}
