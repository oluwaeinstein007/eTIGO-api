<?php

use App\Enums\AdminRole;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Channel authorization callbacks. Private channels require the
| authenticated user to be authorized before they can subscribe.
|
*/

Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return $user->id === $id;
});

Broadcast::channel('ride.{rideId}', function (User $user, string $rideId): bool {
    $ride = Ride::find($rideId);

    if (! $ride) {
        return false;
    }

    // This channel carries the driver's live location, so the booker of a
    // ride for someone else joins only when the tracking policy includes them.
    return ($ride->passenger_id === $user->id && $ride->bookerSeesTracking())
        || $ride->driver_id === $user->id;
});

Broadcast::channel('admin.rides', function (User $user): bool {
    return $user->isAdmin();
});

Broadcast::channel('admin.sos', function (User $user): bool {
    return $user->isAdmin() && $user->isSafetyOperator()
        || $user->admin_role === AdminRole::SuperAdmin;
});

Broadcast::channel('admin.ev-stations', function (User $user): bool {
    return $user->hasAdminRole(AdminRole::Operations)
        || $user->hasAdminRole(AdminRole::SuperAdmin);
});

Broadcast::channel('driver.{driverUserId}', function (User $user, string $driverUserId): bool {
    return $user->id === $driverUserId && $user->isDriver();
});

Broadcast::channel('passengers.nearby', fn (User $user): bool => $user->isPassenger());
Broadcast::channel('drivers.nearby', fn (User $user): bool => $user->isDriver());

Broadcast::channel('user.{userId}', function (User $user, string $userId): bool {
    return $user->id === $userId;
});
