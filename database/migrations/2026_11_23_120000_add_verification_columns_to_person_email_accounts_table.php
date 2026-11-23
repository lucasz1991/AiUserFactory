<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * E-Mail-Konten der Personen dienen als Verifizierungs-Postfaecher fuer
     * Registrierungen bei anderen Anbietern (z. B. Proton). Ein Anbieter bindet die
     * Adresse an das neue Konto; danach ist sie fuer weitere Registrierungen
     * verbraucht. Das wird am Konto selbst vermerkt.
     */
    public function up(): void
    {
        Schema::table('person_email_accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('person_email_accounts', 'verification_consumed_at')) {
                $table->timestamp('verification_consumed_at')->nullable()->after('webmail_session');
            }

            if (! Schema::hasColumn('person_email_accounts', 'verification_consumption')) {
                $table->json('verification_consumption')->nullable()->after('verification_consumed_at');
            }

            if (! Schema::hasColumn('person_email_accounts', 'verification_browser_sessions')) {
                $table->json('verification_browser_sessions')->nullable()->after('verification_consumption');
            }
        });
    }

    public function down(): void
    {
        Schema::table('person_email_accounts', function (Blueprint $table): void {
            foreach (['verification_browser_sessions', 'verification_consumption', 'verification_consumed_at'] as $column) {
                if (Schema::hasColumn('person_email_accounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
