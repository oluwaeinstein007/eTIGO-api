<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\NigerianStates;
use Illuminate\Http\JsonResponse;

class LookupController extends Controller
{
    public function states(): JsonResponse
    {
        return response()->json([
            'states' => NigerianStates::all(),
        ]);
    }

    public function regions(): JsonResponse
    {
        return response()->json([
            'regions' => NigerianStates::regions(),
        ]);
    }
}
