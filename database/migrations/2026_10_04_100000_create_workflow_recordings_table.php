<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_recordings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('recording_uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 180);
            $table->text('start_url');
            $table->string('status', 20)->default('draft');
            $table->longText('events_json')->nullable();
            $table->longText('bindings_json')->nullable();
            $table->longText('runtime_json')->nullable();
            $table->longText('state_json')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('last_event_sequence')->default(0);
            $table->uuid('runtime_generation')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_recordings');
    }
};
