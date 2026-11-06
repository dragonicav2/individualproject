<?php

namespace App\Http\Controllers;

use App\Models\GameAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminGameAssetController extends Controller
{
    private const CATEGORIES = [
        'cs2' => ['game', 'map', 'weapon'],
        'valorant' => ['game', 'map', 'agent', 'weapon'],
        'sf6' => ['game', 'stage', 'character'],
        'mlbb' => ['game', 'map', 'hero', 'item'],
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $assets = GameAsset::query()->with('uploader:id,name')->orderBy('game')->orderBy('category')->orderBy('display_name')->get();
        return response()->json(['data' => $assets->map(fn (GameAsset $asset) => [...$asset->toArray(), 'image_url' => route('game-assets.image', ['asset' => $asset->id], false)])->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'game' => ['required', Rule::in(array_keys(self::CATEGORIES))],
            'category' => ['required', Rule::in(['game', 'map', 'weapon', 'agent', 'stage', 'character', 'hero', 'item'])],
            'display_name' => ['required', 'string', 'max:120'],
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=32,min_height=32,max_width=4096,max_height=4096'],
        ]);
        abort_unless(in_array($data['category'], self::CATEGORIES[$data['game']], true), 422, 'That image category is not used by the selected game.');

        $slug = Str::slug($data['display_name']);
        abort_if($slug === '', 422, 'Enter a name that can be used as an image key.');
        $old = GameAsset::query()->where(['game' => $data['game'], 'category' => $data['category'], 'slug' => $slug])->first();
        $path = 'game-assets/'.Str::uuid().'.enc';
        $contents = file_get_contents($data['image']->getRealPath());
        abort_unless(is_string($contents), 422, 'The uploaded image could not be read.');
        abort_unless(Storage::disk('local')->put($path, Crypt::encryptString($contents)), 500, 'The encrypted image could not be saved.');

        $asset = GameAsset::updateOrCreate(
            ['game' => $data['game'], 'category' => $data['category'], 'slug' => $slug],
            ['display_name' => trim($data['display_name']), 'storage_path' => $path, 'mime_type' => $data['image']->getMimeType(), 'byte_size' => $data['image']->getSize(), 'uploaded_by' => $request->user()->id],
        );
        if ($old && $old->storage_path !== $path) Storage::disk('local')->delete($old->storage_path);

        return response()->json(['data' => [...$asset->toArray(), 'image_url' => route('game-assets.image', ['asset' => $asset->id], false)]], $old ? 200 : 201);
    }

    public function destroy(Request $request, GameAsset $asset): JsonResponse
    {
        $this->authorizeAdmin($request);
        Storage::disk('local')->delete($asset->storage_path);
        $asset->delete();
        return response()->json(['message' => 'Game image removed.']);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->is_platform_admin && $request->user()->admin_role === 'super_admin', 403, 'Primary administrator access is required to manage game artwork.');
    }
}
