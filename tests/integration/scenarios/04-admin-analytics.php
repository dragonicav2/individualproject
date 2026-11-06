<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/Support.php';

$scenario = [
    'title' => 'Admin analytics date periods and game-specific aggregation',
    'test' => function (): void {
        $admin = integrationUser('Analytics Admin', 'analytics-admin@test.invalid', 'organizer');
        $admin->forceFill(['is_platform_admin' => true, 'admin_role' => 'super_admin'])->save();
        $token = $admin->createToken('integration')->plainTextToken;
        $player = integrationUser('Analytics Player', 'analytics-player@test.invalid');
        $player->forceFill(['favorite_game' => 'cs2', 'game_interests' => ['cs2', 'mlbb']])->save();
        App\Models\Tournament::create(['organizer_id' => $admin->id, 'name' => 'VALORANT analytics event', 'game' => 'valorant', 'team_size' => 5, 'format' => 'single_elimination', 'best_of' => 3, 'status' => 'registration_open']);

        foreach (['day' => 14, 'week' => 12, 'month' => 12] as $period => $bucketCount) {
            $result = integrationApi('GET', "/analytics/admin?period={$period}", [], $token);
            integrationCheck($result['status'] === 200, "Analytics period {$period} failed.", "Admin analytics accepted the {$period} period.");
            $data = $result['json']['data'];
            integrationCheck($data['period'] === $period && count($data['buckets']) === $bucketCount, "Period {$period} returned an unexpected date bucket sequence.", "{$period} period returned the expected {$bucketCount} date buckets.");
            $games = collect($data['games'])->keyBy('code');
            integrationCheck($games['cs2']['players_interested'] === 1 && $games['mlbb']['players_interested'] === 1, 'Multiple game interests were not counted for each selected game.', "{$period} period counted the player's CS2 and MLBB interests under both games.");
            integrationCheck($games['valorant']['tournaments'] === 1, 'Tournament creation was attributed to the wrong game.', "{$period} period attributed the tournament to VALORANT.");
            integrationCheck(count($data['charts']['player_interest_growth']) === 4 && count($data['charts']['tournament_creation']) === 4, 'Charts are missing one or more game series.', "{$period} period returned chart series for all four supported games.");
        }

        $unsupported = integrationApi('GET', '/analytics/admin?period=year', [], $token);
        integrationCheck($unsupported['status'] === 422, 'Unsupported analytics period was not rejected.', 'Unsupported year period was rejected with HTTP 422.');
    },
];

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    runIntegrationScenario($scenario['title'], $scenario['test']);
} else {
    return $scenario;
}
