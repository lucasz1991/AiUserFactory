<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['workflow_task_attempts', 'workflow_run_checkpoints'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'resume_task_key')) {
                    $table->string('resume_task_key', 191)->nullable()->index()->after('task_key');
                }
                if (! Schema::hasColumn($tableName, 'failure_task_key')) {
                    $table->string('failure_task_key', 191)->nullable()->index()->after('resume_task_key');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['workflow_run_checkpoints', 'workflow_task_attempts'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $columns = collect(['failure_task_key', 'resume_task_key'])
                    ->filter(fn (string $column): bool => Schema::hasColumn($tableName, $column))
                    ->all();
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
