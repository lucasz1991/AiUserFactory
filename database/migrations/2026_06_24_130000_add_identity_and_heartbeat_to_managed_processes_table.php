<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('managed_processes')) {
            return;
        }

        Schema::table('managed_processes', function (Blueprint $table): void {
            if (! Schema::hasColumn('managed_processes', 'process_key')) {
                $table->string('process_key', 180)->nullable()->index()->after('family_root_pid');
            }

            if (! Schema::hasColumn('managed_processes', 'run_id')) {
                $table->string('run_id', 80)->nullable()->index()->after('process_key');
            }

            if (! Schema::hasColumn('managed_processes', 'run_type')) {
                $table->string('run_type', 80)->nullable()->index()->after('run_id');
            }

            if (! Schema::hasColumn('managed_processes', 'process_role')) {
                $table->string('process_role', 80)->nullable()->index()->after('run_type');
            }

            if (! Schema::hasColumn('managed_processes', 'runtime_config_path')) {
                $table->string('runtime_config_path', 1000)->nullable()->after('short_command');
            }

            if (! Schema::hasColumn('managed_processes', 'status_path')) {
                $table->string('status_path', 1000)->nullable()->after('runtime_config_path');
            }

            if (! Schema::hasColumn('managed_processes', 'heartbeat_at')) {
                $table->timestamp('heartbeat_at')->nullable()->index()->after('last_seen_at');
            }

            if (! Schema::hasColumn('managed_processes', 'heartbeat_age_seconds')) {
                $table->unsignedInteger('heartbeat_age_seconds')->nullable()->after('heartbeat_at');
            }

            if (! Schema::hasColumn('managed_processes', 'last_stage')) {
                $table->string('last_stage', 160)->nullable()->index()->after('heartbeat_age_seconds');
            }

            if (! Schema::hasColumn('managed_processes', 'last_message')) {
                $table->text('last_message')->nullable()->after('last_stage');
            }

            if (! Schema::hasColumn('managed_processes', 'restart_count')) {
                $table->unsignedInteger('restart_count')->default(0)->after('last_message');
            }

            if (! Schema::hasColumn('managed_processes', 'last_restart_at')) {
                $table->timestamp('last_restart_at')->nullable()->after('restart_count');
            }

            if (! Schema::hasColumn('managed_processes', 'supervisor_checked_at')) {
                $table->timestamp('supervisor_checked_at')->nullable()->after('last_restart_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('managed_processes')) {
            return;
        }

        Schema::table('managed_processes', function (Blueprint $table): void {
            foreach ([
                'supervisor_checked_at',
                'last_restart_at',
                'restart_count',
                'last_message',
                'last_stage',
                'heartbeat_age_seconds',
                'heartbeat_at',
                'status_path',
                'runtime_config_path',
                'process_role',
                'run_type',
                'run_id',
                'process_key',
            ] as $column) {
                if (Schema::hasColumn('managed_processes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
