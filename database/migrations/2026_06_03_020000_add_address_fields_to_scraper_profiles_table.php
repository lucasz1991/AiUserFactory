<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scraper_profiles')) {
            return;
        }

        Schema::table('scraper_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('scraper_profiles', 'person_address_line1')) {
                $table->string('person_address_line1')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_address_line2')) {
                $table->string('person_address_line2')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_postal_code')) {
                $table->string('person_postal_code')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_state')) {
                $table->string('person_state')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('scraper_profiles')) {
            return;
        }

        Schema::table('scraper_profiles', function (Blueprint $table): void {
            foreach (['person_state', 'person_postal_code', 'person_address_line2', 'person_address_line1'] as $column) {
                if (Schema::hasColumn('scraper_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
