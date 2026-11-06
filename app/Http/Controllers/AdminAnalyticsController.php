<?php

namespace App\Http\Controllers;

use App\Services\AdminAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminAnalyticsController extends Controller
{
    public function overview(Request $request, AdminAnalyticsService $analytics): JsonResponse
    {
        abort_unless($request->user()->account_type === 'organizer' || $request->user()->is_platform_admin, 403, 'Platform analytics are available to organizers and platform administrators.');

        $data = $request->validate([
            'period' => ['sometimes', Rule::in(['day', 'week', 'month'])],
        ]);

        return response()->json(['data' => $analytics->overview($data['period'] ?? 'week')]);
    }
}

