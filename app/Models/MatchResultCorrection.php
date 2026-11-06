<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchResultCorrection extends Model
{
    protected $fillable = ['match_id', 'corrected_by', 'previous_score_a', 'previous_score_b', 'corrected_score_a', 'corrected_score_b', 'reason'];

    public function corrector(): BelongsTo { return $this->belongsTo(User::class, 'corrected_by'); }
}
