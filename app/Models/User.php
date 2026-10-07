<?php

namespace App\Models;

use App\Enums\AdminRole;
use App\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'first_name',
    'last_name',
    'phone',
    'email',
    'type',
    'admin_role',
    'password',
    'profile_photo_path',
    'is_active',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    use HasUuids;

    protected function casts(): array
    {
        return [
            'type' => UserType::class,
            'admin_role' => AdminRole::class,
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(UserPaymentMethod::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function rides(): HasMany
    {
        return $this->hasMany(Ride::class, 'passenger_id');
    }

    public function driverRides(): HasMany
    {
        return $this->hasMany(Ride::class, 'driver_id');
    }

    public function isPassenger(): bool
    {
        return $this->type === UserType::Passenger;
    }

    public function isDriver(): bool
    {
        return $this->type === UserType::Driver;
    }

    public function isAdmin(): bool
    {
        return $this->type === UserType::Admin;
    }

    public function isSafetyOperator(): bool
    {
        return $this->isAdmin() && $this->admin_role === AdminRole::SafetyOperator;
    }

    public function hasAdminRole(AdminRole $role): bool
    {
        return $this->isAdmin() && $this->admin_role === $role;
    }

    public function hasPhoneVerified(): bool
    {
        return $this->phone_verified_at !== null;
    }
}
