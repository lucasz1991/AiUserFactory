<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indizierter Spiegel des Personenbezugs.
 *
 * Der Bezug lag bisher ausschliesslich in `workflow_runs.context_json['person_id']`.
 * Damit war "alle Laeufe dieser Person" nicht indiziert abfragbar — Historie,
 * Fehlerquote und Kennzahlen je Person waren praktisch nicht zu bilden.
 *
 * `context_json` bleibt beim Start die fuehrende Quelle; diese Spalte wird davon
 * abgeleitet. Sie ist ein Spiegel, kein zweiter Vertrag.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflow_runs') || Schema::hasColumn('workflow_runs', 'person_id')) {
            return;
        }

        Schema::table('workflow_runs', function (Blueprint $table): void {
            $table->foreignId('person_id')
                ->nullable()
                ->after('workflow_id')
                ->constrained('persons')
                ->nullOnDelete();
        });

        $this->backfillFromContext();
    }

    public function down(): void
    {
        if (! Schema::hasTable('workflow_runs') || ! Schema::hasColumn('workflow_runs', 'person_id')) {
            return;
        }

        Schema::table('workflow_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('person_id');
        });
    }

    /**
     * Bestandszeilen aus dem JSON nachziehen. Bewusst in PHP statt per
     * JSON-Funktion: die Anwendung laeuft lokal auf SQLite und produktiv auf
     * MySQL, und `json_extract` verhaelt sich dort nicht gleich.
     */
    protected function backfillFromContext(): void
    {
        if (! Schema::hasTable('persons')) {
            return;
        }

        $knownPersonIds = DB::table('persons')->pluck('id')->all();

        if ($knownPersonIds === []) {
            return;
        }

        $knownPersonIds = array_flip(array_map('intval', $knownPersonIds));

        DB::table('workflow_runs')
            ->select('id', 'context_json')
            ->whereNull('person_id')
            ->orderBy('id')
            ->chunk(500, function ($runs) use ($knownPersonIds): void {
                foreach ($runs as $run) {
                    $context = json_decode((string) $run->context_json, true);

                    if (! is_array($context)) {
                        continue;
                    }

                    $personId = (int) ($context['person_id'] ?? $context['personId'] ?? 0);

                    if ($personId <= 0 || ! isset($knownPersonIds[$personId])) {
                        continue;
                    }

                    DB::table('workflow_runs')->where('id', $run->id)->update(['person_id' => $personId]);
                }
            });
    }
};
