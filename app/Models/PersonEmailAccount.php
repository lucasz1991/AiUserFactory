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
        'verification_consumed_at',
        'verification_consumption',
        'verification_browser_sessions',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'imap_port' => 'integer',
        'smtp_port' => 'integer',
        'sort_order' => 'integer',
        'webmail_session' => 'array',
        'verification_consumed_at' => 'datetime',
        'verification_consumption' => 'array',
        'verification_browser_sessions' => 'array',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function hasStoredPassword(): bool
    {
        return is_string($this->password_encrypted) && trim($this->password_encrypted) !== '';
    }

    /**
     * Ein Anbieter (z. B. Proton) hat die Adresse bei einer Registrierung als
     * Verifizierungs-Adresse gebunden oder abgelehnt; fuer weitere Registrierungen
     * ist sie verbraucht.
     */
    public function isVerificationConsumed(): bool
    {
        return $this->verification_consumed_at !== null;
    }

    /**
     * @return list<array{reason: string, workflow_run_id: ?int, consumed_at: ?string}>
     */
    public function verificationConsumptionEntries(): array
    {
        return array_values(array_filter(
            is_array($this->verification_consumption) ? $this->verification_consumption : [],
            fn (mixed $entry): bool => is_array($entry),
        ));
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
