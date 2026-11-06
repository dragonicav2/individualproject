<?php

namespace App\Http\Controllers;

use App\Models\GameServer;
use App\Models\TournamentMatch;
use App\Jobs\ProvisionCs2MatchServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use App\Services\Cs2Provisioner;

class Cs2ServerController extends Controller
{
    public function provision(Request $request, TournamentMatch $match): JsonResponse
    {
        abort_unless($match->tournament->game === 'cs2', 422, 'This match is not a Counter-Strike 2 match.');
        abort_unless($match->tournament->organizer_id === $request->user()->id, 403);
        abort_unless(in_array($match->status, ['scheduled', 'result_pending'], true), 409, 'Servers can only be provisioned for active matches.');
        $data = $request->validate([
            'region' => ['required', Rule::in(['private-lan', 'singapore', 'hong-kong', 'tokyo', 'sydney', 'frankfurt', 'london', 'us-east', 'us-west'])],
            'map' => ['required', Rule::in(['de_ancient', 'de_anubis', 'de_dust2', 'de_inferno', 'de_mirage', 'de_nuke', 'de_overpass', 'de_vertigo'])],
        ]);
        if ($match->gameServer && in_array($match->gameServer->status, ['provisioning', 'ready'], true)) {
            return response()->json(['data' => $match->gameServer], 202);
        }
        abort_unless(config('services.cs2.provisioner_url') && config('services.cs2.provisioner_token'), 503, 'CS2 server agent is not configured. Set CS2_PROVISIONER_URL and CS2_PROVISIONER_TOKEN first.');

        $server = GameServer::updateOrCreate(['match_id' => $match->id], ['provider' => 'cs2-provisioner', 'region' => $data['region'], 'map' => $data['map'], 'status' => 'provisioning']);
        ProvisionCs2MatchServer::dispatch($server->id);
        return response()->json(['data' => $server], 202);
    }

    public function show(Request $request, TournamentMatch $match): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $canView = (int) $match->tournament->organizer_id === $userId
            || ($match->entrantA?->isOwnedBy($userId) ?? false)
            || ($match->entrantB?->isOwnedBy($userId) ?? false);
        abort_unless($canView, 403);
        $server = $match->gameServer;
        abort_unless($server, 404);
        if ($server->provider === 'cs2-provisioner' && $server->provider_ref && in_array($server->status, ['provisioning', 'ready'], true)) {
            try {
                $state = app(Cs2Provisioner::class)->status((string) $server->provider_ref);
                $server->update(['status' => $state['status'] ?? $server->status, 'last_health_check_at' => now()]);
            } catch (\Throwable $error) {
                report($error);
            }
            $server->refresh();
        }
        $payload = $server->makeVisible(['host', 'port'])->toArray();
        $payload['demo_url'] = $server->demo_path ? route('cs2.demo.download', ['match' => $match->id], false) : null;
        return response()->json(['data' => $payload]);
    }

    public function stop(Request $request, TournamentMatch $match, Cs2Provisioner $provisioner): JsonResponse
    {
        abort_unless((int) $match->tournament->organizer_id === (int) $request->user()->id, 403);
        $server = $match->gameServer;
        abort_unless($server && $server->status === 'ready', 409, 'There is no running CS2 server for this match.');
        $result = $provisioner->stop((string) $server->provider_ref);
        $server->update(['status' => 'stopped', 'stopped_at' => now(), 'last_health_check_at' => now()]);
        return response()->json(['data' => ['server' => $server->fresh()->makeVisible(['host', 'port']), 'demo_received' => (bool) ($result['demo_received'] ?? false)]]);
    }

    public function receiveDemo(Request $request, TournamentMatch $match): JsonResponse
    {
        $expected = (string) config('services.cs2.provisioner_token');
        $provided = (string) $request->bearerToken();
        abort_unless($expected !== '' && hash_equals($expected, $provided), 401);
        $request->validate(['demo' => ['required', 'file', 'max:102400', 'mimetypes:application/octet-stream,application/x-octet-stream,application/vnd.valve.source.demo']]);
        $server = $match->gameServer;
        abort_unless($server, 404);
        $file = $request->file('demo');
        $path = $file->storeAs('cs2-demos', 'match-'.$match->id.'.dem', 'local');
        $server->update(['demo_path' => $path, 'demo_size' => $file->getSize(), 'last_health_check_at' => now()]);
        return response()->json(['data' => ['stored' => true, 'size' => $file->getSize()]], 201);
    }

    public function downloadDemo(Request $request, TournamentMatch $match)
    {
        $userId = (int) $request->user()->id;
        $canView = (int) $match->tournament->organizer_id === $userId
            || ($match->entrantA?->isOwnedBy($userId) ?? false)
            || ($match->entrantB?->isOwnedBy($userId) ?? false);
        abort_unless($canView, 403);
        abort_unless($match->gameServer?->demo_path && Storage::disk('local')->exists($match->gameServer->demo_path), 404);
        return Storage::disk('local')->download($match->gameServer->demo_path, 'match-'.$match->id.'.dem');
    }
}
