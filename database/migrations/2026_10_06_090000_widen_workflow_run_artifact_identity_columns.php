<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflow_run_artifacts')) {
            return;
        }

        // The existing run/step and phase/type indexes scope artifact lookup.
        // A full index on TEXT task identities is not supported by MySQL.
        if (Schema::hasIndex('workflow_run_artifacts', 'workflow_run_artifacts_task_card_key_index')) {
            Schema::table('workflow_run_artifacts', function (Blueprint $table): void {
                $table->dropIndex('workflow_run_artifacts_task_card_key_index');
            });
        }

        Schema::table('workflow_run_artifacts', function (Blueprint $table): void {
            $table->text('storage_path')->nullable()->change();
            $table->text('task_card_key')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Intentionally retain the wider identity columns on rollback.
        // Narrowing to VARCHAR(191) could irreversibly truncate existing paths
        // or merge task identities. Older code can still read the TEXT values.
    }
};
