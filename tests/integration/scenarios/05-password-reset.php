<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/Support.php';

$scenario = [
    'title' => 'Password recovery for known and unknown email addresses',
    'test' => function (): void {
        Illuminate\Support\Facades\Notification::fake();
        $user = integrationUser('Recovery Player', 'recovery@test.invalid');
        $generic = 'If an account exists for that email, a password reset link has been sent.';

        $known = integrationApi('POST', '/auth/forgot-password', ['email' => $user->email]);
        integrationCheck($known['status'] === 200 && $known['json']['message'] === $generic, 'Known email did not receive the generic reset response.', 'Known email received the account-safe reset response.');
        $notifications = Illuminate\Support\Facades\Notification::sent($user, Illuminate\Auth\Notifications\ResetPassword::class);
        integrationCheck(count($notifications) === 1, 'No reset notification was dispatched for the known account.', 'Reset notification was dispatched once for the existing account.');
        $resetToken = $notifications[0]->token;
        $savedToken = Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', $user->email)->first();
        integrationCheck($savedToken && ! hash_equals($resetToken, $savedToken->token), 'Reset token was not stored as a hash.', 'Reset token is stored hashed in the password-reset table.');

        $beforeUnknown = Illuminate\Support\Facades\DB::table('password_reset_tokens')->count();
        $unknown = integrationApi('POST', '/auth/forgot-password', ['email' => 'nobody@test.invalid']);
        integrationCheck($unknown['status'] === 200 && $unknown['json']['message'] === $generic, 'Unknown email response disclosed account existence.', 'Unknown email received the same generic response as a known address.');
        integrationCheck(Illuminate\Support\Facades\DB::table('password_reset_tokens')->count() === $beforeUnknown, 'A reset token was created for an unknown address.', 'No reset token was created for an unknown email.');

        $newPassword = 'FrostyWave9!River';
        $changed = integrationApi('POST', '/auth/reset-password', ['email' => $user->email, 'token' => $resetToken, 'password' => $newPassword, 'password_confirmation' => $newPassword]);
        integrationCheck($changed['status'] === 200, 'Valid reset token did not change the password.', 'Valid reset token was accepted and password changed.');
        integrationCheck(integrationApi('POST', '/auth/login', ['email' => $user->email, 'password' => $newPassword])['status'] === 200, 'New password did not authenticate.', 'New password successfully authenticated the account.');
        integrationCheck(integrationApi('POST', '/auth/login', ['email' => $user->email, 'password' => 'CurrentPassword9!'])['status'] === 422, 'Old password remained usable.', 'Previous password was rejected after password reset.');
        integrationCheck(! Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', $user->email)->exists(), 'Successful reset did not consume its token.', 'Reset token was removed after successful use.');
    },
];

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    runIntegrationScenario($scenario['title'], $scenario['test']);
} else {
    return $scenario;
}
