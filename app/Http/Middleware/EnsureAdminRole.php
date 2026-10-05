<?php

namespace App\Http\Middleware;

use App\Enums\AdminRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRole
{
    /**
     * @param  string  ...$roles  Comma-separated admin role values
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            return response()->json(['message' => 'Forbidden. Admin access required.'], 403);
        }

        if ($user->admin_role === AdminRole::SuperAdmin) {
            return $next($request);
        }

        if (empty($roles)) {
            return $next($request);
        }

        $allowedRoles = array_map(
            fn (string $role) => AdminRole::from($role),
            $roles,
        );

        if (! in_array($user->admin_role, $allowedRoles, true)) {
            return response()->json(['message' => 'Forbidden. Insufficient admin privileges.'], 403);
        }

        return $next($request);
    }
}
