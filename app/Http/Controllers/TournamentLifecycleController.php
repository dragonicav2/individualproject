<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\BracketBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentLifecycleController extends Controller
{
    public function openRegistration(Request $request, Tournament $tournament): JsonResponse
    {
        abort_unless($tournament->organizer_id === $request->user()->id, 403);
        abort_unless($tournament->status === 'draft', 409, 'Only a draft tournament can open registration.');
        $tournament->update(['status' => 'registration_open']);
        return response()->json(['data' => $tournament->fresh()]);
    }

    public function publish(Request $request, Tournament $tournament, BracketBuilder $brackets): JsonResponse
    {
        abort_unless($tournament->organizer_id === $request->user()->id, 403);
        abort_unless($tournament->status === 'registration_open', 409, 'Open registration before publishing the bracket.');
        $brackets->publish($tournament);
        return response()->json(['data' => $tournament->fresh()->load(['matches.entrantA.team', 'matches.entrantA.user', 'matches.entrantB.team', 'matches.entrantB.user'])], 201);
    }

    public function bracket(Request $request, Tournament $tournament): JsonResponse
    {
        $matches = $tournament->matches()->with(['gameServer:id,match_id,room_code', 'entrantA.team:id,name', 'entrantA.team.members.user:id,name', 'entrantA.user:id,name', 'entrantB.team:id,name', 'entrantB.team.members.user:id,name', 'entrantB.user:id,name', 'winnerEntrant.team:id,name', 'winnerEntrant.user:id,name', 'disputes.raiser:id,name', 'resultCorrections.corrector:id,name'])->orderBy('round')->orderBy('match_number')->get();
        $user = $request->user();
        $matches->each(function ($match) use ($user, $tournament): void {
            $isOrganizer = $user && (int) $tournament->organizer_id === (int) $user->id;
            $isParticipant = $user && (($match->entrantA?->isOwnedBy((int) $user->id) ?? false)
                || ($match->entrantB?->isOwnedBy((int) $user->id) ?? false));
            $canViewPrivateMatchData = $isOrganizer || $isParticipant;
            $match->setAttribute('mlbb_room_code', $tournament->game === 'mlbb' && $canViewPrivateMatchData ? $match->gameServer?->room_code : null);
            if (! $canViewPrivateMatchData) {
                $match->setRelation('gameServer', null);
                $match->setRelation('disputes', collect());
                $match->setRelation('resultCorrections', collect());
            }
        });

        return response()->json(['data' => $matches]);
    }

    public function standings(Tournament $tournament): JsonResponse
    {
        $entries = $tournament->entries()->with(['team:id,name', 'user:id,name'])->get();
        $table = $entries->mapWithKeys(fn ($entry) => [$entry->id => [
            'entrant_id' => $entry->id,
            'name' => $entry->team?->name ?? $entry->user?->name ?? 'Entrant',
            'seed' => $entry->seed,
            'matches_played' => 0,
            'wins' => 0,
            'losses' => 0,
            'points' => 0,
            'rounds_for' => 0,
            'rounds_against' => 0,
        ]]);

        foreach ($tournament->matches()->where('status', 'completed')->get() as $match) {
            if (! isset($table[$match->entrant_a_id], $table[$match->entrant_b_id])) continue;
            $a = $table[$match->entrant_a_id];
            $b = $table[$match->entrant_b_id];
            $a['matches_played']++; $b['matches_played']++;
            $a['rounds_for'] += (int) $match->score_a; $a['rounds_against'] += (int) $match->score_b;
            $b['rounds_for'] += (int) $match->score_b; $b['rounds_against'] += (int) $match->score_a;
            if ($match->winner_entrant_id === $match->entrant_a_id) { $a['wins']++; $a['points'] += 3; $b['losses']++; }
            else { $b['wins']++; $b['points'] += 3; $a['losses']++; }
            $table[$match->entrant_a_id] = $a; $table[$match->entrant_b_id] = $b;
        }

        $rows = collect($table)->map(function (array $row): array {
            $row['round_difference'] = $row['rounds_for'] - $row['rounds_against'];
            return $row;
        })->sort(function (array $a, array $b): int {
            return ($b['points'] <=> $a['points']) ?: ($b['round_difference'] <=> $a['round_difference']) ?: (($a['seed'] ?? PHP_INT_MAX) <=> ($b['seed'] ?? PHP_INT_MAX)) ?: ($a['entrant_id'] <=> $b['entrant_id']);
        })->values();

        return response()->json(['data' => $rows]);
    }
}
