<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('game_servers', function (Blueprint $table): void {
            $table->string('stream_url')->nullable();
            $table->string('demo_path')->nullable();
            $table->unsignedBigInteger('demo_size')->nullable();
            $table->timestamp('stopped_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('game_servers', function (Blueprint $table): void {
            $table->dropColumn(['stream_url', 'demo_path', 'demo_size', 'stopped_at']);
        });
    }
};
