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
        if (app()->isProduction()) {
            $this->command?->warn('Skipping AdminSeeder in production.');

            return;
        }

        $password = Hash::make('Qwer!234');

        $admins = [
            [
                'first_name' => 'Lanre',
                'last_name' => 'Sanni',
                'phone' => '+2340000000001',
                'email' => 'slanre26+admin@gmail.com',
                'type' => UserType::Admin,
                'admin_role' => AdminRole::SuperAdmin,
                'password' => $password,
                'phone_verified_at' => now(),
            ],
            [
                'first_name' => 'Timonwa',
                'last_name' => 'Akintokun',
                'phone' => '+2340000000002',
                'email' => 'timonwaakintokun@gmail.com',
                'type' => UserType::Admin,
                'admin_role' => AdminRole::SuperAdmin,
                'password' => $password,
                'phone_verified_at' => now(),
            ],
            [
                'first_name' => 'Pelumi',
                'last_name' => 'Adetoye',
                'phone' => '+2340000000003',
                'email' => 'pelumiiadetoye@gmail.com',
                'type' => UserType::Admin,
                'admin_role' => AdminRole::SuperAdmin,
                'password' => $password,
                'phone_verified_at' => now(),
            ],
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'phone' => '+2340000000004',
                'email' => 'admin@etigo.com',
                'type' => UserType::Admin,
                'admin_role' => AdminRole::SuperAdmin,
                'password' => $password,
                'phone_verified_at' => now(),
            ],
            [
                'first_name' => 'Safety',
                'last_name' => 'Operator',
                'phone' => '+2340000000005',
                'email' => 'safety@etigo.com',
                'type' => UserType::Admin,
                'admin_role' => AdminRole::SafetyOperator,
                'password' => $password,
                'phone_verified_at' => now(),
            ],
            [
                'first_name' => 'Operations',
                'last_name' => 'Manager',
                'phone' => '+2340000000006',
                'email' => 'ops@etigo.com',
                'type' => UserType::Admin,
                'admin_role' => AdminRole::Operations,
                'password' => $password,
                'phone_verified_at' => now(),
            ],
            [
                'first_name' => 'Support',
                'last_name' => 'Agent',
                'phone' => '+2340000000007',
                'email' => 'support@etigo.com',
                'type' => UserType::Admin,
                'admin_role' => AdminRole::Support,
                'password' => $password,
                'phone_verified_at' => now(),
            ],
            [
                'first_name' => 'Finance',
                'last_name' => 'Manager',
                'phone' => '+2340000000008',
                'email' => 'finance@etigo.com',
                'type' => UserType::Admin,
                'admin_role' => AdminRole::Finance,
                'password' => $password,
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
