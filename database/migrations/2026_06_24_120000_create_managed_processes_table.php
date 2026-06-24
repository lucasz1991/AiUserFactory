<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('managed_processes')) {
            return;
        }

        Schema::create('managed_processes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('pid')->unique();
            $table->unsignedBigInteger('parent_pid')->nullable()->index();
            $table->unsignedBigInteger('family_root_pid')->nullable()->index();
            $table->string('process_type', 80)->default('app-process')->index();
            $table->string('executable')->nullable();
            $table->string('script_name')->nullable()->index();
            $table->text('command')->nullable();
            $table->string('short_command', 500)->nullable();
            $table->string('status', 50)->default('running')->index();
            $table->boolean('is_managed')->default(true)->index();
            $table->boolean('is_root')->default(false)->index();
            $table->boolean('is_idle_suspect')->default(false)->index();
            $table->decimal('cpu_percent', 8, 2)->nullable();
            $table->decimal('memory_mb', 10, 2)->nullable();
            $table->unsignedBigInteger('elapsed_seconds')->default(0);
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('detected_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('exited_at')->nullable()->index();
            $table->timestamp('last_action_at')->nullable();
            $table->text('action_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_processes');
    }
};
