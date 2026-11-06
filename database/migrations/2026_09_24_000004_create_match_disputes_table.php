<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('match_disputes', function (Blueprint $table): void { $table->id(); $table->foreignId('match_id')->constrained('tournament_matches')->cascadeOnDelete(); $table->foreignId('raised_by')->constrained('users')->cascadeOnDelete(); $table->text('reason'); $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete(); $table->text('resolution')->nullable(); $table->timestamp('resolved_at')->nullable(); $table->timestamps(); });
    }
    public function down(): void { Schema::dropIfExists('match_disputes'); }
};
