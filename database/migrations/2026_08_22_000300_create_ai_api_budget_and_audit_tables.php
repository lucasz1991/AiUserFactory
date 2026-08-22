<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_api_daily_budgets', function (Blueprint $table): void {
            $table->id();
            $table->date('usage_date');
            $table->string('scope_type', 20);
            $table->unsignedBigInteger('scope_id');
            $table->unsignedBigInteger('committed_cost_microusd')->default(0);
            $table->unsignedBigInteger('reserved_tokens')->default(0);
            $table->unsignedInteger('request_count')->default(0);
            $table->timestamps();

            $table->unique(['usage_date', 'scope_type', 'scope_id'], 'ai_api_daily_budget_scope_unique');
        });

        Schema::create('ai_api_request_audits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Jetstream-Teams sind derzeit deaktiviert. Die ID wird dennoch
            // ohne FK protokolliert, damit current_team_id budgetsicher wirkt.
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->date('usage_date')->index();
            $table->string('endpoint', 40);
            $table->string('profile', 40);
            $table->string('status', 40)->index();
            $table->string('model', 191)->nullable();
            $table->string('provider', 120)->nullable();
            $table->unsignedSmallInteger('provider_status_code')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->unsignedBigInteger('reserved_tokens')->default(0);
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->unsignedBigInteger('reservation_cost_microusd')->default(0);
            $table->unsignedBigInteger('charged_cost_microusd')->default(0);
            $table->unsignedBigInteger('provider_cost_microusd')->nullable();
            $table->unsignedBigInteger('provider_upstream_cost_microusd')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['usage_date', 'user_id'], 'ai_api_audit_user_day_index');
            $table->index(['usage_date', 'team_id'], 'ai_api_audit_team_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_api_request_audits');
        Schema::dropIfExists('ai_api_daily_budgets');
    }
};
