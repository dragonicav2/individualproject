<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/Support.php';

$scenario = [
    'title' => 'Authentication, role-specific login, and refresh persistence',
    'test' => function (): void {
        $player = integrationUser('Test Player', 'player@test.invalid', 'player');
        $organizer = integrationUser('Test Organizer', 'organizer@test.invalid', 'organizer');
        $admin = integrationUser('Test Admin', 'admin@test.invalid', 'organizer');
        $admin->forceFill(['is_platform_admin' => true, 'admin_role' => 'super_admin'])->save();

        foreach ([[$player, '/auth/login'], [$organizer, '/auth/login'], [$admin, '/auth/admin-login']] as [$user, $endpoint]) {
            $login = integrationApi('POST', $endpoint, ['email' => $user->email, 'password' => 'CurrentPassword9!']);
            integrationCheck($login['status'] === 200, "{$user->name} login failed with HTTP {$login['status']}.", "{$user->name} authenticated through the correct login endpoint.");
            integrationCheck(isset($login['json']['token']), 'Successful login did not return an API token.', "{$user->name} login returned an authentication token.");
            $afterRefresh = integrationApi('GET', '/auth/me', [], $login['json']['token']);
            integrationCheck($afterRefresh['status'] === 200, 'The token did not authenticate the follow-up /me request.', "{$user->name} remained authenticated on the refresh-equivalent /me request.");
            integrationCheck((int) $afterRefresh['json']['user']['id'] === (int) $user->id, 'Refresh resolved to the wrong account.', "The refreshed session resolved to the expected {$user->name} account.");
        }

        $wrongPassword = integrationApi('POST', '/auth/login', ['email' => $player->email, 'password' => 'WrongPassword9!']);
        integrationCheck($wrongPassword['status'] === 422, 'Invalid credentials were not rejected.', 'Incorrect password was rejected with HTTP 422.');
        $adminPublicLogin = integrationApi('POST', '/auth/login', ['email' => $admin->email, 'password' => 'CurrentPassword9!']);
        integrationCheck($adminPublicLogin['status'] === 403, 'An admin was allowed through the player/organizer login endpoint.', 'Admin account was denied access through the player/organizer login endpoint.');
    },
];

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    runIntegrationScenario($scenario['title'], $scenario['test']);
} else {
    return $scenario;
}
