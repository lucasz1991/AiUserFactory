<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bauplan der Personen-Fabrik.
 *
 * Ein Bauplan beschreibt, wie neue Personas entstehen: in welchem Takt, in
 * welchen Korridoren (Land, Sprache, Alter, Geschlecht), mit welcher
 * AI-Prompt-Vorlage, welchen Konten, welchem Onboarding-Workflow und welchen
 * Zeitplaenen danach.
 *
 * Erzeugte Personen sind immer zuerst Entwuerfe. Die Aktivierung ist ein
 * bewusster manueller Schritt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('person_blueprints')) {
            return;
        }

        Schema::create('person_blueprints', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false)->index();

            $table->string('platform', 60)->default('instagram');

            // Wie viele Personen der Bauplan insgesamt erzeugen soll und wie
            // viele pro Tag. `target_count` 0 bedeutet: unbegrenzt.
            $table->unsignedInteger('target_count')->default(0);
            $table->unsignedInteger('per_day')->default(1);
            $table->unsignedInteger('created_count')->default(0);

            // Korridore fuer die Profilerzeugung.
            $table->json('countries')->nullable();
            $table->json('languages')->nullable();
            $table->json('genders')->nullable();
            $table->unsignedTinyInteger('age_min')->default(21);
            $table->unsignedTinyInteger('age_max')->default(45);

            $table->text('profile_prompt')->nullable();
            $table->boolean('generate_avatar')->default(false);

            // Kontotypen, die als Geruest angelegt werden (Schluessel aus
            // PersonAccountRegistry::TYPES).
            $table->json('account_types')->nullable();

            $table->foreignId('onboarding_workflow_id')->nullable()->constrained('workflows')->nullOnDelete();

            // Zeitplan-Vorlagen, die nach der Freigabe angelegt werden.
            $table->json('schedule_templates')->nullable();

            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('created_today')->default(0);
            $table->date('created_today_date')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_blueprints');
    }
};
