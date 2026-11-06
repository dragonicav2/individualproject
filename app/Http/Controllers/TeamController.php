<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\TournamentEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $teams = Team::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', $request->user()->id))
            ->withCount('members')
            ->with(['members.user:id,name'])
            ->orderBy('name')
            ->get()
            ->map(function (Team $team) use ($request): array {
                $role = $team->owner_id === $request->user()->id
                    ? 'owner'
                    : $team->members()->where('user_id', $request->user()->id)->value('role');
                return [...$team->toArray(), 'my_role' => $role];
            });

        return response()->json(['data' => $teams]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'game' => ['required', Rule::in(['cs2', 'valorant', 'sf6', 'mlbb'])], 'is_private' => ['sometimes', 'boolean']]);
        $team = Team::create($data + ['owner_id' => $request->user()->id]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $request->user()->id, 'role' => 'owner', 'joined_at' => now()]);
        return response()->json(['data' => $team], 201);
    }

    public function leave(Request $request, Team $team): JsonResponse
    {
        abort_if($team->owner_id === $request->user()->id, 422, 'Transfer team ownership before leaving.');
        abort_if(TournamentEntry::query()->where('team_id', $team->id)->whereHas('tournament', fn ($query) => $query->whereIn('status', ['registration_open', 'live']))->exists(), 409, 'You cannot leave while this team is registered in an active tournament.');
        $removed = TeamMember::where('team_id', $team->id)->where('user_id', $request->user()->id)->delete();
        abort_unless($removed, 404);
        return response()->json(['message' => 'You left the team.']);
    }
}
