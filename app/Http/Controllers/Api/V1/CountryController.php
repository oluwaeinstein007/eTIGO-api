<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\SupportedCountries;
use Illuminate\Http\JsonResponse;

class CountryController extends Controller
{
    /**
     * Return list of supported countries for phone inputs across
     * registration, login, and order ride for someone else.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => SupportedCountries::all(),
        ]);
    }
}
