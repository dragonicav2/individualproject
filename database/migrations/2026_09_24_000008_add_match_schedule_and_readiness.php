<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table): void {
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('entrant_a_ready_at')->nullable();
            $table->timestamp('entrant_b_ready_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table): void {
            $table->dropIndex(['scheduled_at']);
            $table->dropColumn(['scheduled_at', 'entrant_a_ready_at', 'entrant_b_ready_at']);
        });
    }
};
