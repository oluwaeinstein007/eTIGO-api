<?php

namespace App\Http\Middleware;

use App\Enums\UserType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserType
{
    /**
     * @param  string  ...$types  Comma-separated user type values
     */
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $allowedTypes = array_map(
            fn (string $type) => UserType::from($type),
            $types,
        );

        if (! in_array($user->type, $allowedTypes, true)) {
            return response()->json(['message' => 'Forbidden. Invalid user type for this resource.'], 403);
        }

        return $next($request);
    }
}
