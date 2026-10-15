<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Resolve a client-supplied page size, clamped to 1..$max so a zero,
     * negative, or oversized value can never disable pagination.
     */
    protected function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        return min(max($request->integer('per_page', $default), 1), $max);
    }
}
