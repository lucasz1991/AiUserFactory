<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflow_runs') || Schema::hasColumn('workflow_runs', 'duration_ms')) {
            return;
        }

        Schema::table('workflow_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('duration_ms')->nullable()->after('finished_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workflow_runs') || ! Schema::hasColumn('workflow_runs', 'duration_ms')) {
            return;
        }

        Schema::table('workflow_runs', function (Blueprint $table): void {
            $table->dropColumn('duration_ms');
        });
    }
};
