<?php

namespace App\Http\Controllers;

use App\Models\TournamentMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class MlbbScreenshotController extends Controller
{
    public function extract(Request $request, TournamentMatch $match): JsonResponse
    {
        abort_unless((int) $match->tournament->organizer_id === (int) $request->user()->id, 403);
        abort_unless($match->tournament->game === 'mlbb', 422, 'Screenshot extraction is currently available for Mobile Legends matches only.');

        $data = $request->validate(['screenshot' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480']]);
        $path = $data['screenshot']->store('mlbb-ocr');
        $fullPath = Storage::path($path);
        $command = [config('services.mlbb_ocr.command')];
        $command = array_merge($command, config('services.mlbb_ocr.command_args', []), [base_path('mlbb-ocr/extract.py'), $fullPath]);

        try {
            $result = Process::timeout(config('services.mlbb_ocr.timeout', 120))->run($command);
            if (! $result->successful()) {
                $message = trim($result->errorOutput() ?: $result->output());
                if (str_contains($message, 'No module named')) {
                    $message = 'PaddleOCR is not installed yet. Run the local OCR setup command in mlbb-ocr/README.md.';
                }
                abort(422, $message ?: 'The OCR worker could not read that screenshot.');
            }
            $payload = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
            return response()->json(['data' => $payload]);
        } catch (\JsonException) {
            abort(422, 'The OCR worker returned an invalid response.');
        } finally {
            Storage::delete($path);
        }
    }
}
