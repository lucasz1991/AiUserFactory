<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_studio_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_studio_sessions', 'control_owner')) {
                $table->string('control_owner', 20)->default('user')->after('mode')->index();
            }
            if (! Schema::hasColumn('workflow_studio_sessions', 'mode_locked_at')) {
                $table->timestamp('mode_locked_at')->nullable()->after('control_owner')->index();
            }
        });

        DB::table('workflow_studio_sessions')
            ->where('mode', 'autonomous')
            ->update(['control_owner' => 'copilot']);

        if (! Schema::hasTable('workflow_optimization_plans')) {
            Schema::create('workflow_optimization_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows', 'id', 'wop_workflow_fk')->cascadeOnDelete();
            $table->foreignId('workflow_copilot_session_id')->unique()->constrained('workflow_copilot_sessions', 'id', 'wop_copilot_fk')->cascadeOnDelete();
            $table->foreignId('workflow_studio_session_id')->nullable()->constrained('workflow_studio_sessions', 'id', 'wop_studio_fk')->nullOnDelete();
            $table->string('status', 30)->default('planned')->index();
            $table->string('goal_hash', 64)->index();
            $table->json('plan_json');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('verified_items')->default(0);
            $table->unsignedBigInteger('finalized_revision')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            });
        }

        if (! Schema::hasTable('workflow_optimization_plan_items')) {
            Schema::create('workflow_optimization_plan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_optimization_plan_id')->constrained('workflow_optimization_plans', 'id', 'wopi_plan_fk')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->unsignedInteger('step_index');
            $table->unsignedInteger('task_index');
            $table->string('step_action_key', 191)->index();
            $table->string('task_key', 191)->index();
            $table->string('catalog_task_key', 191)->index();
            $table->string('status', 30)->default('planned')->index();
            $table->json('blueprint_json');
            $table->unsignedBigInteger('candidate_revision')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('materialized_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['workflow_optimization_plan_id', 'sequence'], 'workflow_optimization_items_plan_sequence_unique');
            });
        }

        if (! Schema::hasTable('workflow_revision_evidence')) {
            Schema::create('workflow_revision_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows', 'id', 'wre_workflow_fk')->cascadeOnDelete();
            $table->foreignId('workflow_copilot_session_id')->nullable()->constrained('workflow_copilot_sessions', 'id', 'wre_copilot_fk')->nullOnDelete();
            $table->foreignId('workflow_studio_session_id')->nullable()->constrained('workflow_studio_sessions', 'id', 'wre_studio_fk')->nullOnDelete();
            $table->foreignId('workflow_run_id')->nullable()->constrained('workflow_runs', 'id', 'wre_run_fk')->nullOnDelete();
            $table->foreignId('workflow_step_id')->nullable()->constrained('workflow_steps', 'id', 'wre_step_fk')->nullOnDelete();
            $table->unsignedBigInteger('workflow_revision')->default(0)->index();
            $table->string('task_key', 191)->nullable()->index();
            $table->string('logical_outcome', 40)->index();
            $table->string('route_disposition', 40)->index();
            $table->boolean('successful')->default(false)->index();
            $table->string('error_signature', 64)->nullable()->index();
            $table->json('evidence_json')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_revision_evidence');
        Schema::dropIfExists('workflow_optimization_plan_items');
        Schema::dropIfExists('workflow_optimization_plans');

        Schema::table('workflow_studio_sessions', function (Blueprint $table): void {
            $table->dropIndex(['mode_locked_at']);
            $table->dropIndex(['control_owner']);
            $table->dropColumn(['control_owner', 'mode_locked_at']);
        });
    }
};
