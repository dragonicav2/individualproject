<?php

namespace App\Jobs;

use App\Models\GameServer;
use App\Services\Cs2Provisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProvisionCs2MatchServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public array $backoff = [10, 30, 60];
    public function __construct(public int $serverId) {}

    public function handle(Cs2Provisioner $provisioner): void
    {
        $server = GameServer::query()->with('match')->findOrFail($this->serverId);
        $match = $server->match()->with(['tournament', 'entrantA.team', 'entrantA.user', 'entrantB.team', 'entrantB.user'])->firstOrFail();
        $result = $provisioner->provision([
            'id' => $server->match_id,
            'region' => $server->region,
            'map' => $server->map,
            'config' => [
                'best_of' => $match->best_of,
                'team1' => $match->entrantA?->team?->name ?? $match->entrantA?->user?->name ?? 'Team A',
                'team2' => $match->entrantB?->team?->name ?? $match->entrantB?->user?->name ?? 'Team B',
            ],
        ]);
        $server->update([
            'provider_ref' => $result['server_id'],
            'host' => $result['host'],
            'port' => $result['port'],
            'status' => $result['status'],
            'connect_secret' => $result['connect_password'] ?? null,
            'stream_url' => $result['stream_url'] ?? null,
            'last_health_check_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        GameServer::query()->whereKey($this->serverId)->update(['status' => 'failed']);
        report($exception);
    }
}
