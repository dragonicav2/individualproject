<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table): void {
            $table->json('game_stats')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table): void {
            $table->dropColumn('game_stats');
        });
    }
};
