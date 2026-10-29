<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Wallet\UpdateCommissionRequest;
use App\Http\Resources\CommissionConfigResource;
use App\Models\AuditLog;
use App\Models\CommissionConfig;
use App\Models\Driver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            $previousRate = CommissionConfig::active()->where('driver_id', $driverId)->lockForUpdate()->value('rate');

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

            AuditLog::record($config, 'commission.updated', $request->user(), ['rate' => $previousRate !== null ? (float) $previousRate : null], [
                'rate' => $rate,
                'driver_id' => $driverId,
            ]);

            return $config;
        });

        return response()->json([
            'message' => $driverId ? 'Driver commission override updated.' : 'Global commission rate updated.',
            'commission' => new CommissionConfigResource($config->load('driver.user')),
        ]);
    }

    /**
     * Put a driver back on E-tiGo's rate: their own rate is switched off and
     * they follow the global rate, including later changes to it.
     */
    public function destroyDriverRate(Request $request, Driver $driver): JsonResponse
    {
        $removed = DB::transaction(function () use ($request, $driver) {
            $config = CommissionConfig::active()->where('driver_id', $driver->id)->lockForUpdate()->first();

            if ($config === null) {
                return null;
            }

            $config->update(['is_active' => false]);

            AuditLog::record($config, 'commission.override_removed', $request->user(), ['rate' => (float) $config->rate, 'driver_id' => $driver->id]);

            return $config;
        });

        if ($removed === null) {
            return response()->json(['message' => 'This driver is already on the global commission rate.'], 404);
        }

        $global = CommissionConfig::active()->global()->first();

        return response()->json([
            'message' => 'Driver moved back to the global commission rate.',
            'global_rate' => $global ? (float) $global->rate : config('wallet.default_commission_rate'),
        ]);
    }
}
