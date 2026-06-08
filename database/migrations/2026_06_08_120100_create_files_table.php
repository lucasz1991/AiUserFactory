<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('files')) {
            return;
        }

        Schema::create('files', function (Blueprint $table): void {
            $table->id();
            $table->morphs('fileable');
            $table->unsignedBigInteger('filepool_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name');
            $table->string('path');
            $table->string('disk', 50)->default('private');
            $table->string('mime_type')->nullable();
            $table->string('type', 50)->default('default')->index();
            $table->unsignedBigInteger('size')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
