<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Steuerspalten der Personen-Automatisierung.
 *
 * `max_concurrent_workflow_runs` begrenzt, wie viele Workflows eine Person
 * gleichzeitig fahren darf. Der Wert wirkt zusammen mit der automatischen
 * Browser-Exklusivitaet aus `WorkflowBrowserBinding`: browsergebundene Laeufe
 * beanspruchen Browserprofil, Cookie-Datei und Session der Person exklusiv,
 * unabhaengig davon, wie hoch dieser Wert steht.
 *
 * `automation_enabled` ist der Einzelschalter je Person und liegt bewusst neben
 * dem globalen Not-Aus, damit eine einzelne Persona pausiert werden kann, ohne
 * ihre Zeitplaene zu loeschen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('persons')) {
            return;
        }

        Schema::table('persons', function (Blueprint $table): void {
            if (! Schema::hasColumn('persons', 'max_concurrent_workflow_runs')) {
                $table->unsignedTinyInteger('max_concurrent_workflow_runs')->default(1)->after('is_active');
            }

            if (! Schema::hasColumn('persons', 'automation_enabled')) {
                $table->boolean('automation_enabled')->default(true)->after('max_concurrent_workflow_runs');
            }

            if (! Schema::hasColumn('persons', 'person_blueprint_id')) {
                $table->unsignedBigInteger('person_blueprint_id')->nullable()->after('automation_enabled')->index();
            }

            if (! Schema::hasColumn('persons', 'approval_status')) {
                // draft | approved | rejected. Bestandspersonen gelten als freigegeben.
                $table->string('approval_status', 24)->default('approved')->after('person_blueprint_id')->index();
            }

            if (! Schema::hasColumn('persons', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approval_status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('persons')) {
            return;
        }

        Schema::table('persons', function (Blueprint $table): void {
            foreach ([
                'max_concurrent_workflow_runs',
                'automation_enabled',
                'person_blueprint_id',
                'approval_status',
                'approved_at',
            ] as $column) {
                if (Schema::hasColumn('persons', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
