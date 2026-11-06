<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\TournamentSeedingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TournamentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Tournament::query()->whereIn('status', ['registration_open', 'live', 'completed'])->withCount('entries')->latest()->paginate(20));
    }

    public function show(Tournament $tournament): JsonResponse
    {
        return response()->json($tournament->loadCount(['matches', 'entries']));
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json(Tournament::query()->where('organizer_id', $request->user()->id)->withCount(['matches', 'entries'])->latest()->paginate(30));
    }

    public function entries(Request $request, Tournament $tournament, TournamentSeedingService $seeding): JsonResponse
    {
        abort_unless($tournament->organizer_id === $request->user()->id, 403);
        $ratings = $seeding->ratingsFor($tournament);
        $entries = $tournament->entries()->with(['team:id,name', 'user:id,name'])->orderByRaw('seed IS NULL')->orderBy('seed')->orderBy('id')->get();
        $entries->each(function ($entry) use ($ratings, $seeding): void {
            $rating = $ratings[$seeding->keyForEntry($entry)] ?? ['rating' => 1000, 'matches' => 0, 'wins' => 0, 'losses' => 0];
            $entry->setAttribute('elo_rating', (int) round($rating['rating']));
            $entry->setAttribute('elo_matches', $rating['matches']);
            $entry->setAttribute('elo_wins', $rating['wins']);
            $entry->setAttribute('elo_losses', $rating['losses']);
        });
        return response()->json(['data' => $entries]);
    }

    public function autoSeed(Request $request, Tournament $tournament, TournamentSeedingService $seeding): JsonResponse
    {
        abort_unless((int) $tournament->organizer_id === (int) $request->user()->id, 403);
        return response()->json(['data' => $seeding->autoSeed($tournament)]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->account_type === 'organizer', 403, 'Choose an organizer account to create tournaments.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'game' => ['required', Rule::in(['cs2', 'valorant', 'sf6', 'mlbb'])],
            'team_size' => ['required', 'integer', Rule::in([1, 5])],
            'format' => ['required', Rule::in(['single_elimination', 'double_elimination', 'round_robin'])],
            'best_of' => ['required', Rule::in([1, 3, 5, 7, 9, 11])],
            'capacity' => ['nullable', 'integer', 'min:2', 'max:512'],
            'starts_at' => ['nullable', 'date', 'after:now'],
            'allow_organizer_entry' => ['sometimes', 'boolean'],
            'seeding_strategy' => ['sometimes', Rule::in(['fixed', 'random_each_round', 'high_vs_low', 'similar_elo'])],
        ]);
        // datetime-local inputs are converted to ISO UTC in the browser. Store the
        // wall-clock value in Laravel's configured timezone because SQL timestamps
        // do not retain timezone offsets; otherwise they are re-read as local time
        // and can appear several hours earlier than the organizer scheduled.
        if (! empty($data['starts_at'])) {
            $data['starts_at'] = CarbonImmutable::parse($data['starts_at'])
                ->setTimezone(config('app.timezone'));
        }
        $allowOrganizerEntry = (bool) ($data['allow_organizer_entry'] ?? false);
        $seedingStrategy = $data['seeding_strategy'] ?? 'fixed';
        unset($data['allow_organizer_entry']);
        unset($data['seeding_strategy']);
        $tournament = Tournament::create($data + ['settings' => ['allow_organizer_entry' => $allowOrganizerEntry, 'seeding_strategy' => $seedingStrategy], 'organizer_id' => $request->user()->id, 'status' => 'draft']);
        return response()->json(['data' => $tournament], 201);
    }

    public function updateSeedingStrategy(Request $request, Tournament $tournament): JsonResponse
    {
        abort_unless((int) $tournament->organizer_id === (int) $request->user()->id, 403);
        abort_unless(in_array($tournament->status, ['draft', 'registration_open'], true), 409, 'Reseeding options are locked after the bracket is published.');
        $data = $request->validate(['seeding_strategy' => ['required', Rule::in(['fixed', 'random_each_round', 'high_vs_low', 'similar_elo'])]]);
        $settings = $tournament->settings ?? [];
        $settings['seeding_strategy'] = $data['seeding_strategy'];
        $tournament->update(['settings' => $settings]);

        return response()->json(['data' => $tournament->fresh()]);
    }

    public function updateOrganizerParticipation(Request $request, Tournament $tournament): JsonResponse
    {
        abort_unless((int) $tournament->organizer_id === (int) $request->user()->id, 403);
        abort_unless(in_array($tournament->status, ['draft', 'registration_open'], true), 409, 'Organizer participation can only be changed before the bracket is published.');
        $data = $request->validate(['allow_organizer_entry' => ['required', 'boolean']]);
        $settings = $tournament->settings ?? [];
        $settings['allow_organizer_entry'] = (bool) $data['allow_organizer_entry'];
        $tournament->update(['settings' => $settings]);

        return response()->json(['data' => $tournament->fresh()]);
    }
}
