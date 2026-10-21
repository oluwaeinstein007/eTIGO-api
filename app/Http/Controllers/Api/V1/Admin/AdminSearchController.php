<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Driver;
use App\Models\OfflineTripFlag;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Models\User;
use App\Services\AdminAreaAccess;
use App\Services\DisputeAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The console's top-bar search: trips, people and cases in one call. Each
 * group is null when the admin's role cannot open that screen, and holds the
 * few best matches (newest first), not a full result list.
 */
class AdminSearchController extends Controller
{
    public function __construct(
        private AdminAreaAccess $access,
        private DisputeAccess $disputeAccess,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['sometimes', 'integer', 'between:1,10'],
        ]);

        $admin = $request->user();
        $term = trim((string) $request->input('q'));
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $limit = $request->integer('limit', 5);
        $can = fn (string $area) => $this->access->can($admin, $area);

        $matchesPerson = fn (Builder $q) => $q->where(function (Builder $q) use ($like) {
            $q->where('first_name', 'ilike', $like)
                ->orWhere('last_name', 'ilike', $like)
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) ILIKE ?", [$like])
                ->orWhere('phone', 'ilike', $like)
                ->orWhere('email', 'ilike', $like)
                ->orWhere('reference_number', 'ilike', $like);
        });

        return response()->json([
            'query' => $term,
            'results' => [
                'rides' => $can(AdminAreaAccess::MONITORING) ? Ride::query()
                    ->where(fn (Builder $q) => $q->where('reference_number', 'ilike', $like)
                        ->orWhereHas('passenger', $matchesPerson)
                        ->orWhereHas('driver', $matchesPerson))
                    ->with(['passenger', 'driver'])
                    ->latest()
                    ->limit($limit)
                    ->get()
                    ->map(fn (Ride $ride) => [
                        'id' => $ride->id,
                        'reference_number' => $ride->reference_number,
                        'status' => $ride->status->value,
                        'pickup_address' => $ride->pickup_address,
                        'destination_address' => $ride->destination_address,
                        'passenger_name' => $ride->passenger ? $this->name($ride->passenger) : null,
                        'driver_name' => $ride->driver ? $this->name($ride->driver) : null,
                        'created_at' => $ride->created_at?->toIso8601String(),
                    ]) : null,

                'drivers' => $can(AdminAreaAccess::DRIVERS) ? Driver::query()
                    ->where(fn (Builder $q) => $q->where('reference_number', 'ilike', $like)
                        ->orWhereHas('user', $matchesPerson))
                    ->with(['user', 'city'])
                    ->latest()
                    ->limit($limit)
                    ->get()
                    ->map(fn (Driver $driver) => [
                        'id' => $driver->id,
                        'user_id' => $driver->user_id,
                        'reference_number' => $driver->reference_number,
                        'name' => $driver->user ? $this->name($driver->user) : null,
                        'phone' => $driver->user?->phone,
                        'status' => $driver->status->value,
                        'city' => $driver->city?->name,
                    ]) : null,

                'passengers' => $can(AdminAreaAccess::PASSENGERS) ? User::query()
                    ->where('type', UserType::Passenger)
                    ->where($matchesPerson)
                    ->latest()
                    ->limit($limit)
                    ->get()
                    ->map(fn (User $passenger) => [
                        'id' => $passenger->id,
                        'reference_number' => $passenger->reference_number,
                        'name' => $this->name($passenger),
                        'phone' => $passenger->phone,
                        'email' => $passenger->email,
                        'is_active' => (bool) $passenger->is_active,
                    ]) : null,

                'disputes' => $can(AdminAreaAccess::DISPUTES) ? $this->disputeAccess
                    ->scopeVisible(Dispute::query(), $admin)
                    ->where(fn (Builder $q) => $q->where('reference_number', 'ilike', $like)
                        ->orWhereHas('ride', fn (Builder $ride) => $ride->where('reference_number', 'ilike', $like))
                        ->orWhereHas('reportedBy', $matchesPerson))
                    ->latest()
                    ->limit($limit)
                    ->get()
                    ->map(fn (Dispute $dispute) => [
                        'id' => $dispute->id,
                        'reference_number' => $dispute->reference_number,
                        'status' => $dispute->status->value,
                        'category' => $dispute->category?->value,
                        'category_label' => $dispute->category?->label(),
                        'queue' => $dispute->queue?->value,
                        'created_at' => $dispute->created_at?->toIso8601String(),
                    ]) : null,

                'sos_incidents' => $can(AdminAreaAccess::SOS) ? SosIncident::query()
                    ->search($term)
                    ->with('ride')
                    ->latest()
                    ->limit($limit)
                    ->get()
                    ->map(fn (SosIncident $incident) => [
                        'id' => $incident->id,
                        'reference_number' => $incident->reference_number,
                        'status' => $incident->status->value,
                        'ride_reference_number' => $incident->ride?->reference_number,
                        'created_at' => $incident->created_at?->toIso8601String(),
                    ]) : null,

                'offline_flags' => $can(AdminAreaAccess::OFFLINE_FLAGS) ? OfflineTripFlag::query()
                    ->search($term)
                    ->with(['driver', 'ride'])
                    ->latest('flagged_at')
                    ->limit($limit)
                    ->get()
                    ->map(fn (OfflineTripFlag $flag) => [
                        'id' => $flag->id,
                        'reference_number' => $flag->reference_number,
                        'sanction_tier' => $flag->sanction_tier?->value,
                        'driver_name' => $flag->driver ? $this->name($flag->driver) : null,
                        'ride_reference_number' => $flag->ride?->reference_number,
                        'flagged_at' => $flag->flagged_at?->toIso8601String(),
                    ]) : null,
            ],
        ]);
    }

    private function name(User $user): string
    {
        return trim($user->first_name.' '.$user->last_name);
    }
}
