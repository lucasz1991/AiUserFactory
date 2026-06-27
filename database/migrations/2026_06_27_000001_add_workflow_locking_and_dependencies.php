<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workflows', 'is_locked')) {
            Schema::table('workflows', function (Blueprint $table): void {
                $table->boolean('is_locked')->default(false)->index()->after('is_active');
            });
        }

        if (! Schema::hasTable('workflow_dependencies')) {
            Schema::create('workflow_dependencies', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('parent_workflow_id')->constrained('workflows')->cascadeOnDelete();
                $table->foreignId('child_workflow_id')->constrained('workflows')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['parent_workflow_id', 'child_workflow_id'], 'workflow_dependencies_unique');
                $table->index('child_workflow_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_dependencies');

        if (Schema::hasColumn('workflows', 'is_locked')) {
            Schema::table('workflows', function (Blueprint $table): void {
                $table->dropColumn('is_locked');
            });
        }
    }
};
