<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/Support.php';

$scenario = [
    'title' => 'Private team creation, invite use, and refreshed roster consistency',
    'test' => function (): void {
        $owner = integrationUser('Team Owner', 'owner@test.invalid');
        $joiner = integrationUser('Team Joiner', 'joiner@test.invalid');
        $ownerToken = $owner->createToken('integration')->plainTextToken;
        $joinerToken = $joiner->createToken('integration')->plainTextToken;

        $created = integrationApi('POST', '/teams', ['name' => 'Report Test Team', 'game' => 'mlbb', 'is_private' => true], $ownerToken);
        integrationCheck($created['status'] === 201, 'Private team creation failed.', 'Owner created a private team successfully.');
        $teamId = (int) $created['json']['data']['id'];
        integrationCheck(App\Models\TeamMember::query()->where('team_id', $teamId)->where('user_id', $owner->id)->where('role', 'owner')->exists(), 'Creator was not stored as team owner.', 'Team creator was persisted with the owner role.');

        $issued = integrationApi('POST', "/teams/{$teamId}/invites", ['max_uses' => 1, 'expires_in_hours' => 24], $ownerToken);
        integrationCheck($issued['status'] === 201 && isset($issued['json']['code']), 'Invite code was not issued.', 'Owner generated an invite code for the private team.');
        $invite = App\Models\TeamInvite::findOrFail($issued['json']['id']);
        integrationCheck($invite->code_hash !== $issued['json']['code'] && Illuminate\Support\Facades\Hash::check($issued['json']['code'], $invite->code_hash), 'Invite code was not stored as a verifiable hash.', 'Invite code was stored as a hash and verified against the submitted code.');

        $joined = integrationApi('POST', '/teams/join-by-code', ['code' => $issued['json']['code']], $joinerToken);
        integrationCheck($joined['status'] === 200 && (int) $joined['json']['team']['id'] === $teamId, 'Invitee did not join the intended team.', 'Second player joined the team associated with the invite.');
        integrationCheck($invite->fresh()->uses === 1, 'Invite use count was not incremented.', 'Invite usage count increased after the successful join.');
        integrationCheck(integrationApi('POST', '/teams/join-by-code', ['code' => $issued['json']['code']], $joinerToken)['status'] === 422, 'A single-use invite was accepted more than once.', 'The single-use invite was rejected when reused.');

        foreach ([$ownerToken, $joinerToken] as $token) {
            $roster = integrationApi('GET', '/my/teams', [], $token);
            integrationCheck($roster['status'] === 200 && count($roster['json']['data']) === 1, 'A team member could not see the team after refresh.', 'Team is visible in the refreshed team list for a member.');
            integrationCheck((int) $roster['json']['data'][0]['members_count'] === 2, 'Refreshed roster count was inconsistent.', 'Refreshed roster shows the expected two members.');
        }
    },
];

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    runIntegrationScenario($scenario['title'], $scenario['test']);
} else {
    return $scenario;
}
