<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scraper_profiles') || Schema::hasColumn('scraper_profiles', 'social_accounts')) {
            return;
        }

        Schema::table('scraper_profiles', function (Blueprint $table): void {
            $table->json('social_accounts')->nullable()->after('bot_status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('scraper_profiles') || ! Schema::hasColumn('scraper_profiles', 'social_accounts')) {
            return;
        }

        Schema::table('scraper_profiles', function (Blueprint $table): void {
            $table->dropColumn('social_accounts');
        });
    }
};
