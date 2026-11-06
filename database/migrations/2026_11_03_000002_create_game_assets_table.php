<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('game_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('game', 20);
            $table->string('category', 20);
            $table->string('slug', 100);
            $table->string('display_name', 120);
            $table->string('storage_path');
            $table->string('mime_type', 40);
            $table->unsignedInteger('byte_size');
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['game', 'category', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_assets');
    }
};