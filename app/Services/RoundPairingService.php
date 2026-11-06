<?php

namespace App\Services;

use App\Models\Tournament;
use App\Models\TournamentEntry;

class RoundPairingService
{
    public function __construct(private readonly TournamentSeedingService $seeding) {}

    /** @param array<int, int> $entrantIds @return array<int, array{0:int,1:?int}> */
    public function pair(Tournament $tournament, array $entrantIds): array
    {
        $entrantIds = array_values(array_unique(array_map('intval', $entrantIds)));
        if (count($entrantIds) < 2) return array_map(fn (int $id) => [$id, null], $entrantIds);

        $strategy = $tournament->settings['seeding_strategy'] ?? 'fixed';
        $entries = TournamentEntry::query()->whereIn('id', $entrantIds)->get()->keyBy('id');
        $ratings = in_array($strategy, ['high_vs_low', 'similar_elo'], true) ? $this->seeding->ratingsFor($tournament) : [];
        $rows = array_map(function (int $id) use ($entries, $ratings): array {
            $entry = $entries[$id];
            $key = $this->seeding->keyForEntry($entry);
            return ['id' => $id, 'seed' => $entry->seed ?? PHP_INT_MAX, 'rating' => $ratings[$key]['rating'] ?? 1000.0];
        }, $entrantIds);

        if ($strategy === 'random_each_round') {
            shuffle($rows);
        } elseif ($strategy === 'high_vs_low') {
            usort($rows, static fn (array $a, array $b): int => ($b['rating'] <=> $a['rating']) ?: ($a['seed'] <=> $b['seed']) ?: ($a['id'] <=> $b['id']));
            $pairs = [];
            while (count($rows) > 1) $pairs[] = [array_shift($rows)['id'], array_pop($rows)['id']];
            if ($rows) $pairs[] = [$rows[0]['id'], null];
            return $pairs;
        } elseif ($strategy === 'similar_elo') {
            usort($rows, static fn (array $a, array $b): int => ($a['rating'] <=> $b['rating']) ?: ($a['seed'] <=> $b['seed']) ?: ($a['id'] <=> $b['id']));
            $bye = count($rows) % 2 ? array_pop($rows) : null;
            $pairs = [];
            foreach (array_chunk($rows, 2) as $pair) $pairs[] = [$pair[0]['id'], $pair[1]['id']];
            if ($bye) $pairs[] = [$bye['id'], null];
            return $pairs;
        }

        $ids = array_column($rows, 'id');
        $pairs = [];
        foreach (array_chunk($ids, 2) as $pair) $pairs[] = [$pair[0], $pair[1] ?? null];
        return $pairs;
    }
}
