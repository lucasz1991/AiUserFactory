<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_run_leases', function (Blueprint $table): void {
            $table->foreignId('workflow_run_id')->primary()->constrained('workflow_runs')->cascadeOnDelete();
            $table->uuid('token')->nullable();
            $table->string('operation', 40)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_run_leases');
    }
};
