<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdminManager($request);

        $users = User::query()->where('is_platform_admin', true)
            ->orderByRaw("CASE WHEN admin_role = 'super_admin' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'admin_role', 'must_change_password', 'temporary_password_expires_at', 'created_at']);

        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdminManager($request);
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $value))])->exists()) {
                    $fail('An account with this email already exists.');
                }
            }],
        ]);

        [$user, $temporaryPassword] = $this->createLimitedAdmin($data['name'], $data['email']);

        return response()->json([
            'data' => $this->userPayload($user),
            'temporary_password' => $temporaryPassword,
            'expires_at' => $user->temporary_password_expires_at->toIso8601String(),
        ], 201);
    }

    public function issueTemporaryPassword(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdminManager($request);
        abort_unless($user->is_platform_admin && $user->admin_role === 'admin', 404);

        [$user, $temporaryPassword] = $this->createLimitedAdmin($user->name, $user->email, $user);

        return response()->json([
            'data' => $this->userPayload($user),
            'temporary_password' => $temporaryPassword,
            'expires_at' => $user->temporary_password_expires_at->toIso8601String(),
        ]);
    }

    private function createLimitedAdmin(string $name, string $email, ?User $user = null): array
    {
        $temporaryPassword = bin2hex(random_bytes(20)).'!A9';
        $user ??= User::create(['name' => $name, 'email' => $email, 'password' => $temporaryPassword, 'account_type' => 'admin']);
        $user->forceFill([
            'password' => $temporaryPassword,
            'account_type' => 'admin',
            'is_platform_admin' => true,
            'admin_role' => 'admin',
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->addMinutes(5),
        ])->save();
        $user->tokens()->delete();

        return [$user->fresh(), $temporaryPassword];
    }

    private function userPayload(User $user): array
    {
        return $user->only(['id', 'name', 'email', 'admin_role', 'must_change_password', 'temporary_password_expires_at', 'created_at']);
    }

    private function authorizeAdminManager(Request $request): void
    {
        abort_unless($request->user()->is_platform_admin && $request->user()->admin_role === 'super_admin', 403, 'Only the primary platform administrator can manage admin accounts.');
    }
}
