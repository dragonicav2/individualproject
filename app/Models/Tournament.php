<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tournament extends Model
{
    protected $fillable = ['organizer_id', 'name', 'game', 'team_size', 'format', 'best_of', 'capacity', 'status', 'starts_at', 'settings'];
    protected function casts(): array { return ['starts_at' => 'immutable_datetime', 'settings' => 'array']; }
    public function matches(): HasMany { return $this->hasMany(TournamentMatch::class); }
    public function entries(): HasMany { return $this->hasMany(TournamentEntry::class); }
}
