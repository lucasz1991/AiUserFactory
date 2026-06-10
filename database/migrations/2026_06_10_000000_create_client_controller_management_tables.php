<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('network_nodes')) {
            Schema::create('network_nodes', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->uuid('node_uuid')->unique();
                $table->string('api_key', 191)->unique();
                $table->string('node_secret', 191)->nullable();
                $table->string('current_server_domain')->nullable();
                $table->string('last_successful_server_domain')->nullable();
                $table->string('public_ip', 64)->nullable();
                $table->string('country', 120)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('os', 120)->nullable();
                $table->string('version', 120)->nullable();
                $table->boolean('is_online')->default(false)->index();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->json('capabilities_json')->nullable();
                $table->json('settings_json')->nullable();
                $table->boolean('allow_server_rebind')->default(true);
                $table->string('status', 50)->default('active')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('devices')) {
            Schema::create('devices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('network_node_id')->nullable()->constrained('network_nodes')->nullOnDelete();
                $table->string('name');
                $table->string('platform', 50)->default('android')->index();
                $table->string('device_uuid')->unique();
                $table->string('adb_serial')->nullable()->index();
                $table->string('appium_endpoint')->nullable();
                $table->string('status', 50)->default('offline')->index();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->json('settings_json')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('network_targets')) {
            Schema::create('network_targets', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('url');
                $table->boolean('is_active')->default(true)->index();
                $table->boolean('allow_browser')->default(false);
                $table->boolean('allow_api')->default(true);
                $table->boolean('allow_screenshots')->default(false);
                $table->unsignedInteger('timeout')->default(30);
                $table->json('settings_json')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('person_actions')) {
            Schema::create('person_actions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
                $table->foreignId('network_target_id')->nullable()->constrained('network_targets')->nullOnDelete();
                $table->string('action_type', 120)->index();
                $table->json('action_payload')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->string('schedule_expression')->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('network_jobs')) {
            Schema::create('network_jobs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('job_uuid')->unique();
                $table->foreignId('network_node_id')->constrained('network_nodes')->cascadeOnDelete();
                $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
                $table->foreignId('person_action_id')->nullable()->constrained('person_actions')->nullOnDelete();
                $table->foreignId('network_target_id')->nullable()->constrained('network_targets')->nullOnDelete();
                $table->string('type', 120)->index();
                $table->json('payload_json');
                $table->text('signature')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->string('status', 50)->default('pending')->index();
                $table->string('requested_by')->nullable();
                $table->timestamp('queued_at')->nullable()->index();
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->json('result_json')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('action_executions')) {
            Schema::create('action_executions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('network_job_id')->nullable()->constrained('network_jobs')->nullOnDelete();
                $table->foreignId('person_action_id')->nullable()->constrained('person_actions')->nullOnDelete();
                $table->foreignId('network_node_id')->constrained('network_nodes')->cascadeOnDelete();
                $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
                $table->string('status', 50)->default('success')->index();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('finished_at')->nullable();
                $table->unsignedBigInteger('duration_ms')->nullable();
                $table->json('logs_json')->nullable();
                $table->json('result_json')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('screenshots')) {
            Schema::create('screenshots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('action_execution_id')->nullable()->constrained('action_executions')->nullOnDelete();
                $table->foreignId('network_job_id')->nullable()->constrained('network_jobs')->nullOnDelete();
                $table->string('path');
                $table->string('disk', 50)->default('private');
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->timestamp('captured_at')->nullable()->index();
                $table->json('meta_json')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('node_heartbeats')) {
            Schema::create('node_heartbeats', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('network_node_id')->constrained('network_nodes')->cascadeOnDelete();
                $table->string('status', 50)->default('online')->index();
                $table->json('payload_json')->nullable();
                $table->timestamp('received_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('node_server_bindings')) {
            Schema::create('node_server_bindings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('network_node_id')->constrained('network_nodes')->cascadeOnDelete();
                $table->string('server_domain');
                $table->string('status', 50)->default('bound')->index();
                $table->timestamp('bound_at')->nullable()->index();
                $table->timestamp('last_successful_contact_at')->nullable();
                $table->json('settings_json')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('node_rebind_logs')) {
            Schema::create('node_rebind_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('network_node_id')->constrained('network_nodes')->cascadeOnDelete();
                $table->string('old_server_domain')->nullable();
                $table->string('new_server_domain');
                $table->string('status', 50)->default('requested')->index();
                $table->string('requested_by')->nullable();
                $table->timestamp('requested_at')->nullable()->index();
                $table->timestamp('completed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('node_rebind_logs');
        Schema::dropIfExists('node_server_bindings');
        Schema::dropIfExists('node_heartbeats');
        Schema::dropIfExists('screenshots');
        Schema::dropIfExists('action_executions');
        Schema::dropIfExists('network_jobs');
        Schema::dropIfExists('person_actions');
        Schema::dropIfExists('network_targets');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('network_nodes');
    }
};
