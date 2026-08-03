<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die bisher fehlende Verbindung Person x Workflow x Zeitregel.
 *
 * Vorher gab es kein Objekt, das beschreibt, wann eine Person welchen Workflow
 * ausfuehren soll. `workflows.trigger_type` existierte zwar, wurde aber nirgends
 * ausgewertet, und `person_actions.schedule_expression` hatte keinen Konsumenten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('person_workflow_schedules')) {
            return;
        }

        Schema::create('person_workflow_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();

            // Mehrere Zeitplaene je Paar sind erlaubt ("morgens", "abends"), das
            // Etikett haelt sie in der Oberflaeche auseinander.
            $table->string('label', 120)->default('');
            $table->boolean('is_active')->default(true)->index();

            // interval | daily_times | activity_plan
            $table->string('cadence_type', 32)->default('interval')->index();
            $table->unsignedInteger('interval_minutes')->nullable();
            $table->json('daily_times')->nullable();
            $table->json('activity_plan_session_types')->nullable();

            $table->json('weekdays')->nullable();
            $table->time('window_start')->nullable();
            $table->time('window_end')->nullable();
            $table->unsignedInteger('jitter_seconds')->default(0);

            $table->unsignedInteger('max_runs_per_day')->nullable();
            $table->unsignedInteger('min_gap_minutes')->default(0);
            $table->integer('priority')->default(0)->index();

            // Zusaetzliche Startparameter. Traegt bewusst niemals Zugangsdaten,
            // die Validierung lehnt passwortartige Schluessel ab (Regel 6).
            $table->json('context_json')->nullable();

            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('last_workflow_run_id')->nullable()->constrained('workflow_runs')->nullOnDelete();

            // Tagesdeckel ohne teure Aggregation ueber workflow_runs.
            $table->unsignedInteger('runs_today')->default(0);
            $table->date('runs_today_date')->nullable();

            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('paused_until')->nullable();
            $table->string('last_skip_reason', 120)->nullable();

            $table->timestamps();

            $table->index(['is_active', 'next_run_at'], 'pws_due_index');
            $table->index(['person_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_workflow_schedules');
    }
};
