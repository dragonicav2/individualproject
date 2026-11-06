<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id(); $table->string('name', 80); $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete(); $table->string('game', 24); $table->boolean('is_private')->default(true); $table->timestamps();
        });
        Schema::create('team_members', function (Blueprint $table): void {
            $table->id(); $table->foreignId('team_id')->constrained()->cascadeOnDelete(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('role', 16)->default('player'); $table->timestamp('joined_at')->nullable(); $table->timestamps(); $table->unique(['team_id', 'user_id']);
        });
        Schema::create('team_invites', function (Blueprint $table): void {
            $table->id(); $table->foreignId('team_id')->constrained()->cascadeOnDelete(); $table->string('code_hash', 255); $table->foreignId('created_by')->constrained('users')->cascadeOnDelete(); $table->timestamp('expires_at')->index(); $table->unsignedSmallInteger('max_uses')->default(1); $table->unsignedSmallInteger('uses')->default(0); $table->timestamp('revoked_at')->nullable(); $table->timestamps();
        });
        Schema::create('tournaments', function (Blueprint $table): void {
            $table->id(); $table->foreignId('organizer_id')->constrained('users')->cascadeOnDelete(); $table->string('name', 120); $table->string('game', 24)->index(); $table->unsignedTinyInteger('team_size'); $table->string('format', 32); $table->unsignedTinyInteger('best_of')->default(1); $table->string('status', 20)->default('draft')->index(); $table->timestamp('starts_at')->nullable(); $table->json('settings')->nullable(); $table->timestamps();
        });
        Schema::create('tournament_matches', function (Blueprint $table): void {
            $table->id(); $table->foreignId('tournament_id')->constrained()->cascadeOnDelete(); $table->unsignedSmallInteger('round')->default(1); $table->unsignedSmallInteger('match_number')->default(1); $table->string('status', 20)->default('scheduled')->index(); $table->unsignedTinyInteger('best_of')->default(1); $table->foreignId('team_a_id')->nullable()->constrained('teams')->nullOnDelete(); $table->foreignId('team_b_id')->nullable()->constrained('teams')->nullOnDelete(); $table->unsignedTinyInteger('score_a')->nullable(); $table->unsignedTinyInteger('score_b')->nullable(); $table->foreignId('winner_team_id')->nullable()->constrained('teams')->nullOnDelete(); $table->timestamps(); $table->index(['tournament_id', 'round', 'match_number']);
        });
        Schema::create('game_servers', function (Blueprint $table): void {
            $table->id(); $table->foreignId('match_id')->unique()->constrained('tournament_matches')->cascadeOnDelete(); $table->string('provider', 48); $table->string('provider_ref')->nullable(); $table->string('region', 32); $table->string('map', 32); $table->string('host')->nullable(); $table->unsignedSmallInteger('port')->nullable(); $table->string('status', 20)->default('provisioning')->index(); $table->text('connect_secret')->nullable(); $table->timestamp('last_health_check_at')->nullable(); $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('game_servers'); Schema::dropIfExists('tournament_matches'); Schema::dropIfExists('tournaments'); Schema::dropIfExists('team_invites'); Schema::dropIfExists('team_members'); Schema::dropIfExists('teams');
    }
};
