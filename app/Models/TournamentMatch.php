<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TournamentMatch extends Model
{
    protected $table = 'tournament_matches';
    protected $fillable = ['tournament_id', 'round', 'bracket_side', 'match_number', 'status', 'best_of', 'team_a_id', 'team_b_id', 'entrant_a_id', 'entrant_b_id', 'winner_team_id', 'winner_entrant_id', 'score_a', 'score_b', 'result_reporter_id', 'result_confirmed_by', 'result_confirmed_at', 'game_stats', 'scheduled_at', 'entrant_a_ready_at', 'entrant_b_ready_at'];
    public function tournament(): BelongsTo { return $this->belongsTo(Tournament::class); }
    public function gameServer(): HasOne { return $this->hasOne(GameServer::class, 'match_id'); }
    public function entrantA(): BelongsTo { return $this->belongsTo(TournamentEntry::class, 'entrant_a_id'); }
    public function entrantB(): BelongsTo { return $this->belongsTo(TournamentEntry::class, 'entrant_b_id'); }
    public function winnerEntrant(): BelongsTo { return $this->belongsTo(TournamentEntry::class, 'winner_entrant_id'); }
    public function disputes(): HasMany { return $this->hasMany(MatchDispute::class, 'match_id'); }
    public function resultCorrections(): HasMany { return $this->hasMany(MatchResultCorrection::class, 'match_id'); }
    protected function casts(): array { return ['result_confirmed_at' => 'immutable_datetime', 'scheduled_at' => 'immutable_datetime', 'entrant_a_ready_at' => 'immutable_datetime', 'entrant_b_ready_at' => 'immutable_datetime', 'game_stats' => 'array']; }
}
