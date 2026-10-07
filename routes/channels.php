<?php

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

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id): bool {
    return $user->id === $id;
});

Broadcast::channel('ride.{rideId}', function (User $user, string $rideId): bool {
    $ride = Ride::find($rideId);

    if (! $ride) {
        return false;
    }

    return $ride->passenger_id === $user->id
        || $ride->driver_id === $user->id;
});

Broadcast::channel('admin.rides', function (User $user): bool {
    return $user->isAdmin();
});

Broadcast::channel('driver.{driverUserId}', function (User $user, string $driverUserId): bool {
    return $user->id === $driverUserId && $user->isDriver();
});
