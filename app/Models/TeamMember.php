<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMember extends Model
{
    protected $fillable = ['team_id', 'user_id', 'role', 'joined_at'];
    protected function casts(): array { return ['joined_at' => 'immutable_datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
