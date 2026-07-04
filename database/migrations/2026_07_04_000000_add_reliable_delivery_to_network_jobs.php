<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_jobs', function (Blueprint $table): void {
            $table->foreignId('workflow_run_id')->nullable()->after('network_target_id')->constrained('workflow_runs')->nullOnDelete();
            $table->unsignedSmallInteger('payload_version')->default(1)->after('type');
            $table->string('lease_token_hash', 64)->nullable()->after('signature');
            $table->timestamp('lease_expires_at')->nullable()->after('expires_at')->index();
            $table->timestamp('last_progress_at')->nullable()->after('dispatched_at')->index();
            $table->unsignedBigInteger('last_sequence')->default(0)->after('last_progress_at');
            $table->unsignedInteger('attempt_count')->default(0)->after('last_sequence');
        });

        Schema::create('network_job_progress_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('network_job_id')->constrained('network_jobs')->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('kind', 40)->default('progress');
            $table->json('payload_json')->nullable();
            $table->string('screenshot_relative_path')->nullable();
            $table->timestamp('received_at')->index();
            $table->timestamps();

            $table->unique(['network_job_id', 'sequence'], 'network_job_progress_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_job_progress_events');

        Schema::table('network_jobs', function (Blueprint $table): void {
            $table->dropForeign(['workflow_run_id']);
            $table->dropIndex(['lease_expires_at']);
            $table->dropIndex(['last_progress_at']);
            $table->dropColumn([
                'workflow_run_id',
                'payload_version',
                'lease_token_hash',
                'lease_expires_at',
                'last_progress_at',
                'last_sequence',
                'attempt_count',
            ]);
        });
    }
};
