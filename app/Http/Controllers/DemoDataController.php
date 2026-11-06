<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\GameServer;
use App\Models\MatchDispute;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\BracketBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoDataController extends Controller
{
    public function playerSession(Request $request): JsonResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        abort_unless($request->user()->account_type === 'organizer', 403, 'Organizer accounts can open demo player previews.');

        $organizer = $request->user();
        $validated = $request->validate([
            'match_id' => ['required', 'integer'],
            'side' => ['required', 'in:a,b'],
        ]);
        $match = TournamentMatch::query()
            ->with(['entrantA.team.members', 'entrantB.team.members', 'tournament'])
            ->whereKey($validated['match_id'])
            ->firstOrFail();
        $tournament = $match->tournament;
        abort_unless((int) $tournament->organizer_id === (int) $organizer->id, 404);
        abort_unless(($tournament->settings['demo_mlbb_emulator'] ?? false) || ($tournament->settings['demo_player_result_reporting'] ?? false) || ($tournament->settings['demo_match_operations'] ?? false), 404);
        $entrant = $validated['side'] === 'a' ? $match->entrantA : $match->entrantB;
        abort_unless($entrant, 404, 'That side has no participant in this match.');
        $playerId = $entrant->team?->members()->where('user_id', '!=', $organizer->id)->value('user_id')
            ?: ($entrant->user_id && $entrant->user_id !== $organizer->id ? $entrant->user_id : null);
        abort_unless($playerId, 404, 'That side has no demo player to preview.');

        $player = User::findOrFail($playerId);
        $token = $player->createToken('demo-player-preview');
        $token->accessToken->forceFill(['expires_at' => now()->addHours(2)])->save();
        $inviteCode = Cache::get('demo-player-team-invite:'.$organizer->id);

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'user' => ['id' => $player->id, 'name' => $player->name],
            'tournament_id' => $tournament->id,
            'match_id' => $match->id,
            'team_invite_code' => $inviteCode,
        ]]);
    }

    public function generate(Request $request, BracketBuilder $brackets): JsonResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        abort_unless($request->user()->account_type === 'organizer', 403, 'Choose an organizer account to generate demo data.');

        $created = DB::transaction(function () use ($request, $brackets): array {
            $organizer = $request->user();
            $demoPassword = Hash::make('DemoPlayer123!');
            $rosters = [];
            foreach (['cs2' => 8, 'valorant' => 8, 'mlbb' => 8] as $game => $teamCount) {
                $players = [];
                $gameIndex = array_search($game, ['cs2', 'valorant', 'sf6', 'mlbb'], true);
                for ($i = 1; $i <= $teamCount * 5; $i++) {
                    $daysAgo = match (true) {
                        $i <= 12 => ($i + $gameIndex * 3) % 14,
                        $i <= 28 => 14 + (($i * 5 + $gameIndex * 7) % 70),
                        default => 90 + (($i * 13 + $gameIndex * 11) % 270),
                    };
                    $registeredAt = now()->subDays($daysAgo)->setTime(9 + ($i % 10), ($i * 7) % 60);
                    $interests = [$game];
                    if ($i % 4 === 0) $interests[] = ['cs2', 'valorant', 'sf6', 'mlbb'][($gameIndex + 1) % 4];
                    $player = User::updateOrCreate(
                        ['email' => "demo-{$game}-player-{$i}@example.test"],
                        ['name' => ucfirst($game)." Demo Player {$i}", 'password' => $demoPassword, 'email_verified_at' => now(), 'account_type' => 'player', 'favorite_game' => $game, 'game_interests' => array_values(array_unique($interests)), 'bio' => "{$game} demo player · looking for good teammates and fair matches.", 'game_profiles' => [$game => 'DEMO-'.$i]],
                    );
                    $player->forceFill(['created_at' => $registeredAt, 'updated_at' => $registeredAt])->save();
                    $players[] = $player;
                }
                for ($teamIndex = 0; $teamIndex < $teamCount; $teamIndex++) {
                    $teamName = match ($game) {
                        'cs2' => ['Aurora Five', 'Pixel Pushers', 'Northstar', 'Clutch Theory', 'Overtime Club', 'Smoke Signals', 'Full Buy', 'Last Round'][$teamIndex],
                        'valorant' => ['Radiant Core', 'Spike Syndicate', 'Lotus Five', 'Retake Unit', 'Echo Protocol', 'Night Market', 'First Contact', 'Orbital'][$teamIndex],
                        default => ['Mythic Reign', 'Land of Dawn', 'Turtle Control', 'Lord Takers', 'Dawn Patrol', 'Crystal Nexus', 'Jungle Kings', 'Celestial'][$teamIndex],
                    };
                    $team = Team::updateOrCreate(
                        ['owner_id' => $organizer->id, 'name' => $teamName],
                        ['game' => $game, 'is_private' => false],
                    );
                    TeamMember::firstOrCreate(['team_id' => $team->id, 'user_id' => $organizer->id], ['role' => 'captain', 'joined_at' => now()]);
                    foreach (array_slice($players, $teamIndex * 5, 5) as $player) {
                        TeamMember::firstOrCreate(['team_id' => $team->id, 'user_id' => $player->id], ['role' => 'player', 'joined_at' => now()]);
                    }
                    $rosters[$game][] = $team;
                }
            }

            $events = [
                ['name' => 'Demo · Counter-Strike 2 Open', 'game' => 'cs2', 'team_size' => 5, 'format' => 'single_elimination', 'best_of' => 3, 'capacity' => 32, 'status' => 'live', 'settings' => ['demo_match_operations' => true]],
                ['name' => 'Demo · VALORANT Community Cup', 'game' => 'valorant', 'team_size' => 5, 'format' => 'single_elimination', 'best_of' => 3, 'capacity' => 32, 'status' => 'live'],
                ['name' => 'Demo · Street Fighter Round Robin', 'game' => 'sf6', 'team_size' => 1, 'format' => 'round_robin', 'best_of' => 3, 'capacity' => 16, 'status' => 'live'],
                ['name' => 'Demo · Mobile Legends Scrim Night', 'game' => 'mlbb', 'team_size' => 5, 'format' => 'single_elimination', 'best_of' => 1, 'capacity' => 16, 'status' => 'live', 'settings' => ['demo_mlbb_emulator' => true, 'demo_match_operations' => true]],
            ];
            $tournaments = [];
            foreach ($events as $event) {
                $status = $event['status'];
                $gameIndex = array_search($event['game'], ['cs2', 'valorant', 'sf6', 'mlbb'], true);
                $event['created_at'] = now()->subDays((int) $gameIndex);
                unset($event['status']);
                $tournament = Tournament::updateOrCreate(
                    ['organizer_id' => $organizer->id, 'name' => $event['name']],
                    $event + ['status' => $status, 'starts_at' => $status === 'completed' ? now()->subDays(2) : ($status === 'live' ? now()->subHour() : now()->addDays(3))],
                );
                $tournament->forceFill(['created_at' => $event['created_at'], 'updated_at' => now()])->save();
                $tournament->matches()->delete();
                $tournament->entries()->delete();
                $tournaments[$event['game']] = $tournament;
            }

            foreach (['cs2', 'valorant', 'mlbb'] as $game) {
                foreach ($rosters[$game] as $index => $team) {
                    TournamentEntry::create(['tournament_id' => $tournaments[$game]->id, 'team_id' => $team->id, 'seed' => $index + 1, 'checked_in_at' => now(), 'status' => 'registered']);
                }
            }

            $cs2 = $tournaments['cs2'];
            $brackets->publish($cs2);
            $cs2Matches = $cs2->matches()->where('status', 'scheduled')->orderBy('match_number')->get();
            if ($cs2Matches->count() > 1) $this->markDemoResult($cs2Matches[0], 2, 0, true, $organizer->id);
            if ($cs2Matches->count() > 1) $this->markDemoResult($cs2Matches[1], 2, 1, false, $organizer->id);
            if ($cs2Matches->count() > 1) $cs2Matches[1]->gameServer()->create(['provider' => 'demo', 'region' => 'Singapore', 'map' => 'de_mirage', 'host' => 'demo-server.local', 'port' => 27015, 'status' => 'ready', 'last_health_check_at' => now()]);
            $cs2ReadyMatch = $cs2->matches()->where('status', 'scheduled')->orderBy('round')->orderBy('match_number')->first();
            if ($cs2ReadyMatch) {
                $cs2ReadyMatch->update(['entrant_a_ready_at' => now(), 'entrant_b_ready_at' => null]);
                $cs2ReadyMatch->gameServer()->create(['provider' => 'demo', 'region' => 'Private LAN', 'map' => 'de_mirage', 'host' => 'demo-server.local', 'port' => 27015, 'status' => 'ready', 'last_health_check_at' => now()]);
            }

            $valorant = $tournaments['valorant'];
            $brackets->publish($valorant);
            $valorantMatches = $valorant->matches()->where('status', 'scheduled')->orderBy('match_number')->get();
            if ($valorantMatches->count() > 1) $this->markDemoResult($valorantMatches[0], 2, 1, true, $organizer->id);
            if ($valorantMatches->count() > 1) $this->markDemoResult($valorantMatches[1], 2, 0, false, $organizer->id);
            if ($valorantMatches->count() > 1) $valorantMatches[1]->gameServer()->create(['provider' => 'demo-room', 'region' => 'Singapore', 'map' => 'Ascent', 'host' => 'VAL-ROOM-0426', 'status' => 'ready', 'last_health_check_at' => now()]);

            $mlbb = $tournaments['mlbb'];
            $brackets->publish($mlbb);
            $mlbbMatches = $mlbb->matches()->where('status', 'scheduled')->orderBy('match_number')->get();
            if ($mlbbMatches->count() > 1) $this->markDemoResult($mlbbMatches[0], 1, 0, true, $organizer->id);
            if ($mlbbMatches->count() > 1) $this->markDemoResult($mlbbMatches[1], 1, 0, false, $organizer->id);
            $mlbbRoomMatch = $mlbb->matches()->where('status', 'scheduled')->orderBy('round')->orderBy('match_number')->first();
            if ($mlbbRoomMatch) {
                $mlbbRoomMatch->update(['entrant_a_ready_at' => now(), 'entrant_b_ready_at' => null]);
                $mlbbRoomMatch->gameServer()->create(['provider' => 'demo-room', 'region' => 'Malaysia', 'map' => 'Land of Dawn', 'host' => 'MLBB-ROOM-842190', 'room_code' => '842190', 'status' => 'ready', 'last_health_check_at' => now()]);
            }
            $resultDemo = Tournament::updateOrCreate(
                ['organizer_id' => $organizer->id, 'name' => 'Demo · Player Result Reporting Walkthrough'],
                ['game' => 'mlbb', 'team_size' => 5, 'format' => 'round_robin', 'best_of' => 1, 'capacity' => 4, 'status' => 'live', 'starts_at' => now()->subMinutes(10), 'settings' => ['demo_player_result_reporting' => true]],
            );
            $resultDemo->matches()->delete();
            $resultDemo->entries()->delete();
            foreach (array_slice($rosters['mlbb'], 0, 4) as $index => $team) {
                TournamentEntry::create(['tournament_id' => $resultDemo->id, 'team_id' => $team->id, 'seed' => $index + 1, 'checked_in_at' => now(), 'status' => 'registered']);
            }
            $brackets->publish($resultDemo);
            $walkthroughMatches = $resultDemo->matches()->where('status', 'scheduled')->orderBy('round')->orderBy('match_number')->get();
            $this->markDemoResult($walkthroughMatches[0], 1, 0, false, $organizer->id);
            $pendingConfirmMatch = $walkthroughMatches[0];
            $this->markDemoResult($walkthroughMatches[1], 1, 0, false, $organizer->id);
            $disputedMatch = $walkthroughMatches[1];
            $disputerId = $disputedMatch->entrantB->team->members()->where('user_id', '!=', $organizer->id)->value('user_id');
            MatchDispute::create(['match_id' => $disputedMatch->id, 'raised_by' => $disputerId, 'reason' => 'The submitted score does not match our recorded game result. Please review the match evidence.']);
            $disputedMatch->update(['status' => 'disputed']);
            $resultDemoMatch = $walkthroughMatches[2];
            $practiceTeam = Team::updateOrCreate(['owner_id' => $organizer->id, 'name' => 'Demo · MLBB Join/Leave Practice'], ['game' => 'mlbb', 'is_private' => true]);
            $practiceTeam->members()->delete();
            $practiceTeam->invites()->delete();
            $practiceInviteCode = Str::random(48);
            $practiceTeam->invites()->create(['code_hash' => Hash::make($practiceInviteCode), 'created_by' => $organizer->id, 'expires_at' => now()->addHours(72), 'max_uses' => 10, 'uses' => 0]);
            Cache::put('demo-player-team-invite:'.$organizer->id, $practiceInviteCode, now()->addHours(72));

            $sfPlayers = [];
            for ($i = 1; $i <= 8; $i++) {
                $sfPlayer = User::updateOrCreate(['email' => "demo-sf6-player-{$i}@example.test"], ['name' => ['Jade Dragon', 'Combo Breaker', 'Perfect Parry', 'Frame Trap', 'Drive Rush', 'Corner Carry', 'Pixel Guard', 'Final Round'][$i - 1], 'password' => $demoPassword, 'email_verified_at' => now(), 'account_type' => 'player', 'favorite_game' => 'sf6', 'game_interests' => ['sf6']]);
                $sfPlayer->forceFill(['created_at' => now()->subDays($i), 'updated_at' => now()->subDays($i)])->save();
                $sfPlayers[] = $sfPlayer;
            }
            $sfAnalyticsPlayers = [];
            for ($i = 1; $i <= 32; $i++) {
                $daysAgo = match (true) {
                    $i <= 10 => $i % 14,
                    $i <= 22 => 14 + (($i * 6) % 70),
                    default => 90 + (($i * 17) % 270),
                };
                $registeredAt = now()->subDays($daysAgo)->setTime(11 + ($i % 8), ($i * 9) % 60);
                $sfAnalyticsPlayer = User::updateOrCreate(
                    ['email' => "demo-sf6-analytics-player-{$i}@example.test"],
                    ['name' => "SF6 Analytics Player {$i}", 'password' => $demoPassword, 'email_verified_at' => now(), 'account_type' => 'player', 'favorite_game' => 'sf6', 'game_interests' => ['sf6'], 'bio' => 'Street Fighter demo player interested in tournaments and ranked play.'],
                );
                $sfAnalyticsPlayer->forceFill(['created_at' => $registeredAt, 'updated_at' => $registeredAt])->save();
                $sfAnalyticsPlayers[] = $sfAnalyticsPlayer;
            }            $sf = $tournaments['sf6'];
            $sfEntries = [];
            foreach ($sfPlayers as $index => $player) {
                $sfEntries[] = TournamentEntry::create(['tournament_id' => $sf->id, 'user_id' => $player->id, 'seed' => $index + 1, 'checked_in_at' => now(), 'status' => 'registered']);
            }
            $rotation = $sfEntries;
            $matchNumber = 1;
            for ($round = 1; $round < count($rotation); $round++) {
                for ($pair = 0; $pair < intdiv(count($rotation), 2); $pair++) {
                    $entrantA = $rotation[$pair];
                    $entrantB = $rotation[count($rotation) - 1 - $pair];
                    $aWins = (($matchNumber % 3) === 0) ? 1 : 2;
                    $bWins = $aWins === 2 ? 0 : 2;
                    $status = $matchNumber <= 4 ? 'completed' : 'scheduled';
                    $match = TournamentMatch::create(['tournament_id' => $sf->id, 'round' => $round, 'match_number' => $pair + 1, 'entrant_a_id' => $entrantA->id, 'entrant_b_id' => $entrantB->id, 'best_of' => 3, 'status' => $status, 'score_a' => $status === 'completed' ? $aWins : null, 'score_b' => $status === 'completed' ? $bWins : null, 'winner_entrant_id' => $status === 'completed' ? ($aWins > $bWins ? $entrantA->id : $entrantB->id) : null, 'result_reporter_id' => $status === 'completed' ? $sfPlayers[0]->id : null, 'result_confirmed_by' => $status === 'completed' ? $sfPlayers[1]->id : null, 'result_confirmed_at' => $status === 'completed' ? now()->subDays(2) : null, 'game_stats' => $status === 'completed' ? $this->demoGameStats('sf6', $matchNumber, $entrantA, $entrantB, $aWins, $bWins) : null]);
                    if ($status === 'scheduled') {
                        $match->gameServer()->create(['provider' => 'demo-room', 'region' => 'Singapore', 'map' => 'Battle Hub', 'host' => 'SF6-ROOM-004', 'status' => 'ready', 'last_health_check_at' => now()]);
                    }
                    $matchNumber++;
                }
                $fixed = array_shift($rotation);
                $last = array_pop($rotation);
                array_unshift($rotation, $fixed);
                array_splice($rotation, 1, 0, [$last]);
            }

            $historyTournaments = [];
            $valorantAnalyticsDemo = null;
            foreach (['cs2', 'valorant', 'sf6', 'mlbb'] as $game) {
                $historyName = 'Demo · '. $game .' Rating History';
                $historyMonth = ['cs2' => 2, 'valorant' => 4, 'sf6' => 7, 'mlbb' => 10][$game];
                $historyValues = ['game' => $game, 'team_size' => $game === 'sf6' ? 1 : 5, 'format' => 'round_robin', 'best_of' => 3, 'capacity' => 32, 'status' => 'completed', 'starts_at' => now()->subDays(14), 'created_at' => now()->subMonths($historyMonth), 'settings' => ['demo_history' => true]];
                if ($game === 'valorant') {
                    $historyName = 'Demo · VALORANT Analytics Showcase';
                    $history = Tournament::query()->where('organizer_id', $organizer->id)->whereIn('name', ['Demo · valorant Rating History', $historyName])->orderBy('id')->first();
                    if ($history) $history->update($historyValues + ['name' => $historyName]);
                    else $history = Tournament::create($historyValues + ['organizer_id' => $organizer->id, 'name' => $historyName]);
                } else {
                    $history = Tournament::updateOrCreate(['organizer_id' => $organizer->id, 'name' => $historyName], $historyValues);
                }
                $history->forceFill(['created_at' => $historyValues['created_at'], 'updated_at' => now()])->save();
                $history->matches()->delete();
                $history->entries()->delete();
                $historyTournaments[] = $history;
                $participants = $game === 'sf6' ? $sfPlayers : $rosters[$game];
                $historyEntries = [];
                foreach ($participants as $index => $participant) {
                    $historyEntries[] = TournamentEntry::create([
                        'tournament_id' => $history->id,
                        $game === 'sf6' ? 'user_id' : 'team_id' => $participant->id,
                        'seed' => $index + 1,
                        'checked_in_at' => now()->subDays(14),
                        'status' => 'registered',
                    ]);
                }
                $matchNumber = 1;
                for ($a = 0; $a < count($historyEntries); $a++) {
                    for ($b = $a + 1; $b < count($historyEntries); $b++) {
                        $entrantA = $historyEntries[$a];
                        $entrantB = $historyEntries[$b];
                        $scoreA = $a < $b ? 2 : 1;
                        $scoreB = $scoreA === 2 ? 0 : 2;
                        if ($game === 'valorant') {
                            $aWins = (($a * 3 + $b * 5 + $matchNumber) % 7) < 3;
                            $scoreA = $aWins ? 2 : 1;
                            $scoreB = $aWins ? 0 : 2;
                        }
                        $historyMatch = TournamentMatch::create([
                            'tournament_id' => $history->id,
                            'round' => $matchNumber,
                            'match_number' => 1,
                            'entrant_a_id' => $entrantA->id,
                            'entrant_b_id' => $entrantB->id,
                            'best_of' => 3,
                            'status' => 'scheduled',
                        ]);
                        $this->markDemoResult($historyMatch, $scoreA, $scoreB, true, $organizer->id);
                        $matchNumber++;
                    }
                }
                if ($game === 'valorant') {
                    $valorantAnalyticsDemo = ['id' => $history->id, 'name' => $history->name, 'game' => 'valorant', 'teams' => count($participants), 'matches' => $history->matches()->where('status', 'completed')->count(), 'status' => $history->status];
                }
            }

            $seedingLab = Tournament::updateOrCreate(
                ['organizer_id' => $organizer->id, 'name' => 'Demo · CS2 Dynamic Seeding Lab'],
                ['game' => 'cs2', 'team_size' => 5, 'format' => 'single_elimination', 'best_of' => 3, 'capacity' => 16, 'status' => 'registration_open', 'starts_at' => now()->addDays(3), 'settings' => ['allow_organizer_entry' => false, 'demo_dynamic_seeding' => true]],
            );
            $seedingLab->matches()->delete();
            $seedingLab->entries()->delete();
            foreach ($rosters['cs2'] as $team) {
                TournamentEntry::create(['tournament_id' => $seedingLab->id, 'team_id' => $team->id, 'seed' => null, 'checked_in_at' => now(), 'status' => 'registered']);
            }

            $doubleEliminationDemo = Tournament::updateOrCreate(
                ['organizer_id' => $organizer->id, 'name' => 'Demo · CS2 Double-Elimination Showcase'],
                ['game' => 'cs2', 'team_size' => 5, 'format' => 'double_elimination', 'best_of' => 3, 'capacity' => 16, 'status' => 'registration_open', 'starts_at' => now()->subHour(), 'settings' => ['allow_organizer_entry' => false, 'demo_double_elimination' => true]],
            );
            $doubleEliminationDemo->matches()->delete();
            $doubleEliminationDemo->entries()->delete();
            foreach ($rosters['cs2'] as $index => $team) {
                TournamentEntry::create(['tournament_id' => $doubleEliminationDemo->id, 'team_id' => $team->id, 'seed' => $index + 1, 'checked_in_at' => now(), 'status' => 'registered']);
            }
            $brackets->publish($doubleEliminationDemo);
            for ($round = 1; $round <= 12; $round++) {
                $scheduled = $doubleEliminationDemo->matches()->where('round', $round)->where('status', 'scheduled')->orderBy('match_number')->get();
                if ($scheduled->isEmpty()) break;
                foreach ($scheduled as $index => $demoMatch) {
                    $scoreA = ($index + $round) % 2 === 0 ? 2 : 1;
                    $scoreB = $scoreA === 2 ? 0 : 2;
                    $this->markDemoResult($demoMatch, $scoreA, $scoreB, true, $organizer->id);
                    $brackets->advanceDoubleElimination($demoMatch);
                }
                if ($doubleEliminationDemo->matches()->where('bracket_side', 'grand_final')->exists()) break;
            }
            $doubleFinal = $doubleEliminationDemo->matches()->where('bracket_side', 'grand_final')->latest('id')->first();
            if ($doubleFinal && ! $doubleFinal->gameServer()->exists()) {
                $doubleFinal->gameServer()->create(['provider' => 'demo', 'region' => 'Singapore', 'map' => 'de_anubis', 'host' => 'demo-final.local', 'port' => 27016, 'status' => 'ready', 'last_health_check_at' => now()]);
            }

            return [
                'tournaments' => count($tournaments) + count($historyTournaments) + 3,
                'historical_tournaments' => count($historyTournaments),
                'valorant_analytics_demo' => $valorantAnalyticsDemo,
                'teams' => array_sum(array_map('count', $rosters)),
                'players' => 8 * 5 + 8 * 5 + 8 * 5 + count($sfPlayers) + count($sfAnalyticsPlayers),
                'matches' => array_sum(array_map(fn (Tournament $tournament) => $tournament->matches()->count(), array_merge(array_values($tournaments), $historyTournaments, [$seedingLab, $doubleEliminationDemo, $resultDemo]))),
                'dynamic_seeding_tournament' => ['id' => $seedingLab->id, 'name' => $seedingLab->name, 'game' => 'cs2', 'participants' => count($rosters['cs2']), 'status' => $seedingLab->status],
                'double_elimination_tournament' => ['id' => $doubleEliminationDemo->id, 'name' => $doubleEliminationDemo->name, 'participants' => count($rosters['cs2']), 'matches' => $doubleEliminationDemo->matches()->count(), 'status' => $doubleEliminationDemo->fresh()->status],
                'mlbb_emulator_demo' => $mlbbRoomMatch ? ['tournament_id' => $mlbb->id, 'tournament_name' => $mlbb->name, 'match_count' => $mlbb->matches()->count(), 'team_invite_code' => $practiceInviteCode] : null,
                'match_operations_demo' => [
                    'cs2' => $cs2ReadyMatch ? ['tournament_id' => $cs2->id, 'match_id' => $cs2ReadyMatch->id] : null,
                    'mlbb' => $mlbbRoomMatch ? ['tournament_id' => $mlbb->id, 'match_id' => $mlbbRoomMatch->id] : null,
                ],
                'player_result_demo' => ['tournament_id' => $resultDemo->id, 'tournament_name' => $resultDemo->name, 'match_id' => $resultDemoMatch->id, 'pending_match_id' => $pendingConfirmMatch->id, 'disputed_match_id' => $disputedMatch->id],
            ];
        });

        return response()->json(['message' => 'Demo data is ready.', 'data' => $created]);
    }

    private function markDemoResult(TournamentMatch $match, int $scoreA, int $scoreB, bool $confirmed, int $organizerId): void
    {
        $entrantA = $match->entrantA;
        $entrantB = $match->entrantB;
        $reporter = $entrantA->team?->members()->where('user_id', '!=', $organizerId)->value('user_id') ?: $entrantA->user_id;
        $winnerA = $scoreA > $scoreB;
        $winner = $winnerA ? $entrantA : $entrantB;
        $match->update([
            'status' => $confirmed ? 'completed' : 'result_pending',
            'score_a' => $scoreA,
            'score_b' => $scoreB,
            'winner_entrant_id' => $confirmed ? $winner->id : null,
            'winner_team_id' => $confirmed ? $winner->team_id : null,
            'result_reporter_id' => $reporter,
            'result_confirmed_by' => $confirmed ? ($entrantB->team?->members()->where('user_id', '!=', $organizerId)->value('user_id') ?: $entrantB->user_id) : null,
            'result_confirmed_at' => $confirmed ? now()->subHours(2) : null,
            'game_stats' => $confirmed ? $this->demoGameStats($match->tournament->game, $match->id, $entrantA, $entrantB, $scoreA, $scoreB, $organizerId) : null,
        ]);
    }

    private function demoGameStats(string $game, int $seed, TournamentEntry $entrantA, TournamentEntry $entrantB, int $scoreA, int $scoreB, ?int $organizerId = null): array
    {
        $labels = match ($game) {
            'cs2' => ['de_ancient', 'de_anubis', 'de_dust2', 'de_inferno', 'de_mirage', 'de_nuke', 'de_overpass', 'de_vertigo'],
            'valorant' => ['Abyss', 'Ascent', 'Bind', 'Breeze', 'Fracture', 'Haven', 'Icebox', 'Lotus', 'Pearl', 'Split', 'Sunset'],
            'sf6' => ['Battle Hub', 'Barmaley Steelworks', 'Carrier Byron Taylor', 'Colosseo', 'Fête Foraine', 'King Street', 'Metro City Downtown', 'Old Town Market', 'Ranger’s Hut', 'Suval’hal Arena'],
            default => ['Land of Dawn'],
        };
        $map = $labels[$seed % count($labels)];
        $agents = ['Astra', 'Breach', 'Brimstone', 'Chamber', 'Clove', 'Cypher', 'Deadlock', 'Fade', 'Gekko', 'Harbor', 'Iso', 'Jett', 'KAY/O', 'Killjoy', 'Miks', 'Neon', 'Omen', 'Phoenix', 'Raze', 'Reyna', 'Sage', 'Skye', 'Sova', 'Tejo', 'Veto', 'Viper', 'Vyse', 'Waylay', 'Yoru'];
        $heroes = ['Aamon', 'Angela', 'Arlott', 'Balmond', 'Barats', 'Benedetta', 'Chou', 'Claude', 'Cici', 'Estes', 'Fredrinn', 'Fanny', 'Guinevere', 'Hilda', 'Ixia', 'Joy', 'Kalea', 'Lancelot', 'Layla', 'Ling', 'Lukas', 'Marcel', 'Mathilda', 'Miya', 'Nana', 'Nolan', 'Obsidia', 'Paquito', 'Pharsa', 'Ruby', 'Suyou', 'Tigreal', 'Valentina', 'Vexana', 'X.Borg', 'Yu Zhong', 'Zhuxin'];
        $characters = ['Akuma', 'A.K.I.', 'Blanka', 'Cammy', 'Chun-Li', 'Dee Jay', 'Dhalsim', 'Ed', 'Elena', 'Guile', 'Jamie', 'Juri', 'JP', 'Ken', 'Kimberly', 'Luke', 'Mai', 'Manon', 'Marisa', 'M. Bison', 'Rashid', 'Ryu', 'Sagat', 'Terry', 'Zangief'];
        $valorantWeapons = ['Classic', 'Ghost', 'Sheriff', 'Spectre', 'Bulldog', 'Guardian', 'Phantom', 'Vandal', 'Marshal', 'Operator', 'Odin'];
        $weapons = ['AK-47', 'AWP', 'Desert Eagle', 'FAMAS', 'Galil AR', 'Glock-18', 'M4A1-S', 'M4A4', 'MP9', 'P250', 'SSG 08', 'USP-S'];
        $entrants = [];
        foreach ([[$entrantA, 0, $scoreA], [$entrantB, 1, $scoreB]] as [$entry, $side, $seriesScore]) {
            $members = $entry->team
                ? $entry->team->members()->with('user:id,name')->get()->filter(fn (TeamMember $member) => $organizerId === null || (int) $member->user_id !== $organizerId)->values()
                : collect([$entry->user])->filter();
            $lines = [];
            foreach ($members as $index => $member) {
                $user = $member instanceof TeamMember ? $member->user : $member;
                if (! $user) continue;
                $base = ($seed * 11 + (int) $user->id * 7 + $side * 13) % 18;
                $line = ['player_id' => $user->id, 'name' => $user->name];
                if ($game === 'cs2') {
                    $line += ['weapon' => $weapons[((int) $user->id + $seed + $side) % count($weapons)], 'kills' => 12 + $base, 'deaths' => 9 + (($base + $side * 3) % 12), 'assists' => 3 + ($base % 8), 'adr' => round(68 + $base * 2.3, 1)];
                } elseif ($game === 'valorant') {
                    $weaponIndex = ((int) $user->id + $seed + $side) % count($valorantWeapons);
                    $line += ['agent' => $agents[((int) $user->id + $seed) % count($agents)], 'weapons' => [$valorantWeapons[$weaponIndex], $valorantWeapons[($weaponIndex + 3) % count($valorantWeapons)]], 'kills' => 10 + $base, 'deaths' => 10 + (($base + $side * 2) % 10), 'assists' => 2 + ($base % 8), 'acs' => 160 + $base * 9, 'first_bloods' => $base % 4, 'plants' => $side === 0 ? 2 : 1];
                } elseif ($game === 'sf6') {
                    $line += ['character' => $characters[((int) $user->id + $seed) % count($characters)], 'sets_won' => $seriesScore, 'perfect_rounds' => ($base % 5 === 0) ? 1 : 0, 'drive_impacts' => 2 + ($base % 7)];
                } else {
                    $line += ['hero' => $heroes[((int) $user->id + $seed) % count($heroes)], 'kills' => 2 + ($base % 9), 'deaths' => 1 + ($base % 6), 'assists' => 4 + ($base % 13), 'towers' => $side === 0 ? 3 : 1];
                }
                $lines[] = $line;
            }
            $entrants[(string) $entry->id] = match ($game) {
                'cs2' => ['rounds_won' => $side === 0 ? 13 : 9, 'kills' => array_sum(array_column($lines, 'kills')), 'deaths' => array_sum(array_column($lines, 'deaths')), 'assists' => array_sum(array_column($lines, 'assists')), 'players' => $lines],
                'valorant' => ['rounds_won' => $side === 0 ? 13 : 9, 'kills' => array_sum(array_column($lines, 'kills')), 'deaths' => array_sum(array_column($lines, 'deaths')), 'assists' => array_sum(array_column($lines, 'assists')), 'plants' => $side === 0 ? 5 : 3, 'players' => $lines],
                'sf6' => ['sets_won' => $seriesScore, 'perfect_rounds' => array_sum(array_column($lines, 'perfect_rounds')), 'drive_impacts' => array_sum(array_column($lines, 'drive_impacts')), 'players' => $lines],
                default => ['kills' => array_sum(array_column($lines, 'kills')), 'deaths' => array_sum(array_column($lines, 'deaths')), 'assists' => array_sum(array_column($lines, 'assists')), 'towers' => $side === 0 ? 6 : 3, 'players' => $lines],
            };
        }

        return ['map' => $map, 'entrants' => $entrants];
    }
}
