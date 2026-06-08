<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->string('key')->index();
            $table->longText('value')->nullable();
            $table->timestamps();

            $table->unique(['type', 'key'], 'settings_type_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
