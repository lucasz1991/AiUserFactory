<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_jobs', function (Blueprint $table): void {
            $table->timestamp('signaled_at')->nullable()->after('queued_at')->index();
            $table->timestamp('pulled_at')->nullable()->after('signaled_at')->index();
            $table->timestamp('started_at')->nullable()->after('pulled_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('network_jobs', function (Blueprint $table): void {
            $table->dropColumn(['signaled_at', 'pulled_at', 'started_at']);
        });
    }
};
