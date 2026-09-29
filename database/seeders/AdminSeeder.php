<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $admins = [
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'phone' => '+2340000000001',
                'email' => 'admin@etigo.com',
                'type' => UserType::Admin,
                'admin_role' => AdminRole::SuperAdmin,
                'password' => Hash::make('password'),
                'phone_verified_at' => now(),
            ],
            [
                'first_name' => 'Safety',
                'last_name' => 'Operator',
                'phone' => '+2340000000002',
                'email' => 'safety@etigo.com',
                'type' => UserType::Admin,
                'admin_role' => AdminRole::SafetyOperator,
                'password' => Hash::make('password'),
                'phone_verified_at' => now(),
            ],
            [
                'first_name' => 'Operations',
                'last_name' => 'Manager',
                'phone' => '+2340000000003',
                'email' => 'ops@etigo.com',
                'type' => UserType::Admin,
                'admin_role' => AdminRole::Operations,
                'password' => Hash::make('password'),
                'phone_verified_at' => now(),
            ],
        ];

        foreach ($admins as $admin) {
            User::firstOrCreate(
                ['email' => $admin['email']],
                $admin,
            );
        }
    }
}
