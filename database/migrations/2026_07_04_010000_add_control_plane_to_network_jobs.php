<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_nodes', function (Blueprint $table): void {
            $table->foreignId('workflow_reservation_run_id')->nullable()->after('status')->constrained('workflow_runs')->nullOnDelete()->index();
        });
        Schema::table('devices', function (Blueprint $table): void {
            $table->foreignId('workflow_reservation_run_id')->nullable()->after('status')->constrained('workflow_runs')->nullOnDelete()->index();
        });
        Schema::table('network_jobs', function (Blueprint $table): void {
            $table->string('control_command', 40)->nullable()->after('attempt_count')->index();
            $table->unsignedBigInteger('control_sequence')->default(0)->after('control_command');
            $table->json('control_payload_json')->nullable()->after('control_sequence');
            $table->timestamp('control_requested_at')->nullable()->after('control_payload_json');
            $table->timestamp('control_acknowledged_at')->nullable()->after('control_requested_at');
            $table->timestamp('control_deadline_at')->nullable()->after('control_acknowledged_at')->index();
            $table->timestamp('unreachable_at')->nullable()->after('control_deadline_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('network_jobs', function (Blueprint $table): void {
            $table->dropIndex(['control_command']);
            $table->dropIndex(['control_deadline_at']);
            $table->dropIndex(['unreachable_at']);
            $table->dropColumn([
                'control_command',
                'control_sequence',
                'control_payload_json',
                'control_requested_at',
                'control_acknowledged_at',
                'control_deadline_at',
                'unreachable_at',
            ]);
        });
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('workflow_reservation_run_id');
        });
        Schema::table('network_nodes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('workflow_reservation_run_id');
        });
    }
};
