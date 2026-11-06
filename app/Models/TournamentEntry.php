<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentEntry extends Model
{
    protected $fillable = ['tournament_id', 'user_id', 'team_id', 'seed', 'checked_in_at', 'status'];
    protected function casts(): array { return ['checked_in_at' => 'immutable_datetime']; }
    public function tournament(): BelongsTo { return $this->belongsTo(Tournament::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function team(): BelongsTo { return $this->belongsTo(Team::class); }
    public function label(): string { return $this->team?->name ?? $this->user?->name ?? 'TBD'; }
    public function isOwnedBy(int $userId): bool { return (int) $this->user_id === $userId || ($this->team_id !== null && $this->team?->members()->where('user_id', $userId)->exists()); }
}
