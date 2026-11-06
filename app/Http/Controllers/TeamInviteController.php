<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\TeamMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TeamInviteController extends Controller
{
    public function store(Request $request, Team $team): JsonResponse
    {
        abort_unless($team->owner_id === $request->user()->id, 403);
        $data = $request->validate(['expires_in_hours' => ['sometimes', 'integer', 'min:1', 'max:720'], 'max_uses' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $plainCode = Str::random(48);
        $invite = $team->invites()->create([
            'code_hash' => Hash::make($plainCode),
            'created_by' => $request->user()->id,
            'expires_at' => now()->addHours($data['expires_in_hours'] ?? 72),
            'max_uses' => $data['max_uses'] ?? 1,
            'uses' => 0,
        ]);
        return response()->json(['id' => $invite->id, 'code' => $plainCode, 'expires_at' => $invite->expires_at, 'message' => 'Copy this code now. It cannot be retrieved later.'], 201);
    }

    public function join(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'min:32', 'max:128']]);
        $invite = TeamInvite::query()->whereNull('revoked_at')->where('expires_at', '>', now())->whereColumn('uses', '<', 'max_uses')->get()->first(fn (TeamInvite $row) => Hash::check($data['code'], $row->code_hash));
        abort_unless($invite, 422, 'This team invite is invalid, expired, or has already been used.');
        DB::transaction(function () use ($invite, $request): void {
            $locked = TeamInvite::query()->lockForUpdate()->findOrFail($invite->id);
            abort_if($locked->revoked_at || $locked->expires_at->isPast() || $locked->uses >= $locked->max_uses, 422);
            $member = TeamMember::firstOrCreate(['team_id' => $locked->team_id, 'user_id' => $request->user()->id], ['role' => 'player', 'joined_at' => now()]);
            if ($member->wasRecentlyCreated) {
                $locked->increment('uses');
            }
        });
        return response()->json(['team' => $invite->team()->first(['id', 'name', 'game'])]);
    }
}
