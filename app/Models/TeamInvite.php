<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamInvite extends Model
{
    protected $fillable = ['team_id', 'code_hash', 'created_by', 'expires_at', 'max_uses', 'uses', 'revoked_at'];
    protected function casts(): array { return ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime']; }
    public function team(): BelongsTo { return $this->belongsTo(Team::class); }
}
