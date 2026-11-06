<?php

namespace App\Services;

use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentMatch;
use Illuminate\Support\Facades\DB;

class TournamentSeedingService
{
    public function __construct(private readonly EloRatingService $eloRatings) {}

    public function keyForEntry(TournamentEntry $entry): string
    {
        return $this->eloRatings->keyForEntry($entry);
    }

    public function ratingsFor(Tournament $tournament): array
    {
        $matches = TournamentMatch::query()
            ->where('status', 'completed')
            ->where('tournament_id', '<>', $tournament->id)
            ->whereHas('tournament', fn ($query) => $query->where('game', $tournament->game))
            ->with(['tournament:id,game,team_size', 'entrantA.team:id,name', 'entrantA.user:id,name', 'entrantB.team:id,name', 'entrantB.user:id,name'])
            ->orderByRaw('COALESCE(result_confirmed_at, updated_at)')
            ->orderBy('id')
            ->get();

        return $this->eloRatings->calculate($matches)[$tournament->game] ?? [];
    }

    public function autoSeed(Tournament $tournament): array
    {
        abort_unless($tournament->status === 'registration_open', 409, 'Entrants can only be seeded while registration is open.');
        $ratings = $this->ratingsFor($tournament);

        return DB::transaction(function () use ($tournament, $ratings): array {
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
            abort_unless($locked->status === 'registration_open', 409, 'Entrants can only be seeded while registration is open.');
            $entries = $locked->entries()->with(['team:id,name', 'user:id,name'])->get();
            $rows = $entries->map(function (TournamentEntry $entry) use ($ratings): array {
                $key = $this->eloRatings->keyForEntry($entry);
                $rating = $ratings[$key] ?? ['rating' => 1000.0, 'matches' => 0, 'wins' => 0, 'losses' => 0];
                return ['entry' => $entry, 'rating' => $rating];
            })->all();

            usort($rows, static fn (array $a, array $b): int =>
                ($b['rating']['rating'] <=> $a['rating']['rating'])
                ?: ($b['rating']['wins'] <=> $a['rating']['wins'])
                ?: (($a['entry']->seed ?? PHP_INT_MAX) <=> ($b['entry']->seed ?? PHP_INT_MAX))
                ?: ($a['entry']->id <=> $b['entry']->id)
            );

            foreach ($rows as $index => $row) {
                $row['entry']->update(['seed' => $index + 1]);
                $rows[$index]['seed'] = $index + 1;
            }

            return array_map(fn (array $row): array => [
                ...$row['entry']->fresh()->toArray(),
                'team' => $row['entry']->team,
                'user' => $row['entry']->user,
                'elo_rating' => (int) round($row['rating']['rating']),
                'elo_matches' => $row['rating']['matches'],
                'elo_wins' => $row['rating']['wins'],
                'elo_losses' => $row['rating']['losses'],
            ], $rows);
        });
    }
}
