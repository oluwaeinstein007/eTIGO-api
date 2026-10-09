<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Wallet\UpdateCommissionRequest;
use App\Http\Resources\CommissionConfigResource;
use App\Models\AuditLog;
use App\Models\CommissionConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminCommissionController extends Controller
{
    public function show(): JsonResponse
    {
        $global = CommissionConfig::active()->global()->first();
        $overrides = CommissionConfig::active()->whereNotNull('driver_id')->with('driver.user')->get();

        return response()->json([
            'global_rate' => $global ? (float) $global->rate : config('wallet.default_commission_rate'),
            'overrides' => CommissionConfigResource::collection($overrides),
        ]);
    }

    public function update(UpdateCommissionRequest $request): JsonResponse
    {
        $rate = $request->validated('rate');
        $driverId = $request->validated('driver_id') ?? null;

        $config = DB::transaction(function () use ($request, $rate, $driverId) {
            $config = CommissionConfig::updateOrCreate(
                [
                    'driver_id' => $driverId,
                    'is_active' => true,
                ],
                [
                    'rate' => $rate,
                    'created_by_admin_id' => $request->user()->id,
                ],
            );

            AuditLog::record($config, 'commission.updated', $request->user(), null, [
                'rate' => $rate,
                'driver_id' => $driverId,
            ]);

            return $config;
        });

        return response()->json([
            'message' => $driverId ? 'Driver commission override updated.' : 'Global commission rate updated.',
            'commission' => new CommissionConfigResource($config),
        ]);
    }
}
