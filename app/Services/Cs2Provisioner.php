<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class Cs2Provisioner
{
    public function provision(array $match): array
    {
        $endpoint = config('services.cs2.provisioner_url');
        $token = config('services.cs2.provisioner_token');
        if (! $endpoint || ! $token) {
            throw new RuntimeException('CS2 server provisioning is not configured. Set CS2_PROVISIONER_URL and CS2_PROVISIONER_TOKEN.');
        }

        $response = Http::baseUrl(rtrim($endpoint, '/'))
            ->withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->post('/v1/servers', [
                'external_match_id' => (string) $match['id'],
                'region' => $match['region'],
                'map' => $match['map'],
                'mode' => 'competitive',
                'max_players' => 12,
                'auto_record_demo' => true,
                'callback_url' => rtrim((string) config('services.cs2.callback_url'), '/').'/api/v1/cs2/matches/'.rawurlencode((string) $match['id']).'/demo',
                'match_config' => $match['config'] ?? [],
            ])->throw()->json();

        foreach (['server_id', 'host', 'port', 'status'] as $key) {
            if (! isset($response[$key])) {
                throw new RuntimeException("CS2 provisioner response is missing {$key}.");
            }
        }
        return $response;
    }

    public function stop(string $serverId): array
    {
        $endpoint = config('services.cs2.provisioner_url');
        $token = config('services.cs2.provisioner_token');
        if (! $endpoint || ! $token) {
            throw new RuntimeException('CS2 server agent is not configured.');
        }

        return Http::baseUrl(rtrim($endpoint, '/'))
            ->withToken($token)
            ->acceptJson()
            ->timeout(90)
            ->post('/v1/servers/'.rawurlencode($serverId).'/stop')
            ->throw()->json();
    }

    public function status(string $serverId): array
    {
        $endpoint = config('services.cs2.provisioner_url');
        $token = config('services.cs2.provisioner_token');
        if (! $endpoint || ! $token) {
            throw new RuntimeException('CS2 server agent is not configured.');
        }

        return Http::baseUrl(rtrim($endpoint, '/'))
            ->withToken($token)
            ->acceptJson()
            ->timeout(5)
            ->get('/v1/servers/'.rawurlencode($serverId))
            ->throw()->json();
    }
}
