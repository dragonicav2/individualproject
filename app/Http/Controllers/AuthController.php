<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\StrongAccountPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'bio' => ['nullable', 'string', 'max:500'],
            'favorite_game' => ['nullable', Rule::in(['cs2', 'valorant', 'sf6', 'mlbb'])],
            'game_interests' => $request->user()->account_type === 'player' ? ['required', 'array', 'max:4'] : ['sometimes', 'array', 'max:4'],
            'game_interests.*' => ['string', Rule::in(['cs2', 'valorant', 'sf6', 'mlbb']), 'distinct'],
            'game_profiles' => ['nullable', 'array'],
            'game_profiles.cs2' => ['nullable', 'string', 'max:80'],
            'game_profiles.valorant' => ['nullable', 'string', 'max:80'],
            'game_profiles.sf6' => ['nullable', 'string', 'max:80'],
            'game_profiles.mlbb' => ['nullable', 'string', 'max:80'],
        ]);
        if (array_key_exists('game_interests', $data)) $data['favorite_game'] = $data['game_interests'][0] ?? null;
        $request->user()->update($data);

        return response()->json(['user' => $request->user()->fresh()]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4096,max_height=4096'],
        ]);

        $user = $request->user();
        $previousPath = $user->avatar_path;
        $path = $data['avatar']->store("avatars/{$user->id}", 'public');
        $user->update(['avatar_path' => $path]);

        if ($previousPath) {
            Storage::disk('public')->delete($previousPath);
        }

        return response()->json(['user' => $user->fresh()]);
    }

    public function showAvatar(User $user)
    {
        abort_unless($user->account_type !== 'admin', 404);
        abort_unless($user->avatar_path && Storage::disk('public')->exists($user->avatar_path), 404);

        return response()->file(Storage::disk('public')->path($user->avatar_path), [
            'Content-Type' => Storage::disk('public')->mimeType($user->avatar_path) ?: 'application/octet-stream',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return response()->json(['user' => $user->fresh()]);
    }

    public function register(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $value))])->exists()) {
                    $fail('An account with this email already exists. Sign in or use another email.');
                }
            }],
            'account_type' => ['required', Rule::in(['player', 'organizer'])],
            'game_interests' => $request->input('account_type') === 'player' ? ['required', 'array', 'min:1', 'max:4'] : ['sometimes', 'array', 'max:4'],
            'game_interests.*' => ['string', Rule::in(['cs2', 'valorant', 'sf6', 'mlbb']), 'distinct'],
            'password' => ['required', 'confirmed', new StrongAccountPassword((string) $request->input('name'))],
        ]);
        $interests = $data['game_interests'] ?? [];
        $data['game_interests'] = $interests;
        $data['favorite_game'] = $interests[0] ?? null;
        $user = User::create($data);
        $token = $user->createToken('web')->plainTextToken;
        return response()->json(['user' => $user, 'token' => $token], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->first();
        abort_unless($user && Hash::check($data['password'], $user->password), 422, 'Email or password is incorrect.');
        abort_if($user->is_platform_admin, 403, 'Use the platform administrator sign-in page.');
        return response()->json(['user' => $user, 'token' => $user->createToken('web')->plainTextToken]);
    }

    public function adminLogin(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->first();
        abort_unless($user && Hash::check($data['password'], $user->password) && $user->is_platform_admin, 422, 'Administrator email or password is incorrect.');
        if ($user->must_change_password && (! $user->temporary_password_expires_at || now()->greaterThan($user->temporary_password_expires_at))) {
            abort(422, 'This temporary password has expired. Ask the primary administrator to issue a new one.');
        }
        return response()->json(['user' => $user, 'token' => $user->createToken('admin-web')->plainTextToken]);
    }

    public function sendPasswordResetLink(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = mb_strtolower(trim($data['email']));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $genericMessage = 'If an account exists for that email, a password reset link has been sent.';

        // Do not call the mail broker at all when there is no matching account.
        // Keep the same response so the endpoint does not reveal account existence.
        if (! $user) {
            return response()->json(['message' => $genericMessage]);
        }

        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_THROTTLED) {
            return response()->json(['message' => 'Please wait before requesting another reset email.'], 429);
        }

        // Keep the response the same whether the account exists or not.
        return response()->json(['message' => $genericMessage]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', 'string', 'min:8'],
        ]);
        $user = User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->first();
        if ($user) {
            validator($data, ['password' => [new StrongAccountPassword($user->name)]])->validate();
        }

        $status = Password::reset(
            ['email' => $data['email'], 'token' => $data['token'], 'password' => $data['password'], 'password_confirmation' => $request->input('password_confirmation')],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'must_change_password' => false,
                    'temporary_password_expires_at' => null,
                ])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'This password reset link is invalid, expired, or has already been used.'], 422);
        }

        return response()->json(['message' => 'Your password has been reset. You can now sign in.']);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', new StrongAccountPassword((string) $request->user()->name)],
        ]);
        abort_unless(Hash::check($data['current_password'], $request->user()->password), 422, 'Current password is incorrect.');
        $request->user()->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
            'temporary_password_expires_at' => null,
        ])->save();
        return response()->json(['message' => 'Password updated.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'Signed out.']);
    }
}
