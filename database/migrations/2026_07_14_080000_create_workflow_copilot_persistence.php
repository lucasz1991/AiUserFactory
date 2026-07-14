<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflow_copilot_sessions')) {
            Schema::create('workflow_copilot_sessions', function (Blueprint $table): void {
                $table->id();
                $table->uuid('session_uuid')->unique();
                $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
                $table->foreignId('person_id')->nullable()->constrained('persons')->nullOnDelete();
                $table->foreignId('active_workflow_run_id')->nullable()->constrained('workflow_runs')->nullOnDelete();
                $table->string('status', 40)->default('running')->index();
                $table->string('phase', 40)->default('executing')->index();
                $table->string('execution_target', 40)->default('system');
                $table->text('goal')->nullable();
                $table->json('success_criteria_json')->nullable();
                $table->json('workflow_inputs_json')->nullable();
                $table->json('budget_json')->nullable();
                $table->json('usage_json')->nullable();
                $table->json('state_json')->nullable();
                $table->unsignedBigInteger('current_revision')->default(0);
                $table->unsignedInteger('repair_round')->default(0);
                $table->unsignedBigInteger('last_event_sequence')->default(0);
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('paused_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('last_activity_at')->nullable()->index();
                $table->timestamps();

                $table->index(['workflow_id', 'status'], 'workflow_copilot_sessions_workflow_status_idx');
            });
        }

        if (! Schema::hasTable('workflow_copilot_events')) {
            Schema::create('workflow_copilot_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workflow_copilot_session_id')
                    ->constrained('workflow_copilot_sessions')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('sequence');
                $table->string('event_type', 100)->index();
                $table->string('phase', 40)->nullable()->index();
                $table->string('level', 20)->default('info')->index();
                $table->text('message');
                $table->json('payload_json')->nullable();
                $table->boolean('is_milestone')->default(false)->index();
                $table->timestamp('occurred_at')->index();
                $table->timestamp('created_at')->nullable();

                $table->unique(
                    ['workflow_copilot_session_id', 'sequence'],
                    'workflow_copilot_events_session_sequence_unique',
                );
            });
        }

        if (! Schema::hasTable('workflow_revisions')) {
            Schema::create('workflow_revisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workflow_copilot_session_id')
                    ->constrained('workflow_copilot_sessions')
                    ->cascadeOnDelete();
                $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
                $table->unsignedBigInteger('revision_number');
                $table->unsignedBigInteger('parent_revision_number')->nullable();
                $table->string('actor', 80)->default('copilot');
                $table->text('reason');
                $table->json('before_snapshot_json');
                $table->json('after_snapshot_json');
                $table->json('diff_json');
                $table->boolean('is_verified')->default(false)->index();
                $table->timestamp('verified_at')->nullable()->index();
                $table->timestamp('created_at')->nullable();

                $table->unique(['workflow_id', 'revision_number'], 'workflow_revisions_workflow_number_unique');
                $table->index(
                    ['workflow_copilot_session_id', 'revision_number'],
                    'workflow_revisions_session_number_idx',
                );
            });
        }

        if (! Schema::hasTable('workflow_task_attempts')) {
            Schema::create('workflow_task_attempts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workflow_copilot_session_id')
                    ->constrained('workflow_copilot_sessions')
                    ->cascadeOnDelete();
                $table->foreignId('workflow_run_id')->nullable()->constrained('workflow_runs')->nullOnDelete();
                $table->foreignId('workflow_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
                $table->foreignId('workflow_revision_id')->nullable()->constrained('workflow_revisions')->nullOnDelete();
                $table->unsignedBigInteger('attempt_number');
                $table->string('kind', 30)->default('regular')->index();
                $table->string('status', 40)->default('queued')->index();
                $table->string('task_key', 191)->nullable()->index();
                $table->string('task_title')->nullable();
                $table->json('task_definition_json')->nullable();
                $table->json('input_json')->nullable();
                $table->json('result_json')->nullable();
                $table->text('error_message')->nullable();
                $table->json('side_effects_json')->nullable();
                $table->json('artifacts_json')->nullable();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('finished_at')->nullable();
                $table->unsignedBigInteger('duration_ms')->nullable();
                $table->timestamps();

                $table->unique(
                    ['workflow_copilot_session_id', 'attempt_number'],
                    'workflow_task_attempts_session_number_unique',
                );
                $table->index(['workflow_run_id', 'status'], 'workflow_task_attempts_run_status_idx');
            });
        }

        if (! Schema::hasTable('workflow_run_checkpoints')) {
            Schema::create('workflow_run_checkpoints', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workflow_copilot_session_id')
                    ->constrained('workflow_copilot_sessions')
                    ->cascadeOnDelete();
                $table->foreignId('workflow_run_id')->nullable()->constrained('workflow_runs')->nullOnDelete();
                $table->foreignId('workflow_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
                $table->foreignId('workflow_task_attempt_id')
                    ->nullable()
                    ->constrained('workflow_task_attempts')
                    ->nullOnDelete();
                $table->foreignId('workflow_revision_id')->nullable()->constrained('workflow_revisions')->nullOnDelete();
                $table->foreignId('screenshot_artifact_id')
                    ->nullable()
                    ->constrained('workflow_run_artifacts')
                    ->nullOnDelete();
                $table->unsignedBigInteger('sequence');
                $table->string('phase', 40)->nullable()->index();
                $table->string('task_key', 191)->nullable()->index();
                $table->json('cursor_json')->nullable();
                $table->json('context_json')->nullable();
                $table->json('browser_state_json')->nullable();
                $table->json('dom_snapshot_json')->nullable();
                $table->string('state_signature', 191)->nullable()->index();
                $table->json('side_effect_ledger_json')->nullable();
                $table->boolean('is_reproducible')->default(true)->index();
                $table->timestamp('created_at')->nullable();

                $table->unique(
                    ['workflow_copilot_session_id', 'sequence'],
                    'workflow_run_checkpoints_session_sequence_unique',
                );
                $table->index(['workflow_run_id', 'sequence'], 'workflow_run_checkpoints_run_sequence_idx');
            });
        }

        if (Schema::hasTable('workflows') && ! Schema::hasColumn('workflows', 'active_workflow_copilot_session_id')) {
            Schema::table('workflows', function (Blueprint $table): void {
                $table->foreignId('active_workflow_copilot_session_id')
                    ->nullable()
                    ->after('is_locked')
                    ->constrained('workflow_copilot_sessions')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('workflows') && ! Schema::hasColumn('workflows', 'copilot_revision')) {
            Schema::table('workflows', function (Blueprint $table): void {
                $table->unsignedBigInteger('copilot_revision')->default(0)->after('active_workflow_copilot_session_id');
                $table->timestamp('copilot_locked_at')->nullable()->after('copilot_revision');
                $table->string('copilot_verification_status', 40)->nullable()->index()->after('copilot_locked_at');
                $table->timestamp('copilot_verified_at')->nullable()->after('copilot_verification_status');
            });
        }

        if (Schema::hasTable('workflow_runs') && ! Schema::hasColumn('workflow_runs', 'workflow_copilot_session_id')) {
            Schema::table('workflow_runs', function (Blueprint $table): void {
                $table->foreignId('workflow_copilot_session_id')
                    ->nullable()
                    ->after('workflow_id')
                    ->constrained('workflow_copilot_sessions')
                    ->nullOnDelete();
                $table->unsignedBigInteger('workflow_revision')->nullable()->after('workflow_copilot_session_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('workflow_runs') && Schema::hasColumn('workflow_runs', 'workflow_copilot_session_id')) {
            Schema::table('workflow_runs', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('workflow_copilot_session_id');
                $table->dropColumn('workflow_revision');
            });
        }

        if (Schema::hasTable('workflows') && Schema::hasColumn('workflows', 'active_workflow_copilot_session_id')) {
            Schema::table('workflows', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('active_workflow_copilot_session_id');
            });
        }

        if (Schema::hasTable('workflows') && Schema::hasColumn('workflows', 'copilot_revision')) {
            Schema::table('workflows', function (Blueprint $table): void {
                $table->dropColumn([
                    'copilot_revision',
                    'copilot_locked_at',
                    'copilot_verification_status',
                    'copilot_verified_at',
                ]);
            });
        }

        Schema::dropIfExists('workflow_run_checkpoints');
        Schema::dropIfExists('workflow_task_attempts');
        Schema::dropIfExists('workflow_revisions');
        Schema::dropIfExists('workflow_copilot_events');
        Schema::dropIfExists('workflow_copilot_sessions');
    }
};
