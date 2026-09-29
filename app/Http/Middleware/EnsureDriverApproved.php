<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDriverApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isDriver()) {
            return response()->json(['message' => 'Forbidden. Driver access required.'], 403);
        }

        $driver = $user->driver;

        if (! $driver || ! $driver->isApproved()) {
            return response()->json([
                'message' => 'Your driver account is not yet approved.',
                'status' => $driver?->status?->value ?? 'no_profile',
            ], 403);
        }

        return $next($request);
    }
}
