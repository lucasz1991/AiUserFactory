<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_nodes', function (Blueprint $table): void {
            $table->string('api_key_hash', 64)->nullable()->unique()->after('api_key');
            $table->string('api_key_last_four', 8)->nullable()->after('api_key_hash');
            $table->text('signing_secret_encrypted')->nullable()->after('node_secret');
            $table->timestamp('api_key_rotated_at')->nullable()->after('signing_secret_encrypted');
            $table->timestamp('api_key_revoked_at')->nullable()->index()->after('api_key_rotated_at');
            $table->timestamp('last_authenticated_at')->nullable()->after('api_key_revoked_at');
        });

        DB::table('network_nodes')
            ->select(['id', 'api_key'])
            ->orderBy('id')
            ->chunkById(100, function ($nodes): void {
                foreach ($nodes as $node) {
                    $plainTextKey = trim((string) $node->api_key);

                    if ($plainTextKey === '') {
                        continue;
                    }

                    $hash = hash('sha256', $plainTextKey);

                    DB::table('network_nodes')->where('id', $node->id)->update([
                        // Die Legacy-Spalte bleibt fuer eine migrationsarme Rollback-Phase
                        // bestehen, enthaelt ab hier aber ebenfalls nur den SHA-256-Hash.
                        'api_key' => $hash,
                        'api_key_hash' => $hash,
                        'api_key_last_four' => substr($plainTextKey, -4),
                        // Job-HMACs muessen fuer bestehende Clients weiterhin mit dem
                        // bekannten Key signiert werden; die reversible Kopie liegt
                        // deshalb ausschliesslich Laravel-verschluesselt in TEXT.
                        'signing_secret_encrypted' => Crypt::encryptString($plainTextKey),
                        'node_secret' => null,
                        'api_key_rotated_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });

        Schema::create('node_enrollment_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('label')->nullable();
            $table->foreignId('network_node_id')->nullable()->constrained('network_nodes')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('consumed_by_network_node_id')->nullable()->constrained('network_nodes')->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamp('last_attempt_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamps();
        });

        Schema::create('node_credential_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('network_node_id')->nullable()->constrained('network_nodes')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('node_uuid', 120)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('outcome', 40)->index();
            $table->string('ip_hash', 64)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        // Der alte globale Bootstrap-Key darf nach dem Deployment weder als
        // Fallback dienen noch weiter im Klartext in den Settings verbleiben.
        $securitySetting = DB::table('settings')
            ->where('type', 'client_controller')
            ->where('key', 'security')
            ->first();

        if ($securitySetting) {
            $value = json_decode((string) $securitySetting->value, true);

            if (is_array($value)) {
                unset($value['bootstrap_api_key']);

                if ($value === []) {
                    DB::table('settings')->where('id', $securitySetting->id)->delete();
                } else {
                    DB::table('settings')->where('id', $securitySetting->id)->update([
                        'value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('network_nodes')
            ->select(['id', 'signing_secret_encrypted'])
            ->whereNotNull('signing_secret_encrypted')
            ->orderBy('id')
            ->chunkById(100, function ($nodes): void {
                foreach ($nodes as $node) {
                    try {
                        $plainTextKey = Crypt::decryptString((string) $node->signing_secret_encrypted);
                    } catch (Throwable) {
                        continue;
                    }

                    DB::table('network_nodes')->where('id', $node->id)->update([
                        'api_key' => $plainTextKey,
                        'node_secret' => null,
                        'updated_at' => now(),
                    ]);
                }
            });

        Schema::dropIfExists('node_credential_events');
        Schema::dropIfExists('node_enrollment_tokens');

        Schema::table('network_nodes', function (Blueprint $table): void {
            $table->dropUnique('network_nodes_api_key_hash_unique');
            $table->dropIndex('network_nodes_api_key_revoked_at_index');
            $table->dropColumn([
                'api_key_hash',
                'api_key_last_four',
                'signing_secret_encrypted',
                'api_key_rotated_at',
                'api_key_revoked_at',
                'last_authenticated_at',
            ]);
        });
    }
};
