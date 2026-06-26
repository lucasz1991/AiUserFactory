<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflows')) {
            Schema::create('workflows', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('category', 80)->default('automation')->index();
                $table->boolean('is_active')->default(true)->index();
                $table->string('trigger_type', 80)->default('manual')->index();
                $table->json('settings_json')->nullable();
                $table->timestamp('last_run_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workflow_steps')) {
            Schema::create('workflow_steps', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
                $table->string('name');
                $table->string('type', 120)->index();
                $table->string('action_key', 191)->nullable()->index();
                $table->unsignedInteger('position')->default(0);
                $table->boolean('is_enabled')->default(true)->index();
                $table->json('config_json')->nullable();
                $table->unsignedTinyInteger('retry_attempts')->default(0);
                $table->unsignedInteger('wait_after_seconds')->default(0);
                $table->timestamps();

                $table->index(['workflow_id', 'position'], 'workflow_steps_workflow_position_idx');
            });
        }

        if (! Schema::hasTable('workflow_runs')) {
            Schema::create('workflow_runs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('run_uuid')->unique();
                $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
                $table->foreignId('current_workflow_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
                $table->string('status', 50)->default('queued')->index();
                $table->string('requested_by')->nullable();
                $table->timestamp('queued_at')->nullable()->index();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('finished_at')->nullable();
                $table->json('context_json')->nullable();
                $table->json('result_json')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workflow_step_runs')) {
            Schema::create('workflow_step_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();
                $table->foreignId('workflow_step_id')->constrained('workflow_steps')->cascadeOnDelete();
                $table->string('status', 50)->default('queued')->index();
                $table->string('external_run_type', 80)->nullable()->index();
                $table->string('external_run_id', 191)->nullable()->index();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('finished_at')->nullable();
                $table->unsignedBigInteger('duration_ms')->nullable();
                $table->json('logs_json')->nullable();
                $table->json('result_json')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->unique(['workflow_run_id', 'workflow_step_id'], 'workflow_step_runs_unique_step');
                $table->index(['workflow_run_id', 'status'], 'workflow_step_runs_run_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_step_runs');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflows');
    }
};
