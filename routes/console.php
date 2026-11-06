<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('platform:about', function (): void {
    $this->comment('Tournament platform API is ready.');
})->purpose('Show the platform status');

Artisan::command('platform:admin {email} {--revoke}', function (string $email): int {
    $user = \App\Models\User::query()->where('email', $email)->first();
    if (! $user) {
        $this->error('No account exists with that email.');
        return 1;
    }

    $grant = ! $this->option('revoke');
    $user->forceFill($grant
        ? ['is_platform_admin' => true, 'admin_role' => 'super_admin', 'account_type' => 'admin']
        : ['is_platform_admin' => false, 'admin_role' => null, 'must_change_password' => false, 'temporary_password_expires_at' => null])->save();
    $user->tokens()->delete();
    $this->info($grant ? 'Platform administrator access granted.' : 'Platform administrator access revoked.');
    return 0;
})->purpose('Grant or revoke platform administrator access');

Artisan::command('platform:admin-create', function (): int {
    $this->error('Create limited admin accounts from the protected Admin users page so each receives a five-minute password and forced first-login reset.');
    return 1;
})->purpose('Direct admin-account creation to the protected admin page');
