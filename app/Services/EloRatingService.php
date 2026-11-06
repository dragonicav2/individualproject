<?php

namespace App\Services;

use App\Models\TournamentEntry;
use App\Models\TournamentMatch;
use Illuminate\Support\Collection;

class EloRatingService
{
    /** @return array<string, array<string, array{key:string,name:string,kind:string,rating:float,matches:int,wins:int,losses:int}>> */
    public function calculate(Collection $matches): array
    {
        $ratings = [];
        foreach ($matches as $match) {
            $game = $match->tournament?->game;
            $a = $match->entrantA;
            $b = $match->entrantB;
            if (! $game || ! $a || ! $b || ! $match->winner_entrant_id) continue;
            $keyA = $this->keyForEntry($a);
            $keyB = $this->keyForEntry($b);
            $ratings[$game][$keyA] ??= ['key' => $keyA, 'name' => $this->nameForEntry($a), 'kind' => $a->team_id ? 'Team' : 'Player', 'rating' => 1000.0, 'matches' => 0, 'wins' => 0, 'losses' => 0];
            $ratings[$game][$keyB] ??= ['key' => $keyB, 'name' => $this->nameForEntry($b), 'kind' => $b->team_id ? 'Team' : 'Player', 'rating' => 1000.0, 'matches' => 0, 'wins' => 0, 'losses' => 0];

            $ratingA = $ratings[$game][$keyA]['rating'];
            $ratingB = $ratings[$game][$keyB]['rating'];
            $expectedA = 1 / (1 + (10 ** (($ratingB - $ratingA) / 400)));
            $actualA = (int) $match->winner_entrant_id === (int) $a->id ? 1 : 0;
            $kFactor = (int) $match->tournament->team_size === 1 ? 40 : 32;
            $ratings[$game][$keyA]['rating'] = $ratingA + $kFactor * ($actualA - $expectedA);
            $ratings[$game][$keyB]['rating'] = $ratingB + $kFactor * ((1 - $actualA) - (1 - $expectedA));
            foreach ([[$keyA, $actualA], [$keyB, 1 - $actualA]] as [$key, $won]) {
                $ratings[$game][$key]['matches']++;
                $ratings[$game][$key]['wins'] += $won;
                $ratings[$game][$key]['losses'] += 1 - $won;
            }
        }
        return $ratings;
    }

    public function keyForEntry(TournamentEntry $entry): string
    {
        return $entry->team_id ? 'team:'.$entry->team_id : 'player:'.$entry->user_id;
    }

    public function nameForEntry(TournamentEntry $entry): string
    {
        return $entry->team?->name ?? $entry->user?->name ?? 'Unknown entrant';
    }
}
