<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('person_email_accounts')) {
            Schema::create('person_email_accounts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
                $table->string('email')->nullable();
                $table->string('provider', 50)->default('proton'); // proton | gmx | custom
                $table->string('username')->nullable();
                $table->longText('password_encrypted')->nullable();
                $table->string('recovery_email')->nullable();
                $table->string('recovery_phone', 120)->nullable();
                $table->string('webmail_url', 2048)->nullable();
                $table->string('imap_host')->nullable();
                $table->unsignedInteger('imap_port')->nullable();
                $table->string('imap_encryption', 20)->nullable();
                $table->string('smtp_host')->nullable();
                $table->unsignedInteger('smtp_port')->nullable();
                $table->string('smtp_encryption', 20)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_primary')->default(false);
                $table->json('webmail_session')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['person_id', 'is_primary'], 'person_email_accounts_primary_idx');
            });
        }

        $this->importLegacyEmailAccounts();
    }

    public function down(): void
    {
        Schema::dropIfExists('person_email_accounts');
    }

    /**
     * Migriert den bisher einzelnen Account aus persons.metadata['email_account']
     * einmalig als primaeren Account in die neue Tabelle. Der Metadaten-Spiegel
     * bleibt bestehen (Backward-Compat fuer Automatisierung/Workflow-Leser).
     */
    private function importLegacyEmailAccounts(): void
    {
        if (! Schema::hasTable('persons') || ! Schema::hasTable('person_email_accounts')) {
            return;
        }

        if (DB::table('person_email_accounts')->exists()) {
            return;
        }

        DB::table('persons')
            ->select('id', 'metadata', 'person_email')
            ->orderBy('id')
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : $row->metadata;
                    $account = is_array($metadata['email_account'] ?? null) ? $metadata['email_account'] : null;

                    if (! is_array($account)) {
                        continue;
                    }

                    $email = $account['email'] ?? $row->person_email ?? null;

                    if (! $email && empty($account['username']) && empty($account['password_encrypted'])) {
                        continue;
                    }

                    $provider = strtolower((string) ($account['provider'] ?? 'proton'));
                    if (! in_array($provider, ['proton', 'gmx', 'custom'], true)) {
                        $provider = 'proton';
                    }

                    $webmailSession = $account['webmail_session'] ?? null;

                    DB::table('person_email_accounts')->insert([
                        'person_id' => $row->id,
                        'email' => $email,
                        'provider' => $provider,
                        'username' => $account['username'] ?? null,
                        'password_encrypted' => $account['password_encrypted'] ?? null,
                        'recovery_email' => $account['recovery_email'] ?? null,
                        'recovery_phone' => $account['recovery_phone'] ?? null,
                        'webmail_url' => $account['webmail_url'] ?? null,
                        'imap_host' => data_get($account, 'imap.host'),
                        'imap_port' => data_get($account, 'imap.port'),
                        'imap_encryption' => data_get($account, 'imap.encryption'),
                        'smtp_host' => data_get($account, 'smtp.host'),
                        'smtp_port' => data_get($account, 'smtp.port'),
                        'smtp_encryption' => data_get($account, 'smtp.encryption'),
                        'notes' => $account['notes'] ?? null,
                        'is_primary' => true,
                        'webmail_session' => is_array($webmailSession) ? json_encode($webmailSession) : null,
                        'sort_order' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }
};
