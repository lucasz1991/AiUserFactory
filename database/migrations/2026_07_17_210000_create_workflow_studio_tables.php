<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_studio_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('session_uuid')->unique();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('persons')->nullOnDelete();
            $table->foreignId('active_workflow_run_id')->nullable()->constrained('workflow_runs')->nullOnDelete();
            $table->foreignId('workflow_copilot_session_id')->nullable()->constrained('workflow_copilot_sessions')->nullOnDelete();
            $table->string('mode', 30)->default('manual')->index();
            $table->string('permission_mode', 30)->default('ask_critical')->index();
            $table->string('status', 40)->default('draft')->index();
            $table->text('goal')->nullable();
            $table->json('success_criteria_json')->nullable();
            $table->json('workflow_inputs_json')->nullable();
            $table->json('budget_json')->nullable();
            $table->json('usage_json')->nullable();
            $table->json('state_json')->nullable();
            $table->unsignedBigInteger('current_revision')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamps();
            $table->index(['workflow_id', 'status'], 'workflow_studio_sessions_workflow_status_idx');
        });

        Schema::table('workflow_runs', function (Blueprint $table): void {
            $table->foreignId('workflow_studio_session_id')
                ->nullable()
                ->after('workflow_copilot_session_id')
                ->constrained('workflow_studio_sessions')
                ->nullOnDelete();
        });

        Schema::create('workflow_studio_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_studio_session_id')->constrained('workflow_studio_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('event_type', 100)->index();
            $table->string('level', 20)->default('info')->index();
            $table->text('message');
            $table->json('payload_json')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at')->nullable();
            $table->unique(['workflow_studio_session_id', 'sequence'], 'workflow_studio_events_session_sequence_unique');
        });

        Schema::create('workflow_studio_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_studio_session_id')->nullable()->constrained('workflow_studio_sessions')->nullOnDelete();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->unsignedBigInteger('revision_number');
            $table->unsignedBigInteger('parent_revision_number')->nullable();
            $table->string('actor', 80)->default('user');
            $table->text('reason');
            $table->json('before_snapshot_json');
            $table->json('after_snapshot_json');
            $table->json('diff_json');
            $table->boolean('is_verified')->default(false)->index();
            $table->timestamp('verified_at')->nullable()->index();
            $table->timestamp('created_at')->nullable();
            $table->unique(['workflow_id', 'revision_number'], 'workflow_studio_revisions_workflow_number_unique');
        });

        Schema::create('workflow_studio_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_studio_session_id')->constrained('workflow_studio_sessions')->cascadeOnDelete();
            $table->foreignId('workflow_run_id')->nullable()->constrained('workflow_runs')->nullOnDelete();
            $table->foreignId('workflow_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            $table->foreignId('workflow_studio_revision_id')->nullable()->constrained('workflow_studio_revisions')->nullOnDelete();
            $table->foreignId('screenshot_artifact_id')->nullable()->constrained('workflow_run_artifacts')->nullOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('name')->nullable();
            $table->string('phase', 40)->nullable()->index();
            $table->string('task_key', 191)->nullable()->index();
            $table->json('cursor_json')->nullable();
            $table->json('context_json')->nullable();
            $table->json('browser_state_json')->nullable();
            $table->json('dom_snapshot_json')->nullable();
            $table->longText('encrypted_runtime_context')->nullable();
            $table->string('state_signature', 191)->nullable()->index();
            $table->json('side_effect_ledger_json')->nullable();
            $table->boolean('is_reproducible')->default(true)->index();
            $table->timestamp('created_at')->nullable();
            $table->unique(['workflow_studio_session_id', 'sequence'], 'workflow_studio_checkpoints_session_sequence_unique');
        });

        if (Schema::hasTable('workflow_copilot_sessions')) {
            DB::table('workflow_copilot_sessions')->orderBy('id')->get()->each(function (object $legacy): void {
                $studioId = DB::table('workflow_studio_sessions')->insertGetId([
                    'session_uuid' => (string) Str::uuid(),
                    'workflow_id' => $legacy->workflow_id,
                    'person_id' => $legacy->person_id,
                    'active_workflow_run_id' => $legacy->active_workflow_run_id,
                    'workflow_copilot_session_id' => $legacy->id,
                    'mode' => 'autonomous',
                    'permission_mode' => filter_var(data_get(json_decode($legacy->budget_json ?? '{}', true), 'auto_execute_workflow_actions', true), FILTER_VALIDATE_BOOL)
                        ? 'ask_critical'
                        : 'ask_all',
                    'status' => $legacy->status,
                    'goal' => $legacy->goal,
                    'success_criteria_json' => $legacy->success_criteria_json,
                    'workflow_inputs_json' => $legacy->workflow_inputs_json,
                    'budget_json' => $legacy->budget_json,
                    'usage_json' => $legacy->usage_json,
                    'state_json' => $legacy->state_json,
                    'current_revision' => $legacy->current_revision,
                    'started_at' => $legacy->started_at,
                    'paused_at' => $legacy->paused_at,
                    'finished_at' => $legacy->finished_at,
                    'last_activity_at' => $legacy->last_activity_at,
                    'created_at' => $legacy->created_at,
                    'updated_at' => $legacy->updated_at,
                ]);

                DB::table('workflow_runs')
                    ->where('workflow_copilot_session_id', $legacy->id)
                    ->update(['workflow_studio_session_id' => $studioId]);

                if (Schema::hasTable('workflow_copilot_events')) {
                    DB::table('workflow_copilot_events')
                        ->where('workflow_copilot_session_id', $legacy->id)
                        ->orderBy('sequence')
                        ->get()
                        ->each(fn (object $event) => DB::table('workflow_studio_events')->insert([
                            'workflow_studio_session_id' => $studioId,
                            'sequence' => $event->sequence,
                            'event_type' => $event->event_type,
                            'level' => $event->level,
                            'message' => $event->message,
                            'payload_json' => $event->payload_json,
                            'occurred_at' => $event->occurred_at,
                            'created_at' => $event->created_at,
                        ]));
                }

                $revisionMap = [];
                if (Schema::hasTable('workflow_revisions')) {
                    DB::table('workflow_revisions')
                        ->where('workflow_copilot_session_id', $legacy->id)
                        ->orderBy('revision_number')
                        ->get()
                        ->each(function (object $revision) use ($studioId, &$revisionMap): void {
                            $studioRevisionId = DB::table('workflow_studio_revisions')->insertGetId([
                                'workflow_studio_session_id' => $studioId,
                                'workflow_id' => $revision->workflow_id,
                                'revision_number' => $revision->revision_number,
                                'parent_revision_number' => $revision->parent_revision_number,
                                'actor' => $revision->actor,
                                'reason' => $revision->reason,
                                'before_snapshot_json' => $revision->before_snapshot_json,
                                'after_snapshot_json' => $revision->after_snapshot_json,
                                'diff_json' => $revision->diff_json,
                                'is_verified' => $revision->is_verified,
                                'verified_at' => $revision->verified_at,
                                'created_at' => $revision->created_at,
                            ]);
                            $revisionMap[(int) $revision->id] = $studioRevisionId;
                        });
                }

                if (Schema::hasTable('workflow_run_checkpoints')) {
                    DB::table('workflow_run_checkpoints')
                        ->where('workflow_copilot_session_id', $legacy->id)
                        ->orderBy('sequence')
                        ->get()
                        ->each(function (object $checkpoint) use ($studioId, $revisionMap): void {
                            $runtimeContext = json_decode((string) ($checkpoint->context_json ?? '{}'), true);
                            DB::table('workflow_studio_checkpoints')->insert([
                                'workflow_studio_session_id' => $studioId,
                                'workflow_run_id' => $checkpoint->workflow_run_id,
                                'workflow_step_id' => $checkpoint->workflow_step_id,
                                'workflow_studio_revision_id' => $revisionMap[(int) $checkpoint->workflow_revision_id] ?? null,
                                'screenshot_artifact_id' => $checkpoint->screenshot_artifact_id,
                                'sequence' => $checkpoint->sequence,
                                'name' => 'Importierter Copilot-Checkpoint #'.$checkpoint->sequence,
                                'phase' => $checkpoint->phase,
                                'task_key' => $checkpoint->task_key,
                                'cursor_json' => $checkpoint->cursor_json,
                                'context_json' => $checkpoint->context_json,
                                'browser_state_json' => $checkpoint->browser_state_json,
                                'dom_snapshot_json' => $checkpoint->dom_snapshot_json,
                                'encrypted_runtime_context' => Crypt::encryptString(json_encode(is_array($runtimeContext) ? $runtimeContext : [])),
                                'state_signature' => $checkpoint->state_signature,
                                'side_effect_ledger_json' => $checkpoint->side_effect_ledger_json,
                                'is_reproducible' => $checkpoint->is_reproducible,
                                'created_at' => $checkpoint->created_at,
                            ]);
                        });
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_studio_checkpoints');
        Schema::dropIfExists('workflow_studio_revisions');
        Schema::dropIfExists('workflow_studio_events');
        Schema::table('workflow_runs', fn (Blueprint $table) => $table->dropConstrainedForeignId('workflow_studio_session_id'));
        Schema::dropIfExists('workflow_studio_sessions');
    }
};
