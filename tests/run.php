<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:',
    'database.connections.sqlite.foreign_key_constraints' => true,
]);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);

$counter = 0;
$passed = 0;

function expect(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

function expectStatus(int $status, callable $action): void
{
    try {
        $action();
    } catch (Throwable $error) {
        $actual = method_exists($error, 'getStatusCode') ? $error->getStatusCode() : ($error instanceof Illuminate\Validation\ValidationException ? 422 : null);
        if ($actual === $status) return;
        throw new RuntimeException("Expected HTTP {$status}; got ".($actual ?? get_class($error)).': '.$error->getMessage(), previous: $error);
    }
    throw new RuntimeException("Expected HTTP {$status}, but the operation succeeded.");
}

function scenario(string $name, callable $test): void
{
    global $passed;
    Illuminate\Support\Facades\DB::beginTransaction();
    try {
        $test();
        Illuminate\Support\Facades\DB::rollBack();
        $passed++;
        echo "PASS  {$name}\n";
    } catch (Throwable $error) {
        Illuminate\Support\Facades\DB::rollBack();
        fwrite(STDERR, "FAIL  {$name}\n{$error}\n");
        exit(1);
    }
}

function fixture(string $format = 'single_elimination', int $count = 4, string $game = 'sf6'): array
{
    global $counter;
    $organizer = App\Models\User::create(['name' => 'Organizer', 'email' => 'organizer-'.(++$counter).'@test.invalid', 'password' => 'password']);
    $tournament = App\Models\Tournament::create([
        'organizer_id' => $organizer->id,
        'name' => 'Test tournament '.$counter,
        'game' => $game,
        'team_size' => 1,
        'format' => $format,
        'best_of' => 3,
        'capacity' => $count,
        'status' => 'registration_open',
    ]);
    $users = [];
    $entries = [];
    for ($index = 1; $index <= $count; $index++) {
        $users[] = App\Models\User::create(['name' => "Player {$index}", 'email' => "player-{$counter}-{$index}@test.invalid", 'password' => 'password']);
        $entries[] = App\Models\TournamentEntry::create(['tournament_id' => $tournament->id, 'user_id' => end($users)->id, 'seed' => $index, 'checked_in_at' => now(), 'status' => 'registered']);
    }
    return [$organizer, $tournament, $users, $entries];
}

function requestAs(App\Models\User $user, array $data = []): Illuminate\Http\Request
{
    $request = new Illuminate\Http\Request($data);
    $request->setUserResolver(fn () => $user);
    return $request;
}

function createCompletedMatch(App\Models\Tournament $tournament, App\Models\TournamentEntry $a, App\Models\TournamentEntry $b, int $scoreA = 2, int $scoreB = 0, int $round = 1): App\Models\TournamentMatch
{
    return App\Models\TournamentMatch::create([
        'tournament_id' => $tournament->id,
        'round' => $round,
        'match_number' => 1,
        'entrant_a_id' => $a->id,
        'entrant_b_id' => $b->id,
        'winner_entrant_id' => $scoreA > $scoreB ? $a->id : $b->id,
        'best_of' => $tournament->best_of,
        'score_a' => $scoreA,
        'score_b' => $scoreB,
        'status' => 'completed',
        'result_confirmed_at' => now(),
    ]);
}

$brackets = app(App\Services\BracketBuilder::class);
$results = app(App\Http\Controllers\MatchResultController::class);
$matchStats = app(App\Http\Controllers\MatchStatsController::class);

scenario('reject invalid best-of score without changing the match', function () use ($brackets, $results): void {
    [, $tournament, $users] = fixture();
    $brackets->publish($tournament);
    $match = $tournament->matches()->where('round', 1)->first();
    expectStatus(422, fn () => $results->report(requestAs($users[0], ['score_a' => 2, 'score_b' => 2]), $match));
    expect($match->fresh()->status === 'scheduled', 'Invalid score changed the match.');
    expect(! $tournament->matches()->where('round', 2)->exists(), 'Invalid score created a later round.');
});

scenario('hold bracket advancement until an opponent confirms the score', function () use ($brackets, $results): void {
    [, $tournament, $users] = fixture();
    $brackets->publish($tournament);
    $first = $tournament->matches()->where('round', 1)->orderBy('match_number')->first();
    $firstReporter = App\Models\User::findOrFail($first->entrant_a_id ? App\Models\TournamentEntry::findOrFail($first->entrant_a_id)->user_id : 0);
    $firstOpponent = App\Models\User::findOrFail(App\Models\TournamentEntry::findOrFail($first->entrant_b_id)->user_id);
    $results->report(requestAs($firstReporter, ['score_a' => 2, 'score_b' => 0]), $first);
    expect(! $tournament->matches()->where('round', 2)->exists(), 'A pending score advanced the bracket.');
    expectStatus(403, fn () => $results->confirm(requestAs($firstReporter), $first->fresh(), $brackets));
    $results->confirm(requestAs($firstOpponent), $first->fresh(), $brackets);
    expect(! $tournament->matches()->where('round', 2)->exists(), 'One confirmed match advanced before all round-one matches were resolved.');

    $second = $tournament->matches()->where('round', 1)->orderBy('match_number')->skip(1)->first();
    $reporter = App\Models\User::findOrFail(App\Models\TournamentEntry::findOrFail($second->entrant_a_id)->user_id);
    $opponent = App\Models\User::findOrFail(App\Models\TournamentEntry::findOrFail($second->entrant_b_id)->user_id);
    $results->report(requestAs($reporter, ['score_a' => 2, 'score_b' => 1]), $second);
    $results->confirm(requestAs($opponent), $second->fresh(), $brackets);
    expect($tournament->matches()->where('round', 2)->count() === 1, 'Round two was not created after every opening match was confirmed.');
});

scenario('reject score reports and confirmations from users outside the match', function () use ($brackets, $results): void {
    [, $tournament, $users] = fixture();
    $brackets->publish($tournament);
    $match = $tournament->matches()->where('round', 1)->first();
    $outsider = collect($users)->first(fn (App\Models\User $user) => ! $match->entrantA->isOwnedBy($user->id) && ! $match->entrantB->isOwnedBy($user->id));
    expect($outsider !== null, 'Test fixture did not produce a non-entrant.');
    expectStatus(403, fn () => $results->report(requestAs($outsider, ['score_a' => 2, 'score_b' => 0]), $match));
    $a = App\Models\TournamentEntry::findOrFail($match->entrant_a_id);
    $reporter = App\Models\User::findOrFail($a->user_id);
    $results->report(requestAs($reporter, ['score_a' => 2, 'score_b' => 0]), $match);
    expectStatus(403, fn () => $results->confirm(requestAs($outsider), $match->fresh(), $brackets));
});

scenario('allow organizer result entry and explicit organizer confirmation', function () use ($brackets, $results): void {
    [$organizer, $tournament] = fixture();
    $brackets->publish($tournament);
    $match = $tournament->matches()->where('round', 1)->first();
    $results->report(requestAs($organizer, ['score_a' => 0, 'score_b' => 2]), $match);
    expect($match->fresh()->status === 'result_pending', 'Organizer entry was not left pending confirmation.');
    $results->confirm(requestAs($organizer), $match->fresh(), $brackets);
    expect((int) $match->fresh()->winner_entrant_id === (int) $match->entrant_b_id, 'Organizer confirmation recorded the wrong winner.');
});

scenario('allow organizers to update KDA stats for a confirmed match', function () use ($matchStats): void {
    [$organizer, $tournament, $users, $entries] = fixture('round_robin', 2, 'cs2');
    $match = createCompletedMatch($tournament, $entries[0], $entries[1]);
    $gameStats = ['map' => 'de_mirage', 'entrants' => []];
    foreach ([0, 1] as $side) {
        $gameStats['entrants'][(string) $entries[$side]->id] = [
            'rounds_won' => $side === 0 ? 13 : 8,
            'players' => [['player_id' => $users[$side]->id, 'weapon' => 'AK-47', 'kills' => 20 + $side, 'deaths' => 10, 'assists' => 5, 'adr' => 91.5]],
        ];
    }
    $matchStats->update(requestAs($organizer, ['game_stats' => $gameStats]), $match);
    $saved = $match->fresh()->game_stats;
    expect($saved['map'] === 'de_mirage', 'Map was not saved.');
    expect($saved['entrants'][(string) $entries[0]->id]['kills'] === 20, 'Player KDA stats were not stored or aggregated.');
    expect($saved['entrants'][(string) $entries[1]->id]['players'][0]['name'] === $users[1]->name, 'Player identity was not resolved from the roster.');
});

scenario('restrict match stats editing to the organizer and match roster', function () use ($matchStats): void {
    [$organizer, $tournament, $users, $entries] = fixture('round_robin', 3, 'cs2');
    $match = createCompletedMatch($tournament, $entries[0], $entries[1]);
    $stats = ['map' => 'de_ancient', 'entrants' => []];
    foreach ([0, 1] as $side) $stats['entrants'][(string) $entries[$side]->id] = ['rounds_won' => 13, 'players' => [['player_id' => $users[$side]->id, 'kills' => 1, 'deaths' => 1, 'assists' => 0, 'adr' => 1, 'weapon' => 'Rifle']]];
    expectStatus(403, fn () => $matchStats->update(requestAs($users[0], ['game_stats' => $stats]), $match));
    $stats['entrants'][(string) $entries[0]->id]['players'][0]['player_id'] = $users[2]->id;
    expectStatus(422, fn () => $matchStats->update(requestAs($organizer, ['game_stats' => $stats]), $match));
    expect($match->fresh()->game_stats === null, 'Rejected stats edit changed the match.');
});

scenario('record and expose organizer corrections for round-robin results', function () use ($results): void {
    [$organizer, $tournament, , $entries] = fixture('round_robin', 2);
    $tournament->update(['status' => 'completed']);
    $match = createCompletedMatch($tournament, $entries[0], $entries[1]);
    $results->correct(requestAs($organizer, ['score_a' => 0, 'score_b' => 2, 'reason' => 'The verified scorecard was entered on the wrong side.']), $match);
    $match = $match->fresh()->load('resultCorrections.corrector');
    expect((int) $match->winner_entrant_id === (int) $entries[1]->id, 'Corrected score did not change the winner.');
    expect($match->resultCorrections->count() === 1, 'Correction audit entry was not created.');
    expect($match->resultCorrections->first()->corrector->is($organizer), 'Correction audit entry has the wrong organizer.');
});

scenario('preserve a dispute and organizer resolution in the result history', function () use ($results, $brackets): void {
    [$organizer, $tournament, $users, $entries] = fixture('round_robin', 2);
    $tournament->update(['status' => 'live']);
    $match = $tournament->matches()->create(['round' => 1, 'match_number' => 1, 'entrant_a_id' => $entries[0]->id, 'entrant_b_id' => $entries[1]->id, 'best_of' => 3, 'status' => 'scheduled']);
    $results->report(requestAs($users[0], ['score_a' => 2, 'score_b' => 0]), $match);
    $results->dispute(requestAs($users[1], ['reason' => 'The match score was recorded incorrectly.']), $match->fresh());
    expect($match->fresh()->status === 'disputed', 'Disputed result did not enter the dispute state.');
    expectStatus(403, fn () => $results->resolve(requestAs($users[0]), $match->fresh(), $brackets));
    $results->resolve(requestAs($organizer, ['score_a' => 0, 'score_b' => 2, 'resolution' => 'Reviewed the match evidence and corrected the score.']), $match->fresh(), $brackets);
    $dispute = $match->fresh()->disputes()->first();
    expect($match->fresh()->status === 'completed', 'Organizer resolution did not complete the result.');
    expect($dispute && $dispute->resolved_at && str_contains($dispute->resolution, 'Reviewed'), 'Dispute resolution was not retained.');
});

scenario('propagate a single-elimination correction to an unplayed next-round matchup', function () use ($results): void {
    [$organizer, $tournament, , $entries] = fixture('single_elimination', 3);
    $tournament->update(['status' => 'live']);
    $match = createCompletedMatch($tournament, $entries[0], $entries[1]);
    $next = $tournament->matches()->create(['round' => 2, 'match_number' => 1, 'entrant_a_id' => $entries[0]->id, 'entrant_b_id' => $entries[2]->id, 'best_of' => 3, 'status' => 'scheduled', 'scheduled_at' => now()->addDay()]);
    $results->correct(requestAs($organizer, ['score_a' => 0, 'score_b' => 2, 'reason' => 'The verified scorecard had the winner reversed.']), $match);
    expect((int) $next->fresh()->entrant_a_id === (int) $entries[1]->id, 'The unplayed next-round entrant was not corrected.');
    expect($next->fresh()->scheduled_at === null, 'The old scheduled time was retained after the matchup changed.');
});

scenario('block corrections once a later single-elimination match has completed', function () use ($results): void {
    [$organizer, $tournament, , $entries] = fixture('single_elimination', 3);
    $tournament->update(['status' => 'live']);
    $match = createCompletedMatch($tournament, $entries[0], $entries[1]);
    createCompletedMatch($tournament, $entries[0], $entries[2], 0, 2, 2);
    expectStatus(409, fn () => $results->correct(requestAs($organizer, ['score_a' => 0, 'score_b' => 2, 'reason' => 'This correction should be blocked safely.']), $match));
    expect((int) $match->fresh()->winner_entrant_id === (int) $entries[0]->id, 'A blocked correction changed the winner.');
});

scenario('block double-elimination corrections when downstream matches exist', function () use ($results): void {
    [$organizer, $tournament, , $entries] = fixture('double_elimination', 3);
    $tournament->update(['status' => 'live']);
    $match = createCompletedMatch($tournament, $entries[0], $entries[1]);
    $tournament->matches()->create(['round' => 2, 'match_number' => 1, 'entrant_a_id' => $entries[0]->id, 'entrant_b_id' => $entries[2]->id, 'best_of' => 3, 'status' => 'scheduled', 'bracket_side' => 'winners']);
    expectStatus(409, fn () => $results->correct(requestAs($organizer, ['score_a' => 0, 'score_b' => 2, 'reason' => 'This downstream result is intentionally guarded.']), $match));
    expect((int) $match->fresh()->winner_entrant_id === (int) $entries[0]->id, 'Blocked double-elimination correction changed the winner.');
});

echo "\n{$passed} match result regression scenarios passed.\n";
