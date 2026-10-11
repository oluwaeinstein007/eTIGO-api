<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ride\CancelRideRequest;
use App\Http\Requests\Ride\StoreRideRequest;
use App\Http\Requests\Ride\VerifyPinRequest;
use App\Http\Resources\RideDetailResource;
use App\Http\Resources\RideResource;
use App\Jobs\FinalFareCalculationJob;
use App\Models\Ride;
use App\Models\User;
use App\Services\DriverEarningsService;
use App\Services\DriverLocationService;
use App\Services\DriverMatchingService;
use App\Services\RideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RideController extends Controller
{
    private const int ARRIVAL_RADIUS_METERS = 150;

    private const int DRIVER_LOCATION_MAX_AGE_SECONDS = 120;

    public function __construct(
        private RideService $rideService,
        private DriverMatchingService $matchingService,
        private DriverLocationService $locationService,
        private DriverEarningsService $earningsService,
    ) {}

    public function store(StoreRideRequest $request): JsonResponse
    {
        $user = $request->user();

        try {
            $result = DB::transaction(function () use ($user, $request) {
                User::lockForUpdate()->find($user->id);

                $timeoutSeconds = config('matching.matching_timeout', 300);
                $staleRides = Ride::where('passenger_id', $user->id)
                    ->whereIn('status', [RideStatus::Requested, RideStatus::Searching])
                    ->where('created_at', '<', now()->subSeconds($timeoutSeconds))
                    ->get();

                foreach ($staleRides as $staleRide) {
                    $this->rideService->expireStaleSearch($staleRide);
                }

                $activeRide = Ride::where('passenger_id', $user->id)
                    ->whereIn('status', RideStatus::activeStatuses())
                    ->exists();

                if ($activeRide) {
                    return null;
                }

                return $this->rideService->createRide(
                    passenger: $user,
                    cityId: $request->input('city_id'),
                    vehicleClassId: $request->input('vehicle_class_id'),
                    pickupLat: (float) $request->input('pickup_lat'),
                    pickupLng: (float) $request->input('pickup_lng'),
                    pickupAddress: $request->input('pickup_address'),
                    destinationLat: (float) $request->input('destination_lat'),
                    destinationLng: (float) $request->input('destination_lng'),
                    destinationAddress: $request->input('destination_address'),
                    paymentMethod: PaymentMethod::from($request->input('payment_method')),
                );
            });
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['payment_method' => [$e->getMessage()]],
            ], 422);
        }

        if (! $result) {
            return response()->json([
                'message' => 'You already have an active ride. Please complete or cancel it first.',
            ], 409);
        }

        $ride = $result['ride']->load(['city', 'vehicleClass', 'passenger']);

        return response()->json([
            'message' => 'Ride created successfully.',
            'ride' => new RideResource($ride),
            'pin_code' => $result['pin_code'],
        ], 201);
    }

    public function show(Request $request, Ride $ride): JsonResponse
    {
        $user = $request->user();

        if (! $this->canViewRide($user, $ride)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $ride->load(['city', 'vehicleClass', 'passenger', 'driver.driver.vehicle', 'stateTransitions', 'cancelledByUser']);

        return response()->json([
            'ride' => new RideDetailResource($ride),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Ride::query();

        if ($user->isPassenger()) {
            $query->where('passenger_id', $user->id);
        } elseif ($user->isDriver()) {
            $query->where('driver_id', $user->driver?->user_id ?? $user->id);
        }

        if ($request->has('status')) {
            $status = RideStatus::tryFrom($request->input('status'));
            if ($status) {
                $query->where('status', $status);
            }
        }

        if ($request->has('city_id')) {
            $query->where('city_id', $request->input('city_id'));
        }

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        $perPage = min($request->integer('per_page', 15), 50);

        $rides = $query->with(['city', 'vehicleClass'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'rides' => RideResource::collection($rides),
            'meta' => [
                'current_page' => $rides->currentPage(),
                'last_page' => $rides->lastPage(),
                'per_page' => $rides->perPage(),
                'total' => $rides->total(),
            ],
        ]);
    }

    public function cancel(CancelRideRequest $request, Ride $ride): JsonResponse
    {
        $user = $request->user();

        if (! $this->canCancelRide($user, $ride)) {
            return response()->json(['message' => 'You are not authorized to cancel this ride.'], 403);
        }

        if (! $ride->isCancellable()) {
            return response()->json([
                'message' => 'This ride cannot be cancelled in its current state.',
                'current_status' => $ride->status->value,
            ], 422);
        }

        $ride = $this->rideService->cancelRide(
            $ride,
            $user,
            $request->input('reason'),
            $request->input('reason_details'),
        );

        $ride->load(['city', 'vehicleClass', 'passenger', 'driver', 'cancelledByUser']);

        return response()->json([
            'message' => 'Ride cancelled successfully.',
            'ride' => new RideResource($ride),
        ]);
    }

    public function rebroadcast(Request $request, Ride $ride): JsonResponse
    {
        $passenger = $request->user();

        if (! $passenger->isPassenger() || ! $ride->belongsToPassenger($passenger)) {
            return response()->json(['message' => 'You are not authorized to retry this ride.'], 403);
        }

        if ($ride->status !== RideStatus::NoDriverFound) {
            return response()->json([
                'message' => 'This ride can only be retried after no driver was found.',
                'current_status' => $ride->status->value,
            ], 422);
        }

        $ride = $this->rideService->rebroadcastRide($ride, $passenger);
        $ride->load(['city', 'vehicleClass', 'passenger']);

        return response()->json([
            'message' => 'Ride request sent again.',
            'ride' => new RideResource($ride),
        ]);
    }

    public function driverArrived(Request $request, Ride $ride): JsonResponse
    {
        $user = $request->user();
        $driver = $user->driver;

        if (! $driver || ! $ride->isAssignedToDriver($user->id)) {
            return response()->json(['message' => 'You are not assigned to this ride.'], 403);
        }

        if ($ride->status !== RideStatus::DriverEnRoute) {
            return response()->json([
                'message' => 'Cannot mark arrival in current ride state.',
                'current_status' => $ride->status->value,
            ], 422);
        }

        $location = $this->locationService->getDriverLocation((string) $driver->id);
        if ($location === null || $location['timestamp'] < now()->subSeconds(self::DRIVER_LOCATION_MAX_AGE_SECONDS)->timestamp) {
            return response()->json([
                'message' => 'We could not confirm your recent location. Wait for your location to update, then try again.',
            ], 422);
        }

        $distanceFromPickup = $this->distanceInMeters(
            $location['lat'],
            $location['lng'],
            (float) $ride->pickup_lat,
            (float) $ride->pickup_lng,
        );
        if ($distanceFromPickup > self::ARRIVAL_RADIUS_METERS) {
            return response()->json([
                'message' => 'Move closer to the pickup location before marking arrival.',
            ], 422);
        }

        $ride = $this->rideService->driverArrived($ride, $user);
        $ride->load(['city', 'vehicleClass', 'passenger', 'driver', 'stateTransitions']);

        return response()->json([
            'message' => 'Driver arrival confirmed. Waiting for passenger PIN verification.',
            'ride' => new RideDetailResource($ride),
        ]);
    }

    private function distanceInMeters(
        float $firstLatitude,
        float $firstLongitude,
        float $secondLatitude,
        float $secondLongitude,
    ): float {
        $earthRadiusMeters = 6_371_000;
        $latitudeDelta = deg2rad($secondLatitude - $firstLatitude);
        $longitudeDelta = deg2rad($secondLongitude - $firstLongitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($firstLatitude))
            * cos(deg2rad($secondLatitude))
            * sin($longitudeDelta / 2) ** 2;

        return $earthRadiusMeters * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function verifyPin(VerifyPinRequest $request, Ride $ride): JsonResponse
    {
        $user = $request->user();
        $driver = $user->driver;

        if (! $driver || ! $ride->isAssignedToDriver($user->id)) {
            return response()->json(['message' => 'You are not assigned to this ride.'], 403);
        }

        if ($ride->status !== RideStatus::DriverArrived) {
            return response()->json([
                'message' => 'PIN verification is only available when driver has arrived.',
                'current_status' => $ride->status->value,
            ], 422);
        }

        $result = $this->rideService->verifyPinAndStart($ride, $user, $request->input('pin_code'));

        if (! $result['success']) {
            return response()->json([
                'message' => $result['error'],
                'verified' => false,
            ], 422);
        }

        $result['ride']->load(['city', 'vehicleClass', 'passenger', 'driver']);

        return response()->json([
            'message' => 'PIN verified. Ride started.',
            'verified' => true,
            'ride' => new RideResource($result['ride']),
        ]);
    }

    public function complete(Request $request, Ride $ride): JsonResponse
    {
        $user = $request->user();
        $driver = $user->driver;

        if (! $driver || ! $ride->isAssignedToDriver($user->id)) {
            return response()->json(['message' => 'You are not assigned to this ride.'], 403);
        }

        if ($ride->status !== RideStatus::InProgress) {
            return response()->json([
                'message' => 'Only in-progress rides can be completed.',
                'current_status' => $ride->status->value,
            ], 422);
        }

        $ride = $this->rideService->completeRide($ride, $user);

        FinalFareCalculationJob::dispatch($ride->id);

        $ride->load(['city', 'vehicleClass', 'passenger', 'driver']);

        return response()->json([
            'message' => 'Ride completed successfully.',
            'ride' => new RideResource($ride),
        ]);
    }

    public function accept(Request $request, Ride $ride): JsonResponse
    {
        $user = $request->user();
        $driver = $user->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 403);
        }

        if ($this->earningsService->hasExcessiveNegativeBalance($user->id)) {
            return response()->json([
                'message' => 'You cannot accept rides while your earnings balance is below the allowed threshold. Please clear your outstanding balance.',
            ], 403);
        }

        if ($ride->status !== RideStatus::Searching) {
            return response()->json([
                'message' => 'This ride is no longer available for acceptance.',
                'current_status' => $ride->status->value,
            ], 422);
        }

        $dispatched = $this->matchingService->getDispatchedDriverId($ride);
        if ($dispatched !== $user->id) {
            return response()->json([
                'message' => 'This ride was not dispatched to you.',
            ], 403);
        }

        $result = $this->rideService->acceptRide($ride, $user);

        if (! $result['success']) {
            return response()->json(['message' => $result['error']], 409);
        }

        $result['ride']->load(['city', 'vehicleClass', 'passenger', 'driver']);

        return response()->json([
            'message' => 'Ride accepted.',
            'ride' => new RideResource($result['ride']),
        ]);
    }

    public function reject(Request $request, Ride $ride): JsonResponse
    {
        $user = $request->user();
        $driver = $user->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 403);
        }

        if ($ride->status !== RideStatus::Searching) {
            return response()->json([
                'message' => 'This ride is no longer available.',
            ], 422);
        }

        if ($this->matchingService->getDispatchedDriverId($ride) !== $user->id) {
            return response()->json(['message' => 'This ride was not dispatched to you.'], 403);
        }

        $this->rideService->rejectRide($ride, $user);

        return response()->json([
            'message' => 'Ride request rejected. It will be dispatched to another driver.',
        ]);
    }

    private function canViewRide(User $user, Ride $ride): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isPassenger() && $ride->passenger_id === $user->id) {
            return true;
        }

        if ($user->isDriver() && $ride->driver_id === $user->id) {
            return true;
        }

        return false;
    }

    private function canCancelRide(User $user, Ride $ride): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isPassenger() && $ride->passenger_id === $user->id) {
            return true;
        }

        if ($user->isDriver() && $ride->driver_id === $user->id) {
            return true;
        }

        return false;
    }
}
