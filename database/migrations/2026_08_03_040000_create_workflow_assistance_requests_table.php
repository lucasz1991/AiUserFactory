<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_assistance_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_uuid')->unique();
            $table->char('source_key', 64)->unique();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->foreignId('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();
            // Nullable + unique is portable across MySQL/SQLite and guarantees
            // at most one unresolved human handoff for a workflow run.
            $table->foreignId('open_workflow_run_id')->nullable()->constrained('workflow_runs')->nullOnDelete()->unique();
            $table->foreignId('workflow_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            $table->foreignId('workflow_step_run_id')->nullable()->constrained('workflow_step_runs')->nullOnDelete();
            $table->foreignId('workflow_studio_session_id')->nullable()->constrained('workflow_studio_sessions')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40)->default('captcha')->index();
            $table->string('status', 30)->default('pending')->index();
            $table->string('priority', 20)->default('high')->index();
            $table->string('reason_code', 80)->default('captcha_detected')->index();
            $table->string('task_key', 191)->index();
            $table->string('resume_task_key', 191)->nullable();
            $table->string('browser_window', 120)->default('main');
            $table->text('current_url')->nullable();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->json('cursor_json')->nullable();
            $table->json('browser_state_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('requested_at')->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('resume_dispatched_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'requested_at'], 'workflow_assist_status_requested_idx');
            $table->index(['workflow_run_id', 'status'], 'workflow_assist_run_status_idx');
        });

        Schema::create('workflow_assistance_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_assistance_request_id');
            $table->foreign(
                'workflow_assistance_request_id',
                'workflow_assist_event_request_fk',
            )->references('id')->on('workflow_assistance_requests')->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('event_type', 80)->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message');
            $table->json('payload_json')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at')->nullable();
            $table->unique(
                ['workflow_assistance_request_id', 'sequence'],
                'workflow_assist_events_request_sequence_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_assistance_events');
        Schema::dropIfExists('workflow_assistance_requests');
    }
};
