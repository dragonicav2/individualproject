<?php

namespace App\Http\Controllers;

use App\Models\MatchDispute;
use App\Models\MatchResultCorrection;
use App\Models\TournamentMatch;
use App\Services\BracketBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class MatchResultController extends Controller
{
    public function report(Request $request, TournamentMatch $match): JsonResponse
    {
        abort_unless(in_array($match->status, ['scheduled', 'result_pending'], true), 409, 'This match cannot accept a result report.');
        $organizer = (int) $match->tournament->organizer_id === (int) $request->user()->id;
        $actorA = $match->entrantA?->isOwnedBy((int) $request->user()->id) ?? false;
        $actorB = $match->entrantB?->isOwnedBy((int) $request->user()->id) ?? false;
        abort_unless($organizer || $actorA || $actorB, 403);
        if ($match->status === 'result_pending') {
            abort_unless($organizer && (int) $match->result_reporter_id === (int) $request->user()->id, 409, 'A result is awaiting the other entrant or organizer to confirm or dispute it.');
        }
        $data = $this->validatedScore($request, $match);
        $match->update(['score_a' => $data['score_a'], 'score_b' => $data['score_b'], 'result_reporter_id' => $request->user()->id, 'status' => 'result_pending', 'result_confirmed_by' => null, 'result_confirmed_at' => null]);
        return response()->json(['data' => $match->fresh()->load(['entrantA.team:id,name', 'entrantA.user:id,name', 'entrantB.team:id,name', 'entrantB.user:id,name'])]);
    }

    public function confirm(Request $request, TournamentMatch $match, BracketBuilder $brackets): JsonResponse
    {
        abort_unless($match->status === 'result_pending', 409, 'No unconfirmed result is waiting for you.');
        $isOrganizer = (int) $match->tournament->organizer_id === (int) $request->user()->id;
        if ((int) $match->result_reporter_id === (int) $match->tournament->organizer_id) {
            abort_unless($isOrganizer, 403, 'Only the organizer can confirm an organizer-entered score.');
        } else {
            abort_unless((int) $match->result_reporter_id !== (int) $request->user()->id, 403, 'The person who reported the score cannot confirm it.');
        }
        if (! $isOrganizer) {
            $reporterEntry = $match->entrantA?->isOwnedBy((int) $match->result_reporter_id) ? $match->entrantA : $match->entrantB;
            abort_unless($reporterEntry?->isOwnedBy((int) $match->result_reporter_id), 409, 'The result reporter is not a match entrant.');
            $opponent = $reporterEntry->id === $match->entrant_a_id ? $match->entrantB : $match->entrantA;
            abort_unless($opponent?->isOwnedBy((int) $request->user()->id), 403);
        }

        DB::transaction(function () use ($request, $match, $brackets): void {
            $winner = $match->score_a > $match->score_b ? $match->entrant_a_id : $match->entrant_b_id;
            $match->update(['winner_entrant_id' => $winner, 'winner_team_id' => $match->score_a > $match->score_b ? $match->team_a_id : $match->team_b_id, 'status' => 'completed', 'result_confirmed_by' => $request->user()->id, 'result_confirmed_at' => now()]);
            $brackets->advanceSingleElimination($match);
            $brackets->advanceDoubleElimination($match);
            if ($match->tournament->format === 'round_robin' && ! $match->tournament->matches()->whereNotIn('status', ['completed'])->exists()) {
                $match->tournament->update(['status' => 'completed']);
            }
        });
        return response()->json(['data' => $match->fresh()->load('winnerEntrant.team:id,name', 'winnerEntrant.user:id,name')]);
    }

    public function dispute(Request $request, TournamentMatch $match): JsonResponse
    {
        abort_unless($match->status === 'result_pending', 409, 'Only a reported result can be disputed.');
        abort_unless((int) $match->result_reporter_id !== (int) $request->user()->id, 403);
        $reporterEntry = $match->entrantA?->isOwnedBy((int) $match->result_reporter_id) ? $match->entrantA : $match->entrantB;
        abort_unless($reporterEntry && $reporterEntry->isOwnedBy((int) $match->result_reporter_id), 409);
        $opponent = $reporterEntry->id === $match->entrant_a_id ? $match->entrantB : $match->entrantA;
        abort_unless($opponent?->isOwnedBy((int) $request->user()->id), 403);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:2000'],
            'evidence' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $file = $data['evidence'];
        $path = 'match-disputes/'.$match->id.'/'.Str::uuid().'.enc';
        abort_unless(Storage::disk('local')->put($path, Crypt::encryptString(file_get_contents($file->getRealPath()))), 500, 'The dispute evidence could not be stored.');

        try {
            $dispute = DB::transaction(function () use ($request, $match, $data, $file, $path): MatchDispute {
                $dispute = MatchDispute::create([
                    'match_id' => $match->id,
                    'raised_by' => $request->user()->id,
                    'reason' => $data['reason'],
                    'evidence_path' => $path,
                    'evidence_mime' => $file->getMimeType(),
                    'evidence_name' => Str::limit(basename($file->getClientOriginalName()), 120, ''),
                ]);
                $match->update(['status' => 'disputed']);
                return $dispute;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        return response()->json(['data' => $dispute], 201);
    }

    public function evidence(Request $request, TournamentMatch $match, MatchDispute $dispute): Response
    {
        abort_unless((int) $dispute->match_id === (int) $match->id && $dispute->evidence_path, 404);
        $match->loadMissing(['tournament', 'entrantA', 'entrantB']);
        $userId = (int) $request->user()->id;
        $authorized = (int) $match->tournament->organizer_id === $userId
            || ($match->entrantA?->isOwnedBy($userId) ?? false)
            || ($match->entrantB?->isOwnedBy($userId) ?? false);
        abort_unless($authorized, 403);
        abort_unless(Storage::disk('local')->exists($dispute->evidence_path), 404);

        try {
            $image = Crypt::decryptString(Storage::disk('local')->get($dispute->evidence_path));
        } catch (\Throwable) {
            abort(404, 'The dispute evidence could not be opened.');
        }

        return response($image, 200, [
            'Content-Type' => $dispute->evidence_mime ?: 'application/octet-stream',
            'Content-Length' => (string) strlen($image),
            'Content-Disposition' => 'inline; filename="match-'.$match->id.'-evidence"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function resolve(Request $request, TournamentMatch $match, BracketBuilder $brackets): JsonResponse
    {
        abort_unless($match->status === 'disputed', 409, 'This match has no open dispute.');
        abort_unless($match->tournament->organizer_id === $request->user()->id, 403);
        $data = $this->validatedScore($request, $match);
        $data = [...$data, ...$request->validate([
            'outcome' => ['required', 'in:valid,invalid'],
            'resolution' => ['required', 'string', 'min:8', 'max:2000'],
        ])];
        DB::transaction(function () use ($request, $match, $data, $brackets): void {
            $aWins = $data['score_a'] > $data['score_b'];
            $winner = $aWins ? $match->entrant_a_id : $match->entrant_b_id;
            $match->update(['score_a' => $data['score_a'], 'score_b' => $data['score_b'], 'winner_entrant_id' => $winner, 'winner_team_id' => $aWins ? $match->team_a_id : $match->team_b_id, 'status' => 'completed', 'result_confirmed_by' => $request->user()->id, 'result_confirmed_at' => now()]);
            MatchDispute::where('match_id', $match->id)->whereNull('resolved_at')->latest('id')->first()?->update(['outcome' => $data['outcome'], 'resolved_by' => $request->user()->id, 'resolution' => $data['resolution'], 'resolved_at' => now()]);
            $brackets->advanceSingleElimination($match);
            $brackets->advanceDoubleElimination($match);
        });
        return response()->json(['data' => $match->fresh()]);
    }

    public function correct(Request $request, TournamentMatch $match): JsonResponse
    {
        abort_unless((int) $match->tournament->organizer_id === (int) $request->user()->id, 403);
        abort_unless($match->status === 'completed', 409, 'Only a confirmed result can be corrected.');
        $scores = $this->validatedScore($request, $match);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:2000']])['reason'];

        DB::transaction(function () use ($request, $match, $scores, $reason): void {
            $locked = TournamentMatch::query()->lockForUpdate()->findOrFail($match->id);
            abort_unless($locked->status === 'completed', 409, 'This match result changed before the correction was saved.');
            abort_unless($locked->score_a !== $scores['score_a'] || $locked->score_b !== $scores['score_b'], 422, 'The corrected score is unchanged.');

            $oldWinner = (int) $locked->winner_entrant_id;
            $newWinner = $scores['score_a'] > $scores['score_b'] ? (int) $locked->entrant_a_id : (int) $locked->entrant_b_id;
            $later = $locked->tournament->matches()->where('round', '>', $locked->round)->get();
            if ($locked->tournament->format === 'double_elimination' && $later->isNotEmpty()) {
                abort(409, 'This double-elimination result already feeds later rounds. Contact the organizer team to correct the bracket safely.');
            }
            if ($locked->tournament->format === 'single_elimination' && $later->contains(fn (TournamentMatch $row) => $row->status !== 'scheduled')) {
                abort(409, 'A later match has already started or finished, so this correction would change its bracket path.');
            }

            MatchResultCorrection::create([
                'match_id' => $locked->id,
                'corrected_by' => $request->user()->id,
                'previous_score_a' => $locked->score_a,
                'previous_score_b' => $locked->score_b,
                'corrected_score_a' => $scores['score_a'],
                'corrected_score_b' => $scores['score_b'],
                'reason' => $reason,
            ]);

            $locked->update([
                'score_a' => $scores['score_a'],
                'score_b' => $scores['score_b'],
                'winner_entrant_id' => $newWinner,
                'winner_team_id' => $scores['score_a'] > $scores['score_b'] ? $locked->team_a_id : $locked->team_b_id,
                'result_confirmed_by' => $request->user()->id,
                'result_confirmed_at' => now(),
            ]);

            if ($oldWinner !== $newWinner && $locked->tournament->format === 'single_elimination' && $later->isNotEmpty()) {
                $next = $later->where('round', (int) $locked->round + 1);
                abort_unless($later->count() === $next->count(), 409, 'This correction would affect more than the immediately following round.');
                $destination = $next->first(fn (TournamentMatch $row) => (int) $row->entrant_a_id === $oldWinner || (int) $row->entrant_b_id === $oldWinner);
                abort_unless($destination, 409, 'The previous winner is no longer in the next round, so the bracket cannot be corrected automatically.');
                $side = (int) $destination->entrant_a_id === $oldWinner ? 'a' : 'b';
                $destination->update([
                    "entrant_{$side}_id" => $newWinner,
                    "team_{$side}_id" => $side === 'a' ? $locked->team_b_id : $locked->team_a_id,
                    'scheduled_at' => null,
                    'entrant_a_ready_at' => null,
                    'entrant_b_ready_at' => null,
                ]);
            }
        });

        return response()->json(['data' => $match->fresh()->load('resultCorrections.corrector:id,name')]);
    }

    private function validatedScore(Request $request, TournamentMatch $match): array
    {
        $data = array_map('intval', $request->validate([
            'score_a' => ['required', 'integer', 'min:0', 'max:99'],
            'score_b' => ['required', 'integer', 'min:0', 'max:99'],
        ]));
        $target = intdiv($match->best_of, 2) + 1;
        $aWins = $data['score_a'] === $target && $data['score_b'] < $target;
        $bWins = $data['score_b'] === $target && $data['score_a'] < $target;
        if (! $aWins && ! $bWins) {
            throw ValidationException::withMessages(['score' => "The winning score must be {$target} for a best-of-{$match->best_of} series."]);
        }
        return $data;
    }
}
