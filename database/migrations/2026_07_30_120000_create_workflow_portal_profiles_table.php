<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_portal_profiles', function (Blueprint $table): void {
            $table->id();
            // Bewusst ohne eigenen Index: Der Unique-Index unten beginnt mit
            // `domain` und deckt Abfragen nach Domain sowie Domain+Rolle als
            // Praefix bereits ab. Zusaetzliche Indizes waeren nur Schreiblast.
            $table->string('domain', 191);
            $table->string('role', 64);
            $table->text('selector');

            // Der Selector selbst kann beliebig lang werden und taugt deshalb auf
            // MySQL nicht als Teil eines Unique-Index (Praefixlaenge). Der
            // SHA-256 ist fix breit und macht die Eindeutigkeit trotzdem exakt.
            $table->char('selector_hash', 64);

            // Ergebnis von WorkflowSelectorSyntaxService::qualityWarningsFor().
            // Wird mitgeschrieben statt bei jeder Abfrage neu berechnet, damit
            // bestFor() ohne Nachladen aller Zeilen sortieren kann.
            $table->boolean('has_quality_warnings')->default(false);

            $table->unsignedInteger('hit_count')->default(0);
            $table->unsignedInteger('miss_count')->default(0);
            $table->timestamp('last_confirmed_at')->nullable()->index();
            $table->string('source', 80)->nullable();
            $table->timestamps();

            $table->unique(['domain', 'role', 'selector_hash'], 'workflow_portal_profiles_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_portal_profiles');
    }
};
