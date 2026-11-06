<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/Support.php';

$scenario = [
    'title' => 'Tournament check-in opens one hour before start and closes at start',
    'test' => function (): void {
        $organizer = integrationUser('Check-in Organizer', 'checkin-organizer@test.invalid', 'organizer');
        $player = integrationUser('Check-in Player', 'checkin-player@test.invalid');
        $token = $player->createToken('integration')->plainTextToken;
        $tournament = App\Models\Tournament::create([
            'organizer_id' => $organizer->id, 'name' => 'Check-in Window Test', 'game' => 'sf6',
            'team_size' => 1, 'format' => 'single_elimination', 'best_of' => 3, 'capacity' => 8,
            'status' => 'registration_open', 'starts_at' => now()->addMinutes(61),
        ]);
        $entry = App\Models\TournamentEntry::create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'status' => 'registered']);

        $early = integrationApi('POST', "/tournament-entries/{$entry->id}/check-in", [], $token);
        integrationCheck($early['status'] === 409 && str_contains($early['json']['message'] ?? '', 'one hour'), 'Check-in was not rejected before the one-hour window.', 'Check-in 61 minutes before start was rejected with the opening-window message.');
        integrationCheck($entry->fresh()->checked_in_at === null, 'Early check-in changed the entry record.', 'Early attempt did not modify the participant entry.');

        $tournament->update(['starts_at' => now()->addHour()]);
        $inWindow = integrationApi('POST', "/tournament-entries/{$entry->id}/check-in", [], $token);
        integrationCheck($inWindow['status'] === 200 && $entry->fresh()->checked_in_at !== null, 'Check-in failed at the one-hour opening boundary.', 'Check-in succeeded and was saved at the one-hour opening boundary.');

        $entry->update(['checked_in_at' => null]);
        $tournament->update(['starts_at' => now()->subSecond()]);
        $late = integrationApi('POST', "/tournament-entries/{$entry->id}/check-in", [], $token);
        integrationCheck($late['status'] === 409 && $entry->fresh()->checked_in_at === null, 'Check-in remained open after the scheduled start.', 'Check-in after the scheduled start was rejected without changing the entry.');
    },
];

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    runIntegrationScenario($scenario['title'], $scenario['test']);
} else {
    return $scenario;
}
