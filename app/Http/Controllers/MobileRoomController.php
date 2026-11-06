<?php

namespace App\Http\Controllers;

use App\Models\TournamentMatch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileRoomController extends Controller
{
    public function updateRoomCode(Request $request, TournamentMatch $match): JsonResponse
    {
        abort_unless($match->tournament->game === 'mlbb', 422, 'This is not a Mobile Legends match.');
        abort_unless((int) $match->tournament->organizer_id === (int) $request->user()->id, 403, 'Only the tournament organizer can update the room code.');
        abort_unless(in_array($match->status, ['scheduled', 'result_pending', 'disputed'], true), 409, 'The room code can only be updated for an active match.');

        $data = $request->validate(['room_code' => ['required', 'string', 'max:80']]);
        $server = $match->gameServer()->firstOrNew();
        if (! $server->exists) {
            $server->provider = 'manual-room';
            $server->region = 'Malaysia';
            $server->map = 'Land of Dawn';
            $server->status = 'ready';
        }
        $server->room_code = trim($data['room_code']);
        $server->save();

        return response()->json(['data' => ['room_code' => $server->room_code]]);
    }

    public function show(Request $request, TournamentMatch $match): JsonResponse
    {
        abort_unless($match->tournament->game === 'mlbb', 422, 'This is not a Mobile Legends match.');
        abort_unless((int) $match->tournament->organizer_id === (int) $request->user()->id, 403, 'Only the tournament organizer can open the emulator station.');
        abort_unless(in_array($match->status, ['scheduled', 'result_pending', 'disputed'], true), 409, 'The emulator station is available only for active matches.');

        $baseUrl = rtrim(trim((string) config('services.guacamole.api_url')), '/');
        $clientUrl = rtrim(trim((string) config('services.guacamole.client_url')), '/');
        $clientId = trim((string) config('services.guacamole.client_id'));
        $username = (string) config('services.guacamole.username');
        $password = (string) config('services.guacamole.password');

        abort_unless($baseUrl !== '' && $clientUrl !== '' && $clientId !== '' && $username !== '' && $password !== '', 503, 'The MLBB emulator station is not configured. Set the Guacamole service account settings in .env.');

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(8)
                ->post($baseUrl.'/api/tokens', [
                    'username' => $username,
                    'password' => $password,
                ]);
        } catch (ConnectionException $exception) {
            report($exception);
            abort(503, 'The Guacamole service could not be reached.');
        }

        if (! $response->successful() || ! is_string($response->json('authToken'))) {
            abort(503, 'Guacamole could not create an emulator session. Check the service account credentials and permissions.');
        }

        $token = $response->json('authToken');
        $dataSource = (string) ($response->json('dataSource') ?: config('services.guacamole.data_source'));
        $url = $clientUrl.'/#/client/'.rawurlencode($clientId).'?'.http_build_query([
            'token' => $token,
            'GUAC_DATA_SOURCE' => $dataSource,
        ]);

        return response()->json(['data' => ['url' => $url]]);
    }
}
