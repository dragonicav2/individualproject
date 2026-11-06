<?php

namespace App\Services;

use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentMatch;
use App\Models\User;
use Carbon\CarbonImmutable;

class AdminAnalyticsService
{
    private const GAMES = [
        'cs2' => 'Counter-Strike 2',
        'valorant' => 'VALORANT',
        'sf6' => 'Street Fighter 6',
        'mlbb' => 'Mobile Legends',
    ];

    public function overview(string $period = 'week'): array
    {
        [$buckets, $from] = $this->buckets($period);
        $bucketKeys = array_column($buckets, 'key');
        $registrations = array_fill_keys(array_keys(self::GAMES), array_fill_keys($bucketKeys, 0));
        $tournamentTrend = array_fill_keys(array_keys(self::GAMES), array_fill_keys($bucketKeys, 0));

        $newPlayers = User::query()
            ->where('account_type', 'player')
            ->where('created_at', '>=', $from)
            ->get(['favorite_game', 'game_interests', 'created_at']);
        foreach ($newPlayers as $user) {
            $interests = $user->game_interests ?: array_filter([$user->favorite_game]);
            $key = $this->bucketKey($user->created_at, $period);
            foreach (array_unique($interests) as $game) {
                if (isset($registrations[$game][$key])) $registrations[$game][$key]++;
            }
        }

        $newTournaments = Tournament::query()
            ->where('created_at', '>=', $from)
            ->get(['game', 'created_at']);
        foreach ($newTournaments as $tournament) {
            if (! isset(self::GAMES[$tournament->game])) continue;
            $key = $this->bucketKey($tournament->created_at, $period);
            if (isset($tournamentTrend[$tournament->game][$key])) $tournamentTrend[$tournament->game][$key]++;
        }

        $playerCounts = array_fill_keys(array_keys(self::GAMES), 0);
        $allPlayers = User::query()->where('account_type', 'player')->get(['favorite_game', 'game_interests']);
        foreach ($allPlayers as $user) {
            $interests = $user->game_interests ?: array_filter([$user->favorite_game]);
            foreach (array_unique($interests) as $game) {
                if (array_key_exists($game, $playerCounts)) $playerCounts[$game]++;
            }
        }

        $tournamentCounts = Tournament::query()
            ->selectRaw('game, COUNT(*) as total')
            ->groupBy('game')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->game => (int) $row->total]);

        $activeTournamentCounts = Tournament::query()
            ->whereIn('status', ['registration_open', 'live'])
            ->selectRaw('game, COUNT(*) as total')
            ->groupBy('game')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->game => (int) $row->total]);

        $entryCounts = TournamentEntry::query()
            ->join('tournaments', 'tournaments.id', '=', 'tournament_entries.tournament_id')
            ->where('tournament_entries.status', 'registered')
            ->selectRaw('tournaments.game, COUNT(*) as entries, SUM(tournaments.team_size) as player_slots')
            ->groupBy('tournaments.game')
            ->get()
            ->keyBy('game');

        $verifiedMatchCounts = TournamentMatch::query()
            ->join('tournaments', 'tournaments.id', '=', 'tournament_matches.tournament_id')
            ->where('tournament_matches.status', 'completed')
            ->selectRaw('tournaments.game, COUNT(*) as total')
            ->groupBy('tournaments.game')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->game => (int) $row->total]);

        $games = [];
        foreach (self::GAMES as $code => $name) {
            $entry = $entryCounts->get($code);
            $games[] = [
                'code' => $code,
                'name' => $name,
                'players_interested' => (int) $playerCounts[$code],
                'tournaments' => (int) $tournamentCounts->get($code, 0),
                'active_tournaments' => (int) $activeTournamentCounts->get($code, 0),
                'entrant_registrations' => (int) ($entry->entries ?? 0),
                'registered_player_slots' => (int) ($entry->player_slots ?? 0),
                'verified_matches' => (int) $verifiedMatchCounts->get($code, 0),
            ];
        }

        $totals = [
            'players' => User::query()->where('account_type', 'player')->count(),
            'organizers' => User::query()->where('account_type', 'organizer')->count(),
            'tournaments' => Tournament::query()->count(),
            'active_tournaments' => Tournament::query()->whereIn('status', ['registration_open', 'live'])->count(),
            'completed_tournaments' => Tournament::query()->where('status', 'completed')->count(),
            'verified_matches' => TournamentMatch::query()->where('status', 'completed')->count(),
            'pending_results' => TournamentMatch::query()->where('status', 'result_pending')->count(),
            'open_disputes' => TournamentMatch::query()->where('status', 'disputed')->count(),
            'new_players_in_period' => $newPlayers->count(),
            'new_tournaments_in_period' => $newTournaments->count(),
        ];

        return [
            'totals' => $totals,
            'games' => $games,
            'period' => $period,
            'buckets' => $buckets,
            'charts' => [
                'player_interest_growth' => $this->series($registrations),
                'tournament_creation' => $this->series($tournamentTrend),
            ],
            'updated_at' => now()->toIso8601String(),
        ];
    }

    private function buckets(string $period): array
    {
        $now = CarbonImmutable::now();
        [$start, $count] = match ($period) {
            'day' => [$now->startOfDay()->subDays(13), 14],
            'month' => [$now->startOfMonth()->subMonths(11), 12],
            default => [$now->startOfWeek()->subWeeks(11), 12],
        };

        $buckets = [];
        $cursor = $start;
        for ($i = 0; $i < $count; $i++) {
            $key = match ($period) {
                'day' => $cursor->format('Y-m-d'),
                'month' => $cursor->format('Y-m'),
                default => $cursor->format('Y-m-d'),
            };
            $label = match ($period) {
                'day' => $cursor->format('M j'),
                'month' => $cursor->format('M Y'),
                default => 'Week of '.$cursor->format('M j'),
            };
            $buckets[] = ['key' => $key, 'label' => $label];
            $cursor = match ($period) {
                'day' => $cursor->addDay(),
                'month' => $cursor->addMonth(),
                default => $cursor->addWeek(),
            };
        }

        return [$buckets, $start];
    }

    private function bucketKey($date, string $period): string
    {
        $value = CarbonImmutable::parse($date);
        return match ($period) {
            'day' => $value->format('Y-m-d'),
            'month' => $value->format('Y-m'),
            default => $value->startOfWeek()->format('Y-m-d'),
        };
    }

    private function series(array $grid): array
    {
        return collect($grid)->map(fn (array $values, string $code) => [
            'code' => $code,
            'name' => self::GAMES[$code],
            'values' => array_values($values),
        ])->values()->all();
    }
}

