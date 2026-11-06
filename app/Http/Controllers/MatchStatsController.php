<?php

namespace App\Http\Controllers;

use App\Models\TournamentMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MatchStatsController extends Controller
{
    public function update(Request $request, TournamentMatch $match): JsonResponse
    {
        abort_unless((int) $match->tournament->organizer_id === (int) $request->user()->id, 403);
        abort_unless($match->status === 'completed', 409, 'Game statistics can be edited after the match result is confirmed.');

        $data = $request->validate([
            'game_stats' => ['required', 'array'],
            'game_stats.map' => ['nullable', 'string', 'max:80'],
            'game_stats.duration' => ['nullable', 'string', 'max:16'],
            'game_stats.entrants' => ['required', 'array'],
        ]);
        $fields = $this->fieldsFor($match->tournament->game);
        $teamMetrics = $this->teamMetricsFor($match->tournament->game);
        $clean = ['entrants' => []];
        if (filled($data['game_stats']['map'] ?? null)) $clean['map'] = trim($data['game_stats']['map']);
        if (filled($data['game_stats']['duration'] ?? null)) $clean['duration'] = trim($data['game_stats']['duration']);

        foreach ([$match->entrantA, $match->entrantB] as $entry) {
            abort_if(! $entry, 409, 'Both match entrants must be assigned before game stats can be recorded.');
            $key = (string) $entry->id;
            $entrantData = $data['game_stats']['entrants'][$key] ?? null;
            if (! is_array($entrantData) || ! is_array($entrantData['players'] ?? null)) {
                throw ValidationException::withMessages(["game_stats.entrants.{$key}.players" => 'Enter stats for each participating player.']);
            }

            $allowedIds = $entry->user_id
                ? [(int) $entry->user_id]
                : $entry->team?->members()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $allowedIds ??= [];
            if (count($entrantData['players']) !== (int) $match->tournament->team_size) {
                throw ValidationException::withMessages(["game_stats.entrants.{$key}.players" => "Enter exactly {$match->tournament->team_size} player stat lines for this entrant."]);
            }

            $rows = [];
            $seen = [];
            foreach (array_values($entrantData['players']) as $index => $player) {
                if (! is_array($player)) {
                    throw ValidationException::withMessages(["game_stats.entrants.{$key}.players.{$index}" => 'Each player stat line must be an object.']);
                }
                $rules = ['player_id' => ['required', 'integer']];
                foreach ($fields as $field => $type) $rules[$field] = ['required', $type === 'decimal' ? 'numeric' : 'integer', 'min:0', 'max:99999'];
                $rules[$this->pickFieldFor($match->tournament->game)] = ['nullable', 'string', 'max:80'];
                if ($match->tournament->game === 'mlbb') $rules['items'] = ['nullable', 'array', 'max:6'];
                if ($match->tournament->game === 'valorant') $rules['weapons'] = ['nullable', 'array', 'max:5'];
                $rules['weapons.*'] = ['string', 'max:80'];
                $line = Validator::make($player, $rules)->validate();
                $playerId = (int) $line['player_id'];
                if (! in_array($playerId, $allowedIds, true) || in_array($playerId, $seen, true)) {
                    throw ValidationException::withMessages(["game_stats.entrants.{$key}.players.{$index}.player_id" => 'Choose each participating roster member once.']);
                }
                $seen[] = $playerId;
                $user = \App\Models\User::query()->findOrFail($playerId);
                $row = ['player_id' => $playerId, 'name' => $user->name];
                $pickField = $this->pickFieldFor($match->tournament->game);
                if (filled($line[$pickField] ?? null)) $row[$pickField] = trim($line[$pickField]);
                if ($match->tournament->game === 'valorant') {
                    $row['weapons'] = collect($line['weapons'] ?? [])->filter(fn ($weapon) => is_string($weapon) && filled(trim($weapon)))->map(fn ($weapon) => trim($weapon))->unique()->values()->all();
                }
                if ($match->tournament->game === 'mlbb') {
                    $row['items'] = collect($line['items'] ?? [])
                        ->filter(fn ($item) => is_string($item) && filled(trim($item)))
                        ->map(fn ($item) => trim($item))
                        ->values()
                        ->all();
                }
                foreach ($fields as $field => $type) $row[$field] = $type === 'decimal' ? (float) $line[$field] : (int) $line[$field];
                $rows[] = $row;
            }

            $entrantStats = ['players' => $rows];
            foreach (['kills', 'deaths', 'assists', 'perfect_rounds', 'drive_impacts', 'rounds_won'] as $sumField) {
                if (collect($rows)->contains(fn (array $row) => array_key_exists($sumField, $row))) {
                    $entrantStats[$sumField] = array_sum(array_column($rows, $sumField));
                }
            }
            foreach ($teamMetrics as $metric) {
                $rule = Validator::make($entrantData, [$metric => ['required', 'integer', 'min:0', 'max:99999']]);
                $teamData = $rule->validate();
                $entrantStats[$metric] = (int) $teamData[$metric];
            }
            $clean['entrants'][$key] = $entrantStats;
        }

        $match->update(['game_stats' => $clean]);
        return response()->json(['data' => $match->fresh()->load('entrantA.team:id,name', 'entrantA.user:id,name', 'entrantB.team:id,name', 'entrantB.user:id,name')]);
    }

    private function fieldsFor(string $game): array
    {
        return match ($game) {
            'cs2' => ['kills' => 'integer', 'deaths' => 'integer', 'assists' => 'integer', 'adr' => 'decimal'],
            'valorant' => ['kills' => 'integer', 'deaths' => 'integer', 'assists' => 'integer', 'acs' => 'decimal', 'first_bloods' => 'integer', 'plants' => 'integer'],
            'mlbb' => ['kills' => 'integer', 'deaths' => 'integer', 'assists' => 'integer', 'gold' => 'integer', 'towers' => 'integer'],
            default => ['sets_won' => 'integer', 'perfect_rounds' => 'integer', 'drive_impacts' => 'integer', 'rounds_won' => 'integer'],
        };
    }

    private function teamMetricsFor(string $game): array
    {
        return match ($game) {
            'cs2' => ['rounds_won'],
            'valorant' => ['rounds_won', 'plants'],
            'mlbb' => ['towers'],
            default => [],
        };
    }

    private function pickFieldFor(string $game): string
    {
        return match ($game) { 'cs2' => 'weapon', 'valorant' => 'agent', 'mlbb' => 'hero', default => 'character' };
    }
}
