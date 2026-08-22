<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_portal_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('profile_version')->default(1)->after('selector_hash');
            $table->boolean('is_approved')->default(true)->after('has_quality_warnings')->index();
            $table->boolean('is_active')->default(true)->after('is_approved')->index();
            $table->json('evidence_json')->nullable()->after('source');
            $table->timestamp('approved_at')->nullable()->after('evidence_json');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('disabled_at')->nullable()->after('approved_by');
            $table->foreignId('disabled_by')->nullable()->after('disabled_at')->constrained('users')->nullOnDelete();
            $table->string('disable_reason', 500)->nullable()->after('disabled_by');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_portal_profiles', function (Blueprint $table): void {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['disabled_by']);
            $table->dropColumn([
                'profile_version',
                'is_approved',
                'is_active',
                'evidence_json',
                'approved_at',
                'approved_by',
                'disabled_at',
                'disabled_by',
                'disable_reason',
            ]);
        });
    }
};
