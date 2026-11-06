<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use App\Services\EloRatingService;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    public function overview(AnalyticsService $analytics, EloRatingService $eloRatings): JsonResponse
    {
        return response()->json(['data' => $analytics->overview($eloRatings)]);
    }
}
