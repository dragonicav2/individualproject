<?php

namespace App\Http\Controllers;

use App\Models\TournamentMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MatchOperationsController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $rows = TournamentMatch::query()
            ->whereHas('tournament', fn ($query) => $query->whereIn('status', ['live', 'completed'])->where(function ($query) use ($userId): void {
                $query->where('organizer_id', $userId)
                    ->orWhereHas('entries', function ($entries) use ($userId): void {
                        $entries->where(function ($entries) use ($userId): void {
                            $entries->where('user_id', $userId)->orWhereHas('team.members', fn ($members) => $members->where('user_id', $userId));
                        })->whereColumn('tournament_entries.tournament_id', 'tournaments.id');
                    });
            }))
            ->whereIn('status', ['scheduled', 'result_pending', 'disputed'])
            ->with(['tournament:id,name,game,organizer_id', 'entrantA.team:id,name', 'entrantA.user:id,name', 'entrantB.team:id,name', 'entrantB.user:id,name', 'gameServer'])
            ->orderByRaw('scheduled_at IS NULL')
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get()
            ->map(function (TournamentMatch $match) use ($userId): array {
                $side = $match->entrantA?->isOwnedBy($userId) ? 'a' : ($match->entrantB?->isOwnedBy($userId) ? 'b' : null);
                return [...$match->toArray(), 'is_organizer' => (int) $match->tournament->organizer_id === $userId,
                    'my_side' => $side, 'my_side_ready' => $side === 'a' ? (bool) $match->entrant_a_ready_at : ($side === 'b' ? (bool) $match->entrant_b_ready_at : false)];
            });

        return response()->json(['data' => $rows]);
    }

    public function schedule(Request $request, TournamentMatch $match): JsonResponse
    {
        abort_unless((int) $match->tournament->organizer_id === (int) $request->user()->id, 403);
        abort_unless($match->status === 'scheduled', 409, 'Only an upcoming match can be scheduled.');
        $data = $request->validate(['scheduled_at' => ['nullable', 'date', 'after:now']]);
        $match->update(['scheduled_at' => $data['scheduled_at'] ?? null, 'entrant_a_ready_at' => null, 'entrant_b_ready_at' => null]);
        return response()->json(['data' => $match->fresh()]);
    }

    public function ready(Request $request, TournamentMatch $match): JsonResponse
    {
        abort_unless($match->status === 'scheduled', 409, 'This match is no longer awaiting players.');
        $data = $request->validate(['ready' => ['required', 'boolean']]);
        $userId = (int) $request->user()->id;
        if ($match->entrantA?->isOwnedBy($userId)) {
            $entry = $match->entrantA;
            $column = 'entrant_a_ready_at';
        } elseif ($match->entrantB?->isOwnedBy($userId)) {
            $entry = $match->entrantB;
            $column = 'entrant_b_ready_at';
        }
        else abort(403, 'You must belong to one of the match entrants to mark ready.');
        abort_unless($entry->checked_in_at, 409, 'Check in before marking your side ready.');

        $match->update([$column => $data['ready'] ? now() : null]);
        return response()->json(['data' => $match->fresh()]);
    }
}
