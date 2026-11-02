<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Resolve a client-supplied page size, clamped to 1..$max so a zero,
     * negative, or oversized value can never disable pagination. An empty
     * value (`?per_page=`) means the default, not 1.
     */
    protected function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        $perPage = $request->filled('per_page') ? $request->integer('per_page') : $default;

        return min(max($perPage, 1), $max);
    }
}
