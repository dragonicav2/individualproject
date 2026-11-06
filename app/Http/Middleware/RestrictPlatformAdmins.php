<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictPlatformAdmins
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->is_platform_admin) return $next($request);

        $path = ltrim($request->path(), '/');
        $method = $request->method();
        $passwordSetupRoutes = [
            'api/v1/auth/me' => ['GET'],
            'api/v1/auth/password' => ['PATCH'],
            'api/v1/auth/logout' => ['POST'],
        ];

        if ($user->must_change_password) {
            abort_unless(in_array($method, $passwordSetupRoutes[$path] ?? [], true), 423, 'Choose a new password before opening the admin dashboard.');
            return $next($request);
        }

        if (isset($passwordSetupRoutes[$path]) && in_array($method, $passwordSetupRoutes[$path], true)) return $next($request);
        if ($path === 'api/v1/analytics/admin' && $method === 'GET') return $next($request);

        $gameAssetReads = $path === 'api/v1/game-assets' || preg_match('#^api/v1/game-assets/\d+/image$#', $path) === 1;
        $adminGameAssetPath = $path === 'api/v1/admin/game-assets' || preg_match('#^api/v1/admin/game-assets/\d+$#', $path) === 1;
        if ($user->admin_role === 'super_admin' && (($gameAssetReads && $method === 'GET') || ($path === 'api/v1/analytics/overview' && $method === 'GET') || ($adminGameAssetPath && in_array($method, ['GET', 'POST', 'DELETE'], true)))) {
            return $next($request);
        }

        $isAdminUsersPath = $path === 'api/v1/admin/users';
        $isAdminPasswordIssuePath = preg_match('#^api/v1/admin/users/\d+/temporary-password$#', $path) === 1;
        if ($user->admin_role === 'super_admin' && (($isAdminUsersPath && in_array($method, ['GET', 'POST'], true)) || ($isAdminPasswordIssuePath && $method === 'POST'))) {
            return $next($request);
        }

        abort(403, 'Administrator accounts are limited to platform monitoring.');
    }
}
