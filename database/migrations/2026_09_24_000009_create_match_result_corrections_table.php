<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('match_result_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('match_id')->constrained('tournament_matches')->cascadeOnDelete();
            $table->foreignId('corrected_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('previous_score_a');
            $table->unsignedTinyInteger('previous_score_b');
            $table->unsignedTinyInteger('corrected_score_a');
            $table->unsignedTinyInteger('corrected_score_b');
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_result_corrections');
    }
};
