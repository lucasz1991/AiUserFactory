<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workflow_run_artifacts')) {
            return;
        }

        Schema::create('workflow_run_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->foreignId('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->foreignId('workflow_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            $table->foreignId('workflow_step_run_id')->nullable()->constrained('workflow_step_runs')->cascadeOnDelete();
            $table->unsignedInteger('step_position')->nullable();
            $table->string('step_action_key')->nullable()->index();
            $table->string('task_card_key')->nullable()->index();
            $table->string('phase', 20)->index();
            $table->string('artifact_type', 40)->index();
            $table->string('browser_window')->nullable()->index();
            $table->text('current_url')->nullable();
            $table->string('title')->nullable();
            $table->string('storage_disk', 80)->default('local');
            $table->string('storage_path')->nullable();
            $table->string('status', 40)->default('success')->index();
            $table->text('error_message')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['workflow_run_id', 'workflow_step_run_id'], 'workflow_run_artifacts_run_step_idx');
            $table->index(['workflow_step_run_id', 'phase', 'artifact_type'], 'workflow_run_artifacts_phase_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_run_artifacts');
    }
};
