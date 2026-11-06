<?php

namespace App\Services;

use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BracketBuilder
{
    public function __construct(private readonly RoundPairingService $pairings) {}

    public function publish(Tournament $tournament): void
    {
        if (! in_array($tournament->format, ['single_elimination', 'double_elimination', 'round_robin'], true)) {
            throw ValidationException::withMessages(['format' => 'This tournament format is not available yet.']);
        }
        $entries = $tournament->entries()->where('status', 'registered')->whereNotNull('checked_in_at')->get();
        if ($entries->count() < 2) {
            throw ValidationException::withMessages(['entries' => 'At least two checked-in entrants are required to publish.']);
        }

        DB::transaction(function () use ($tournament, $entries): void {
            $tournament->matches()->delete();
            if ($tournament->format === 'round_robin') {
                $this->buildRoundRobin($tournament, $entries);
            } elseif ($tournament->format === 'double_elimination') {
                $this->buildDoubleElimination($tournament, $entries);
            } else {
                $this->buildSingleElimination($tournament, $entries);
            }
            $tournament->update(['status' => 'live']);
        });
    }

    public function advanceSingleElimination(TournamentMatch $match): void
    {
        $tournament = $match->tournament;
        if ($tournament->format !== 'single_elimination') {
            return;
        }
        $roundMatches = $tournament->matches()->where('round', $match->round)->orderBy('match_number')->get();
        if ($roundMatches->contains(fn (TournamentMatch $row) => ! in_array($row->status, ['completed', 'bye'], true))) {
            return;
        }
        if ($tournament->matches()->where('round', $match->round + 1)->exists()) {
            return;
        }

        $winners = $roundMatches->pluck('winner_entrant_id')->map(fn ($id) => $id ? (int) $id : null)->all();
        $activeWinners = array_values(array_filter($winners));
        if (count($activeWinners) <= 1) {
            $tournament->update(['status' => 'completed']);
            return;
        }

        foreach ($this->pairings->pair($tournament, $activeWinners) as $index => $pair) {
            [$a, $b] = $pair;
            $this->createMatch($tournament, $match->round + 1, $index + 1, $a, $b, $a === null || $b === null);
        }
        $nextRound = $tournament->matches()->where('round', $match->round + 1)->get();
        $nextRound->each(function (TournamentMatch $row): void {
            if ($row->status === 'bye') {
                $this->advanceSingleElimination($row);
            }
        });
    }

    public function advanceDoubleElimination(TournamentMatch $match): void
    {
        $tournament = $match->tournament;
        if ($tournament->format !== 'double_elimination') return;
        $roundMatches = $tournament->matches()->where('round', $match->round)->get();
        if ($roundMatches->contains(fn (TournamentMatch $row) => ! in_array($row->status, ['completed', 'bye'], true))) return;
        if ($tournament->matches()->where('round', '>', $match->round)->exists()) return;

        $losses = $tournament->entries()->where('status', 'registered')->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => 0])->all();
        foreach ($tournament->matches()->where('status', 'completed')->get() as $played) {
            if (! $played->entrant_a_id || ! $played->entrant_b_id || ! $played->winner_entrant_id) continue;
            $loser = (int) $played->winner_entrant_id === (int) $played->entrant_a_id ? (int) $played->entrant_b_id : (int) $played->entrant_a_id;
            if (array_key_exists($loser, $losses)) $losses[$loser]++;
        }
        $alive = array_keys(array_filter($losses, fn (int $count) => $count < 2));
        if (count($alive) <= 1) {
            $tournament->update(['status' => 'completed']);
            return;
        }

        $zeroLoss = array_values(array_filter($alive, fn (int $id) => $losses[$id] === 0));
        $oneLoss = array_values(array_filter($alive, fn (int $id) => $losses[$id] === 1));
        $nextRound = (int) $match->round + 1;
        $entries = $tournament->entries()->whereIn('id', $alive)->get()->keyBy('id');
        $sortBySeed = fn (int $a, int $b): int => (($entries[$a]->seed ?? PHP_INT_MAX) <=> ($entries[$b]->seed ?? PHP_INT_MAX)) ?: ($a <=> $b);
        usort($zeroLoss, $sortBySeed);
        usort($oneLoss, $sortBySeed);

        if (count($alive) === 2 && count($zeroLoss) === 1 && count($oneLoss) === 1) {
            $this->createMatch($tournament, $nextRound, 1, $zeroLoss[0], $oneLoss[0], false, 'grand_final');
            return;
        }
        if (count($alive) === 2 && count($oneLoss) === 2) {
            $this->createMatch($tournament, $nextRound, 1, $oneLoss[0], $oneLoss[1], false, 'grand_final');
            return;
        }

        $number = 1;
        foreach ([['winners', $zeroLoss], ['losers', $oneLoss]] as [$side, $entrants]) {
            foreach ($this->pairings->pair($tournament, $entrants) as $pair) {
                [$a, $b] = $pair;
                $this->createMatch($tournament, $nextRound, $number++, $a, $b, $b === null, $side);
            }
        }

        foreach ($tournament->matches()->where('round', $nextRound)->where('status', 'bye')->get() as $bye) {
            $this->advanceDoubleElimination($bye);
        }
    }

    private function buildRoundRobin(Tournament $tournament, Collection $entries): void
    {
        $rotation = $this->orderedEntries($entries)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        if (count($rotation) % 2 !== 0) $rotation[] = null;
        $playerCount = count($rotation);
        for ($round = 1; $round < $playerCount; $round++) {
            $number = 1;
            for ($pair = 0; $pair < intdiv($playerCount, 2); $pair++) {
                $a = $rotation[$pair];
                $b = $rotation[$playerCount - 1 - $pair];
                if ($a !== null && $b !== null) $this->createMatch($tournament, $round, $number++, $a, $b, false);
            }
            $fixed = array_shift($rotation);
            $last = array_pop($rotation);
            array_unshift($rotation, $fixed);
            array_splice($rotation, 1, 0, [$last]);
        }
    }

    private function buildSingleElimination(Tournament $tournament, Collection $entries): void
    {
        $ordered = $this->orderedEntries($entries)->values();
        $size = 1;
        while ($size < $ordered->count()) { $size *= 2; }
        $seedOrder = [1, 2];
        while (count($seedOrder) < $size) {
            $half = count($seedOrder);
            $expanded = [];
            foreach ($seedOrder as $seed) { $expanded[] = $seed; $expanded[] = $half * 2 + 1 - $seed; }
            $seedOrder = $expanded;
        }

        $slots = array_fill(0, $size, null);
        foreach ($ordered as $index => $entry) {
            $slots[$seedOrder[$index] - 1] = (int) $entry->id;
        }
        for ($i = 0; $i < $size; $i += 2) {
            $this->createMatch($tournament, 1, intdiv($i, 2) + 1, $slots[$i], $slots[$i + 1], $slots[$i] === null || $slots[$i + 1] === null);
        }

        $initial = $tournament->matches()->where('round', 1)->orderBy('match_number')->get();
        foreach ($initial as $row) {
            if ($row->status === 'bye') { $this->advanceSingleElimination($row); }
        }
    }

    private function buildDoubleElimination(Tournament $tournament, Collection $entries): void
    {
        $ordered = $this->orderedEntries($entries)->values();
        $size = 1;
        while ($size < $ordered->count()) $size *= 2;
        $seedOrder = [1, 2];
        while (count($seedOrder) < $size) {
            $half = count($seedOrder);
            $expanded = [];
            foreach ($seedOrder as $seed) { $expanded[] = $seed; $expanded[] = $half * 2 + 1 - $seed; }
            $seedOrder = $expanded;
        }
        $slots = array_fill(0, $size, null);
        foreach ($ordered as $index => $entry) $slots[$seedOrder[$index] - 1] = (int) $entry->id;
        for ($i = 0; $i < $size; $i += 2) {
            $this->createMatch($tournament, 1, intdiv($i, 2) + 1, $slots[$i], $slots[$i + 1], $slots[$i] === null || $slots[$i + 1] === null, 'winners');
        }
        foreach ($tournament->matches()->where('round', 1)->where('status', 'bye')->get() as $bye) {
            $this->advanceDoubleElimination($bye);
        }
    }

    private function createMatch(Tournament $tournament, int $round, int $number, ?int $entrantA, ?int $entrantB, bool $bye, string $side = 'winners'): TournamentMatch
    {
        $entryA = $entrantA ? TournamentEntry::find($entrantA) : null;
        $entryB = $entrantB ? TournamentEntry::find($entrantB) : null;
        $winner = $bye ? ($entrantA ?? $entrantB) : null;
        return $tournament->matches()->create([
            'round' => $round,
            'bracket_side' => $side,
            'match_number' => $number,
            'entrant_a_id' => $entrantA,
            'entrant_b_id' => $entrantB,
            'team_a_id' => $entryA?->team_id,
            'team_b_id' => $entryB?->team_id,
            'best_of' => $tournament->best_of,
            'status' => $bye ? 'bye' : 'scheduled',
            'winner_entrant_id' => $winner,
        ]);
    }

    private function orderedEntries(Collection $entries): Collection
    {
        return $entries->sortBy(fn (TournamentEntry $entry) => [$entry->seed ?? PHP_INT_MAX, $entry->id])->values();
    }
}
