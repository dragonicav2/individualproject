<?php

namespace App\Services;

use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;

class AnalyticsService
{
    private const GAMES = [
        'cs2' => ['name' => 'Counter-Strike 2', 'kind' => 'Team ELO', 'focus' => 'Maps, kills, assists, and ADR'],
        'valorant' => ['name' => 'VALORANT', 'kind' => 'Team ELO', 'focus' => 'Agents, ACS, first bloods, and plants'],
        'sf6' => ['name' => 'Street Fighter 6', 'kind' => 'Player ELO', 'focus' => 'Characters, sets, and perfect rounds'],
        'mlbb' => ['name' => 'Mobile Legends', 'kind' => 'Team ELO', 'focus' => 'Heroes, kills, assists, and towers'],
    ];

    public function overview(EloRatingService $eloRatings): array
    {
        $tournaments = Tournament::query()->whereIn('status', ['registration_open', 'live', 'completed'])->withCount('entries')->get();
        $matches = TournamentMatch::query()->where('status', 'completed')
            ->whereHas('tournament', fn ($query) => $query->whereIn('status', ['live', 'completed']))
            ->with(['tournament:id,game,team_size', 'entrantA.team:id,name', 'entrantA.user:id,name', 'entrantB.team:id,name', 'entrantB.user:id,name'])
            ->orderByRaw('COALESCE(result_confirmed_at, updated_at)')
            ->orderBy('id')->get();

        $ratings = $eloRatings->calculate($matches);
        $players = [];
        $individualElo = [];
        $gameData = [];
        foreach (self::GAMES as $code => $details) {
            $gameData[$code] = ['verified_matches' => 0, 'active_tournaments' => 0, 'entrants' => 0, 'stat_matches' => 0, 'maps' => [], 'agents' => [], 'weapons' => [], 'map_agent_usage' => [], 'characters' => [], 'heroes' => [], 'totals' => [], 'players' => [], 'teams' => []];
        }

        foreach ($tournaments as $tournament) {
            if (! isset($gameData[$tournament->game])) continue;
            if (in_array($tournament->status, ['live', 'registration_open'], true)) $gameData[$tournament->game]['active_tournaments']++;
            $gameData[$tournament->game]['entrants'] += $tournament->entries_count;
        }

        foreach ($matches as $match) {
            $game = $match->tournament->game;
            if (! isset($gameData[$game])) continue;
            $gameData[$game]['verified_matches']++;
            $stats = $match->game_stats;
            if (! is_array($stats)) continue;
            $gameData[$game]['stat_matches']++;
            if ($game === 'valorant') {
                $sideLines = [];
                foreach (['A' => $match->entrantA, 'B' => $match->entrantB] as $side => $entry) {
                    $entryId = (string) ($entry?->id ?? '');
                    $sideLines[$side] = $stats['entrants'][$entryId]['players'] ?? [];
                }
                if ($sideLines['A'] && $sideLines['B']) {
                    $avg = [];
                    foreach ($sideLines as $side => $lines) {
                        $avg[$side] = count($lines) ? array_sum(array_map(fn ($line) => $individualElo[$game][(string) ($line['player_id'] ?? '')]['rating'] ?? 1000, $lines)) / count($lines) : 1000;
                    }
                    foreach ($sideLines as $side => $lines) {
                        $opponent = $side === 'A' ? 'B' : 'A';
                        $actual = ((string) $match->winner_entrant_id === (string) ($side === 'A' ? $match->entrant_a_id : $match->entrant_b_id)) ? 1 : 0;
                        $expected = 1 / (1 + (10 ** (($avg[$opponent] - $avg[$side]) / 400)));
                        foreach ($lines as $line) {
                            $id = (string) ($line['player_id'] ?? '');
                            if ($id === '') continue;
                            $individualElo[$game][$id] ??= ['rating' => 1000.0, 'matches' => 0];
                            $individualElo[$game][$id]['rating'] += 32 * ($actual - $expected);
                            $individualElo[$game][$id]['matches']++;
                        }
                    }
                }
            }
            $map = trim((string) ($stats['map'] ?? ''));
            if ($map !== '') $gameData[$game]['maps'][$map] = ($gameData[$game]['maps'][$map] ?? 0) + 1;
            foreach (($stats['entrants'] ?? []) as $entryId => $entrantStats) {
                $entry = collect([$match->entrantA, $match->entrantB])->first(fn ($candidate) => (string) $candidate?->id === (string) $entryId);
                $didWin = (string) $match->winner_entrant_id === (string) $entryId;
                $team = $entry?->team;
                if ($team) {
                    $teamId = (string) $team->id;
                    $gameData[$game]['teams'][$teamId] ??= ['team_id' => $team->id, 'name' => $team->name, 'matches' => 0, 'wins' => 0, 'losses' => 0, 'kills' => 0, 'deaths' => 0, 'assists' => 0, 'first_bloods' => 0, 'plants' => 0, 'rounds_won' => 0, 'maps' => [], 'agents' => [], 'players' => []];
                    $teamRow = &$gameData[$game]['teams'][$teamId];
                    $teamRow['matches']++;
                    $teamRow[$didWin ? 'wins' : 'losses']++;
                    if ($map !== '') $teamRow['maps'][$map] = ($teamRow['maps'][$map] ?? 0) + 1;
                }
                foreach (['kills', 'deaths', 'assists', 'plants', 'towers', 'perfect_rounds', 'drive_impacts', 'rounds_won'] as $metric) {
                    $gameData[$game]['totals'][$metric] = ($gameData[$game]['totals'][$metric] ?? 0) + (float) ($entrantStats[$metric] ?? 0);
                    if ($team && ! in_array($metric, ['kills', 'deaths', 'assists'], true)) $teamRow[$metric] = ($teamRow[$metric] ?? 0) + (float) ($entrantStats[$metric] ?? 0);
                }
                foreach (($entrantStats['players'] ?? []) as $line) {
                    $playerId = (string) ($line['player_id'] ?? 'unknown');
                    $players[$game][$playerId] ??= ['player_id' => $playerId, 'name' => $line['name'] ?? 'Player', 'matches' => 0, 'wins' => 0, 'losses' => 0, 'kills' => 0, 'deaths' => 0, 'assists' => 0, 'adr_total' => 0, 'acs_total' => 0, 'first_bloods' => 0, 'plants' => 0, 'sets_won' => 0, 'perfect_rounds' => 0, 'drive_impacts' => 0, 'towers' => 0, 'agents' => [], 'characters' => [], 'heroes' => [], 'weapons' => [], 'pick_stats' => []];
                    $row = &$players[$game][$playerId];
                    $row['matches']++;
                    $row[$didWin ? 'wins' : 'losses']++;
                    foreach (['kills', 'deaths', 'assists', 'sets_won', 'perfect_rounds', 'drive_impacts', 'towers'] as $metric) $row[$metric] += (int) ($line[$metric] ?? 0);
                    $row['adr_total'] += (float) ($line['adr'] ?? 0);
                    $row['acs_total'] += (float) ($line['acs'] ?? 0);
                    foreach (['first_bloods', 'plants'] as $metric) $row[$metric] += (int) ($line[$metric] ?? 0);
                    foreach (['agent' => 'agents', 'character' => 'characters', 'hero' => 'heroes', 'weapon' => 'weapons'] as $source => $target) {
                        if (! empty($line[$source])) {
                            $pick = (string) $line[$source];
                            $row[$target][$pick] = ($row[$target][$pick] ?? 0) + 1;
                            $gameData[$game][$target][$pick] = ($gameData[$game][$target][$pick] ?? 0) + 1;
                            $row['pick_stats'][$pick] ??= ['matches' => 0, 'wins' => 0, 'losses' => 0, 'kills' => 0, 'deaths' => 0, 'assists' => 0, 'adr_total' => 0, 'acs_total' => 0, 'first_bloods' => 0, 'plants' => 0, 'sets_won' => 0, 'perfect_rounds' => 0, 'drive_impacts' => 0, 'towers' => 0];
                            $pickStats = &$row['pick_stats'][$pick];
                            $pickStats['matches']++;
                            $pickStats[$didWin ? 'wins' : 'losses']++;
                            foreach (['kills', 'deaths', 'assists', 'first_bloods', 'plants', 'sets_won', 'perfect_rounds', 'drive_impacts', 'towers'] as $metric) $pickStats[$metric] += (int) ($line[$metric] ?? 0);
                            $pickStats['adr_total'] += (float) ($line['adr'] ?? 0);
                            $pickStats['acs_total'] += (float) ($line['acs'] ?? 0);
                            unset($pickStats);
                        }
                    }
                    if ($game === 'valorant') {
                        foreach (($line['weapons'] ?? []) as $weapon) {
                            if (! is_string($weapon) || trim($weapon) === '') continue;
                            $weapon = trim($weapon);
                            $gameData[$game]['weapons'][$weapon] = ($gameData[$game]['weapons'][$weapon] ?? 0) + 1;
                        }
                    }
                    if ($team) {
                        foreach (['kills', 'deaths', 'assists', 'first_bloods'] as $metric) $teamRow[$metric] += (int) ($line[$metric] ?? 0);
                        $teamRow['players'][$playerId] ??= ['player_id' => $playerId, 'name' => $line['name'] ?? 'Player', 'matches' => 0, 'wins' => 0, 'kills' => 0, 'deaths' => 0, 'assists' => 0, 'agents' => [], 'acs_total' => 0, 'first_bloods' => 0, 'plants' => 0];
                        $teamPlayer = &$teamRow['players'][$playerId];
                        $teamPlayer['matches']++;
                        if ($didWin) $teamPlayer['wins']++;
                        foreach (['kills', 'deaths', 'assists', 'first_bloods', 'plants'] as $metric) $teamPlayer[$metric] += (int) ($line[$metric] ?? 0);
                        $teamPlayer['acs_total'] += (float) ($line['acs'] ?? 0);
                        if (! empty($line['agent'])) {
                            $agent = (string) $line['agent'];
                            $teamRow['agents'][$agent] = ($teamRow['agents'][$agent] ?? 0) + 1;
                            $teamPlayer['agents'][$agent] = ($teamPlayer['agents'][$agent] ?? 0) + 1;
                            if ($map !== '') $gameData[$game]['map_agent_usage'][$map][$agent] = ($gameData[$game]['map_agent_usage'][$map][$agent] ?? 0) + 1;
                        }
                        unset($teamPlayer);
                    }
                    unset($row);
                }
                if ($team) unset($teamRow);
            }
            foreach (['agent' => 'agents', 'character' => 'characters', 'hero' => 'heroes'] as $source => $target) {
                if (! empty($stats[$source])) $gameData[$game][$target][$stats[$source]] = ($gameData[$game][$target][$stats[$source]] ?? 0) + 1;
            }
        }

        $games = [];
        foreach (self::GAMES as $code => $details) {
            $leaderboard = array_values($ratings[$code] ?? []);
            usort($leaderboard, fn (array $a, array $b) => ($b['rating'] <=> $a['rating']) ?: ($b['wins'] <=> $a['wins']) ?: strcmp($a['name'], $b['name']));
            $leaderboard = array_slice(array_map(function (array $row, int $index): array {
                $row['rank'] = $index + 1;
                $row['rating'] = (int) round($row['rating']);
                return $row;
            }, $leaderboard, array_keys($leaderboard)), 0, 8);

            $playerRows = array_values($players[$code] ?? []);
            $avatarUrls = User::query()->whereIn('id', array_filter(array_map(fn ($row) => is_numeric($row['player_id'] ?? null) ? (int) $row['player_id'] : null, $playerRows)))->get(['id', 'avatar_path', 'updated_at'])->mapWithKeys(fn ($user) => [(string) $user->id => $user->avatar_url]);
            foreach ($playerRows as &$player) {
                $player['adr'] = $player['matches'] ? round($player['adr_total'] / $player['matches'], 1) : 0;
                $player['acs'] = $player['matches'] ? (int) round($player['acs_total'] / $player['matches']) : 0;
                $player['kd'] = round($player['kills'] / max(1, $player['deaths']), 2);
                $player['kda'] = round(($player['kills'] + $player['assists']) / max(1, $player['deaths']), 2);
                $player['acs'] = $player['matches'] ? (int) round($player['acs_total'] / $player['matches']) : 0;
                unset($player['acs_total']);
                $player['win_rate'] = $player['matches'] ? round($player['wins'] * 100 / $player['matches'], 1) : 0;
                $player['kda_ratio'] = $player['kda'];
                $player['elo_rating'] = (int) round($individualElo[$code][(string) $player['player_id']]['rating'] ?? 1000);
                $player['avatar_url'] = $avatarUrls->get((string) $player['player_id']);
                unset($player['adr_total'], $player['acs_total']);
                foreach ($player['pick_stats'] as &$pickStats) {
                    $pickStats['adr'] = $pickStats['matches'] ? round($pickStats['adr_total'] / $pickStats['matches'], 1) : 0;
                    $pickStats['acs'] = $pickStats['matches'] ? (int) round($pickStats['acs_total'] / $pickStats['matches']) : 0;
                    $pickStats['kd'] = round($pickStats['kills'] / max(1, $pickStats['deaths']), 2);
                    $pickStats['kda'] = round(($pickStats['kills'] + $pickStats['assists']) / max(1, $pickStats['deaths']), 2);
                    $pickStats['win_rate'] = $pickStats['matches'] ? round($pickStats['wins'] * 100 / $pickStats['matches'], 1) : 0;
                    unset($pickStats['adr_total'], $pickStats['acs_total']);
                }
                unset($pickStats);
            }
            unset($player);
            $sortMetric = match ($code) { 'valorant' => 'acs', 'sf6' => 'sets_won', default => 'kills' };
            usort($playerRows, fn (array $a, array $b) => ($b[$sortMetric] <=> $a[$sortMetric]) ?: strcmp($a['name'], $b['name']));
            $playerLeaderboards = [];
            foreach (['kd' => 'K/D leaders', 'kda' => 'K/D/A leaders', 'elo_rating' => 'Player ELO', 'wins' => 'Most wins', 'matches' => 'Most played'] as $metric => $label) {
                $rows = $playerRows;
                usort($rows, fn (array $a, array $b) => ($b[$metric] <=> $a[$metric]) ?: ($b['matches'] <=> $a['matches']) ?: strcmp($a['name'], $b['name']));
                $playerLeaderboards[$metric] = ['label' => $label, 'players' => array_slice($rows, 0, 8)];
            }

            $data = $gameData[$code];
            $games[] = [
                'code' => $code,
                'name' => $details['name'],
                'elo_kind' => $details['kind'],
                'focus' => $details['focus'],
                'verified_matches' => $data['verified_matches'],
                'active_tournaments' => $data['active_tournaments'],
                'entrants' => $data['entrants'],
                'stat_matches' => $data['stat_matches'],
                'leaderboard' => $leaderboard,
                'players' => $playerRows,
                'top_players' => array_slice($playerRows, 0, 5),
                'player_leaderboards' => $playerLeaderboards,
                'weapons' => $this->rankedRows($data['weapons']),
                'map_agent_usage' => $this->mapAgentUsage($data['maps'], $data['map_agent_usage']),
                'teams' => $this->teamRows($data['teams']),
                'metrics' => $this->metrics($code, $data),
                'distribution' => $this->distribution($code, $data),
            ];
        }

        $verified = $matches->count();
        return [
            'totals' => [
                'verified_matches' => $verified,
                'active_tournaments' => $tournaments->whereIn('status', ['registration_open', 'live'])->count(),
                'entrants' => $tournaments->sum('entries_count'),
                'games_tracked' => count(self::GAMES),
            ],
            'games' => $games,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    private function rankedRows(array $counts): array
    {
        arsort($counts);
        $max = max($counts ?: [1]);
        return array_map(fn (string $name, int $count) => ['name' => $name, 'count' => $count, 'percent' => (int) round($count * 100 / $max)], array_keys($counts), array_values($counts));
    }

    private function mapAgentUsage(array $maps, array $usage): array
    {
        arsort($maps);
        $rows = [];
        foreach ($maps as $name => $count) {
            $agents = $this->rankedRows($usage[$name] ?? []);
            $rows[] = ['name' => $name, 'matches' => $count, 'agents' => array_slice($agents, 0, 8)];
        }
        return $rows;
    }

    private function teamRows(array $teams): array
    {
        $rows = [];
        foreach ($teams as $team) {
            $players = array_values($team['players']);
            foreach ($players as &$player) {
                $player['kd'] = round($player['kills'] / max(1, $player['deaths']), 2);
                $player['kda'] = round(($player['kills'] + $player['assists']) / max(1, $player['deaths']), 2);
                $player['acs'] = $player['matches'] ? (int) round($player['acs_total'] / $player['matches']) : 0;
                unset($player['acs_total']);
                arsort($player['agents']);
                $player['top_agent'] = array_key_first($player['agents']) ?: null;
            }
            unset($player);
            usort($players, fn (array $a, array $b) => ($b['kda'] <=> $a['kda']) ?: ($b['matches'] <=> $a['matches']));
            arsort($team['maps']);
            $rows[] = [
                'team_id' => $team['team_id'], 'name' => $team['name'], 'matches' => $team['matches'], 'wins' => $team['wins'], 'losses' => $team['losses'],
                'win_rate' => $team['matches'] ? round($team['wins'] * 100 / $team['matches'], 1) : 0,
                'kills' => $team['kills'], 'deaths' => $team['deaths'], 'assists' => $team['assists'], 'kd' => round($team['kills'] / max(1, $team['deaths']), 2),
                'first_bloods' => $team['first_bloods'], 'plants' => $team['plants'], 'rounds_won' => $team['rounds_won'],
                'agents' => $this->rankedRows($team['agents']), 'maps' => array_map(fn ($name, $count) => ['name' => $name, 'matches' => $count], array_keys($team['maps']), array_values($team['maps'])),
                'players' => $players,
            ];
        }
        usort($rows, fn (array $a, array $b) => ($b['win_rate'] <=> $a['win_rate']) ?: ($b['matches'] <=> $a['matches']));
        return $rows;
    }

    private function metrics(string $game, array $data): array
    {
        $totalPlayers = array_sum(array_map(fn (array $player) => $player['matches'], array_values($data['players'])));
        $totalKills = $data['totals']['kills'] ?? 0;
        $items = [['label' => 'Verified matches', 'value' => $data['verified_matches']], ['label' => 'Active events', 'value' => $data['active_tournaments']]];
        $items[] = match ($game) {
            'cs2' => ['label' => 'Average kills / team', 'value' => $data['stat_matches'] ? round($totalKills / ($data['stat_matches'] * 2), 1) : '—'],
            'valorant' => ['label' => 'Average kills / team', 'value' => $data['stat_matches'] ? round($totalKills / ($data['stat_matches'] * 2), 1) : '—'],
            'sf6' => ['label' => 'Perfect rounds', 'value' => $data['totals']['perfect_rounds'] ?? 0],
            default => ['label' => 'Average kills / team', 'value' => $data['stat_matches'] ? round($totalKills / ($data['stat_matches'] * 2), 1) : '—'],
        };
        $items[] = ['label' => 'Player stat lines', 'value' => $totalPlayers];
        if ($game === 'cs2') $items[] = ['label' => 'Average ADR', 'value' => $this->averagePlayerMetric($data['players'], 'adr_total')];
        if ($game === 'valorant') $items[] = ['label' => 'Average ACS', 'value' => $this->averagePlayerMetric($data['players'], 'acs_total')];
        if ($game === 'mlbb') $items[] = ['label' => 'Tower takedowns', 'value' => $data['totals']['towers'] ?? 0];
        if ($game === 'sf6') $items[] = ['label' => 'Drive impacts', 'value' => $data['totals']['drive_impacts'] ?? 0];
        return $items;
    }

    private function averagePlayerMetric(array $players, string $field): int|float|string
    {
        $lines = array_values($players);
        $matches = array_sum(array_map(fn (array $player) => $player['matches'], $lines));
        if (! $matches) return '—';
        $total = array_sum(array_map(fn (array $player) => $player[$field], $lines));
        return round($total / $matches, 1);
    }

    private function distribution(string $game, array $data): array
    {
        $key = match ($game) { 'valorant' => 'agents', 'sf6' => 'characters', 'mlbb' => 'heroes', default => 'maps' };
        $rows = $data[$key];
        arsort($rows);
        $total = array_sum($rows);
        $max = max($rows ?: [1]);
        return array_slice(array_map(fn (string $name, int $count) => ['name' => $name, 'count' => $count, 'percent' => (int) round($count / $max * 100), 'share' => $total ? round($count / $total * 100) : 0], array_keys($rows), array_values($rows)), 0, 5);
    }
}
