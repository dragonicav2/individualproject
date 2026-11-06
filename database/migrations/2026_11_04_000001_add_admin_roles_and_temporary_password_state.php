<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('admin_role', 20)->nullable()->index();
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('temporary_password_expires_at')->nullable();
        });

        DB::table('users')->where('is_platform_admin', true)->update([
            'account_type' => 'admin',
            'admin_role' => 'super_admin',
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['admin_role']);
            $table->dropColumn(['admin_role', 'must_change_password', 'temporary_password_expires_at']);
        });
    }
};
