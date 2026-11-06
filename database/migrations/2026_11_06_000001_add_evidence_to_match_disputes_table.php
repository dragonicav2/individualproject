<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('match_disputes', function (Blueprint $table): void {
            $table->string('evidence_path')->nullable();
            $table->string('evidence_mime', 80)->nullable();
            $table->string('evidence_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('match_disputes', function (Blueprint $table): void {
            $table->dropColumn(['evidence_path', 'evidence_mime', 'evidence_name']);
        });
    }
};
