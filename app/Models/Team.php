<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    protected $fillable = ['name', 'owner_id', 'game', 'is_private'];
    protected function casts(): array { return ['is_private' => 'boolean']; }
    public function members(): HasMany { return $this->hasMany(TeamMember::class); }
    public function invites(): HasMany { return $this->hasMany(TeamInvite::class); }
}
