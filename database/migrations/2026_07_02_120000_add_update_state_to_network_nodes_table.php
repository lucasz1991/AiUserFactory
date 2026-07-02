<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_nodes', function (Blueprint $table): void {
            $table->string('update_status', 50)->default('idle')->index()->after('version');
            $table->string('update_target_version', 120)->nullable()->after('update_status');
            $table->timestamp('update_requested_at')->nullable()->after('update_target_version');
            $table->timestamp('update_installed_at')->nullable()->after('update_requested_at');
            $table->text('update_error')->nullable()->after('update_installed_at');
        });
    }

    public function down(): void
    {
        Schema::table('network_nodes', function (Blueprint $table): void {
            $table->dropIndex(['update_status']);
            $table->dropColumn([
                'update_status',
                'update_target_version',
                'update_requested_at',
                'update_installed_at',
                'update_error',
            ]);
        });
    }
};
