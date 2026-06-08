<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('persons')) {
            Schema::create('persons', function (Blueprint $table): void {
                $table->id();
                $table->string('platform', 50)->default('instagram');
                $table->string('profile_key');
                $table->string('profile_label');
                $table->string('person_first_name')->nullable();
                $table->string('person_last_name')->nullable();
                $table->string('person_alias')->nullable();
                $table->date('person_date_of_birth')->nullable();
                $table->string('person_gender')->nullable();
                $table->string('person_email')->nullable();
                $table->string('person_phone')->nullable();
                $table->string('person_address_line1')->nullable();
                $table->string('person_address_line2')->nullable();
                $table->string('person_postal_code')->nullable();
                $table->string('person_state')->nullable();
                $table->string('person_country')->nullable();
                $table->string('person_city')->nullable();
                $table->string('person_timezone')->nullable();
                $table->text('person_notes')->nullable();
                $table->string('avatar_path')->nullable();
                $table->json('identity_profile')->nullable();
                $table->json('bot_profile')->nullable();
                $table->string('bot_status')->default('manual')->index();
                $table->json('social_accounts')->nullable();
                $table->string('browser_profile_path')->nullable();
                $table->string('cookie_file_path')->nullable();
                $table->boolean('persistent_profile_enabled')->default(true);
                $table->boolean('headless_enabled')->default(true);
                $table->boolean('auto_login_enabled')->default(false);
                $table->string('login_username')->nullable();
                $table->longText('login_password_encrypted')->nullable();
                $table->longText('login_password_base_encrypted')->nullable();
                $table->unsignedInteger('navigation_timeout_seconds')->default(120);
                $table->unsignedInteger('post_login_wait_ms')->default(2500);
                $table->unsignedInteger('typing_delay_ms')->default(35);
                $table->unsignedInteger('relationship_list_process_timeout_seconds')->default(14400);
                $table->unsignedInteger('relationship_list_max_scroll_rounds')->default(100000);
                $table->unsignedInteger('follower_list_max_items')->default(0);
                $table->unsignedInteger('following_list_max_items')->default(0);
                $table->boolean('is_primary')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->longText('cookie_payload')->nullable();
                $table->string('cookie_payload_hash', 64)->nullable();
                $table->unsignedInteger('cookie_count')->default(0);
                $table->boolean('session_cookie_present')->default(false);
                $table->timestamp('cookies_synced_at')->nullable();
                $table->timestamp('scrape_blocked_at')->nullable();
                $table->timestamp('scrape_blocked_until')->nullable();
                $table->string('scrape_blocked_reason')->nullable();
                $table->string('base_sync_status')->default('pending')->index();
                $table->timestamp('base_synced_at')->nullable();
                $table->text('base_sync_error')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['platform', 'profile_key'], 'persons_platform_key_unq');
                $table->index(['platform', 'is_active', 'is_primary'], 'persons_platform_active_idx');
                $table->index(['platform', 'is_active', 'scrape_blocked_until'], 'persons_scrape_block_idx');
                $table->index(['platform', 'login_username'], 'persons_platform_login_idx');
            });
        }

        $this->importLegacyLocalScraperProfiles();
    }

    public function down(): void
    {
        Schema::dropIfExists('persons');
    }

    private function importLegacyLocalScraperProfiles(): void
    {
        if (! Schema::hasTable('scraper_profiles') || DB::table('persons')->exists()) {
            return;
        }

        $personColumns = Schema::getColumnListing('persons');
        $legacyColumns = Schema::getColumnListing('scraper_profiles');

        DB::table('scraper_profiles')
            ->orderBy('id')
            ->chunk(100, function ($rows) use ($personColumns, $legacyColumns): void {
                foreach ($rows as $row) {
                    $payload = [];

                    foreach ($personColumns as $column) {
                        if ($column === 'id' || ! in_array($column, $legacyColumns, true)) {
                            continue;
                        }

                        $payload[$column] = $row->{$column};
                    }

                    if ($payload === []) {
                        continue;
                    }

                    $payload['base_sync_status'] = 'pending';

                    DB::table('persons')->insert($payload);
                }
            });
    }
};
