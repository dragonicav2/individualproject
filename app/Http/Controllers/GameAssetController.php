<?php

namespace App\Http\Controllers;

use App\Models\GameAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class GameAssetController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => GameAsset::query()->orderBy('game')->orderBy('category')->orderBy('display_name')->get(['id', 'game', 'category', 'slug', 'display_name', 'mime_type', 'byte_size'])->map(fn (GameAsset $asset) => [...$asset->toArray(), 'image_url' => route('game-assets.image', ['asset' => $asset->id], false)])->all()]);
    }

    public function image(GameAsset $asset): Response
    {
        abort_unless(Storage::disk('local')->exists($asset->storage_path), 404);
        try {
            $image = Crypt::decryptString(Storage::disk('local')->get($asset->storage_path));
        } catch (\Throwable) {
            abort(404, 'The stored game image could not be decrypted.');
        }

        return response($image, 200, [
            'Content-Type' => $asset->mime_type,
            'Content-Length' => (string) strlen($image),
            'Content-Disposition' => 'inline; filename="game-asset-'.$asset->id.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}