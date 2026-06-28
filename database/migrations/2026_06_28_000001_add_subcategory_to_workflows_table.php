<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workflows', 'subcategory')) {
            Schema::table('workflows', function (Blueprint $table): void {
                $table->string('subcategory', 80)->nullable()->index()->after('category');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('workflows', 'subcategory')) {
            Schema::table('workflows', function (Blueprint $table): void {
                $table->dropColumn('subcategory');
            });
        }
    }
};
