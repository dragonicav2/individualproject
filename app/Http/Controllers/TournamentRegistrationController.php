<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TournamentRegistrationController extends Controller
{
    public function mine(Request $request, Tournament $tournament): JsonResponse
    {
        $entry = $tournament->entries()
            ->where(function ($query) use ($request): void {
                $query->where('user_id', $request->user()->id)
                    ->orWhereHas('team.members', fn ($memberQuery) => $memberQuery->where('user_id', $request->user()->id));
            })->with(['team:id,name', 'user:id,name'])->first();
        return response()->json(['data' => $entry]);
    }

    public function store(Request $request, Tournament $tournament): JsonResponse
    {
        abort_unless($tournament->status === 'registration_open', 409, 'Registration is not open.');
        abort_if((int) $tournament->organizer_id === (int) $request->user()->id && ! ($tournament->settings['allow_organizer_entry'] ?? false), 403, 'The organizer has disabled their own tournament entry.');
        if ($tournament->team_size === 1) {
            $existing = $tournament->entries()->where('user_id', $request->user()->id)->first();
            if ($existing) return response()->json(['data' => $existing->load(['user:id,name', 'team:id,name'])]);
            abort_if($tournament->capacity !== null && $tournament->entries()->count() >= $tournament->capacity, 422, 'This tournament has reached its entrant capacity.');
            $entry = TournamentEntry::firstOrCreate(['tournament_id' => $tournament->id, 'user_id' => $request->user()->id], ['status' => 'registered']);
        } else {
            $data = $request->validate(['team_id' => ['required', 'integer', 'exists:teams,id']]);
            $team = Team::findOrFail($data['team_id']);
            abort_unless($team->game === $tournament->game, 422, 'The team is registered for a different game.');
            abort_unless($team->members()->where('user_id', $request->user()->id)->exists(), 403, 'You must be a member of this team to register it.');
            $existing = $tournament->entries()->where('team_id', $team->id)->first();
            if ($existing) return response()->json(['data' => $existing->load(['user:id,name', 'team:id,name'])]);
            abort_if($tournament->capacity !== null && $tournament->entries()->count() >= $tournament->capacity, 422, 'This tournament has reached its entrant capacity.');
            $members = TeamMember::where('team_id', $team->id)->count();
            abort_unless($members >= $tournament->team_size, 422, "This tournament requires {$tournament->team_size} roster members.");
            $entry = TournamentEntry::firstOrCreate(['tournament_id' => $tournament->id, 'team_id' => $team->id], ['status' => 'registered']);
        }
        return response()->json(['data' => $entry->load(['user:id,name', 'team:id,name'])], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function checkIn(Request $request, TournamentEntry $entry): JsonResponse
    {
        $tournament = $entry->tournament;
        abort_unless($tournament->status === 'registration_open', 409, 'Check-in is closed.');
        abort_unless($entry->isOwnedBy((int) $request->user()->id), 403);

        abort_if($tournament->starts_at === null, 409, 'This tournament has no scheduled start time yet.');
        $checkInOpensAt = $tournament->starts_at->subHour();
        abort_if(now()->lt($checkInOpensAt), 409, 'Check-in opens one hour before the tournament starts.');
        abort_if(now()->gt($tournament->starts_at), 409, 'Check-in is closed because the tournament start time has passed.');

        $entry->update(['checked_in_at' => now()]);
        return response()->json(['data' => $entry->fresh()]);
    }

    public function seed(Request $request, TournamentEntry $entry): JsonResponse
    {
        $tournament = $entry->tournament;
        abort_unless($tournament->organizer_id === $request->user()->id, 403);
        abort_unless($tournament->status === 'registration_open', 409, 'Seeds can only be changed while registration is open.');
        $data = $request->validate(['seed' => ['present', 'nullable', 'integer', 'min:1', 'max:512']]);
        if ($data['seed'] !== null) {
            abort_if($tournament->entries()->where('id', '<>', $entry->id)->where('seed', $data['seed'])->exists(), 422, 'That seed is already assigned to another entrant.');
        }
        $entry->update(['seed' => $data['seed']]);
        return response()->json(['data' => $entry->fresh()->load(['team:id,name', 'user:id,name'])]);
    }

    public function destroy(Request $request, TournamentEntry $entry): JsonResponse
    {
        abort_unless($entry->isOwnedBy((int) $request->user()->id), 403);
        abort_unless($entry->tournament->status === 'registration_open', 409, 'Registration is locked.');
        $entry->delete();
        return response()->json(['message' => 'Registration withdrawn.']);
    }
}
