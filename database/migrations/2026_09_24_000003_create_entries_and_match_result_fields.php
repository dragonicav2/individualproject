<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tournament_entries', function (Blueprint $table): void {
            $table->id(); $table->foreignId('tournament_id')->constrained()->cascadeOnDelete(); $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete(); $table->unsignedSmallInteger('seed')->nullable(); $table->timestamp('checked_in_at')->nullable(); $table->string('status', 20)->default('registered'); $table->timestamps(); $table->unique(['tournament_id', 'user_id']); $table->unique(['tournament_id', 'team_id']);
        });
        Schema::table('tournament_matches', function (Blueprint $table): void {
            $table->foreignId('entrant_a_id')->nullable()->after('tournament_id')->constrained('tournament_entries')->nullOnDelete();
            $table->foreignId('entrant_b_id')->nullable()->after('entrant_a_id')->constrained('tournament_entries')->nullOnDelete();
            $table->foreignId('winner_entrant_id')->nullable()->after('entrant_b_id')->constrained('tournament_entries')->nullOnDelete();
            $table->foreignId('result_reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('result_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('result_confirmed_at')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table): void { $table->dropConstrainedForeignId('result_confirmed_by'); $table->dropConstrainedForeignId('result_reporter_id'); $table->dropConstrainedForeignId('winner_entrant_id'); $table->dropConstrainedForeignId('entrant_b_id'); $table->dropConstrainedForeignId('entrant_a_id'); $table->dropColumn('result_confirmed_at'); });
        Schema::dropIfExists('tournament_entries');
    }
};
