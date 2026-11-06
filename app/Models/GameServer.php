<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameServer extends Model
{
    protected $fillable = ['match_id', 'provider', 'provider_ref', 'region', 'map', 'host', 'port', 'room_code', 'status', 'connect_secret', 'last_health_check_at', 'stream_url', 'demo_path', 'demo_size', 'stopped_at'];
    protected $hidden = ['connect_secret', 'demo_path'];
    protected function casts(): array { return ['connect_secret' => 'encrypted', 'last_health_check_at' => 'immutable_datetime']; }
    public function match(): BelongsTo { return $this->belongsTo(TournamentMatch::class, 'match_id'); }
}
