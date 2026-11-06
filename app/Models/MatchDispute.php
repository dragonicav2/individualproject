<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchDispute extends Model
{
    protected $fillable = ['match_id', 'raised_by', 'reason', 'evidence_path', 'evidence_mime', 'evidence_name', 'outcome', 'resolved_by', 'resolution', 'resolved_at'];
    protected $hidden = ['evidence_path'];
    protected function casts(): array { return ['resolved_at' => 'immutable_datetime']; }
    public function match(): BelongsTo { return $this->belongsTo(TournamentMatch::class, 'match_id'); }
    public function raiser(): BelongsTo { return $this->belongsTo(User::class, 'raised_by'); }
}
