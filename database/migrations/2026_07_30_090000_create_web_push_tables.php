<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portiert aus RailTime (2026_07_25_000001_create_web_push_tables.php).
 *
 * Die Abo-Tabelle ersetzt bewusst die Standardmigration von
 * laravel-notification-channels/webpush: Endpunkt und Schluessel liegen
 * verschluesselt (`text` statt `string`), die Eindeutigkeit haengt deshalb am
 * separaten `endpoint_hash`, und die Diagnosefelder (Geraet, Plattform,
 * Fehlerzaehler, Widerruf) kommen dazu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('webpush.database_connection'))
            ->create(config('webpush.table_name'), function (Blueprint $table): void {
                $table->id();
                $table->morphs('subscribable', 'push_subscriptions_subscribable_morph_idx');
                $table->text('endpoint');
                $table->char('endpoint_hash', 64)->unique();
                $table->text('public_key')->nullable();
                $table->text('auth_token')->nullable();
                $table->string('content_encoding', 50)->nullable();
                $table->string('device_name', 100)->nullable();
                $table->string('platform', 20)->default('unknown');
                $table->string('browser', 60)->nullable();
                $table->char('user_agent_hash', 64)->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->unsignedInteger('failure_count')->default(0);
                $table->timestamp('revoked_at')->nullable()->index();
                $table->timestamps();
            });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->boolean('web_push_enabled')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');

        Schema::connection(config('webpush.database_connection'))
            ->dropIfExists(config('webpush.table_name'));
    }
};
