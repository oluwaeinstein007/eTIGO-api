<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminPassengerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::where('type', UserType::Passenger);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $passengers = $query->latest()->paginate(20);

        return response()->json([
            'passengers' => UserResource::collection($passengers),
            'meta' => [
                'current_page' => $passengers->currentPage(),
                'last_page' => $passengers->lastPage(),
                'per_page' => $passengers->perPage(),
                'total' => $passengers->total(),
            ],
        ]);
    }

    public function show(User $passenger): JsonResponse
    {
        if ($passenger->type !== UserType::Passenger) {
            return response()->json(['message' => 'User is not a passenger.'], 404);
        }

        $passenger->loadCount([
            'notifications',
        ]);

        return response()->json([
            'passenger' => new UserResource($passenger),
            'statistics' => [
                'total_rides' => $passenger->rides()->count(),
                'completed_rides' => $passenger->rides()->where('status', 'completed')->count(),
                'cancelled_rides' => $passenger->rides()->where('status', 'cancelled')->count(),
            ],
        ]);
    }

    public function suspend(Request $request, User $passenger): JsonResponse
    {
        if ($passenger->type !== UserType::Passenger) {
            return response()->json(['message' => 'User is not a passenger.'], 404);
        }

        if (! $passenger->is_active) {
            return response()->json([
                'message' => 'Passenger account is already suspended.',
            ], 422);
        }

        $admin = $request->user();

        DB::transaction(function () use ($passenger, $admin) {
            $passenger->update(['is_active' => false]);

            $passenger->tokens()->delete();

            AuditLog::record($passenger, 'passenger_suspended', $admin);
        });

        return response()->json([
            'message' => 'Passenger account suspended.',
            'passenger' => new UserResource($passenger->fresh()),
        ]);
    }

    public function reactivate(Request $request, User $passenger): JsonResponse
    {
        if ($passenger->type !== UserType::Passenger) {
            return response()->json(['message' => 'User is not a passenger.'], 404);
        }

        if ($passenger->is_active) {
            return response()->json([
                'message' => 'Passenger account is already active.',
            ], 422);
        }

        $admin = $request->user();

        DB::transaction(function () use ($passenger, $admin) {
            $passenger->update(['is_active' => true]);

            AuditLog::record($passenger, 'passenger_reactivated', $admin);
        });

        return response()->json([
            'message' => 'Passenger account reactivated.',
            'passenger' => new UserResource($passenger->fresh()),
        ]);
    }
}
