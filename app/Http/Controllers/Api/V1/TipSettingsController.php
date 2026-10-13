<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;

class TipSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(AppSetting::getTipSettings());
    }
}
