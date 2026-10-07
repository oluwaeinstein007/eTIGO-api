<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 1: Add UUID columns to all parent tables
        $parentTables = [
            'users', 'drivers', 'cities', 'vehicle_classes',
            'driver_documents', 'vehicles', 'pricing_configs',
            'ride_state_transitions', 'payments', 'user_payment_methods',
            'otp_codes', 'social_accounts', 'admin_invitations',
            'notifications', 'device_tokens', 'kyc_verifications',
            'surge_rules', 'audit_logs',
            'ratings', 'disputes', 'promo_codes', 'promo_redemptions',
        ];

        foreach ($parentTables as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN new_id UUID NOT NULL DEFAULT gen_random_uuid()");
        }

        // Phase 2: Add UUID FK columns and populate them via joins
        $foreignKeys = [
            // [child_table, old_fk_column, parent_table, nullable]
            ['sessions', 'user_id', 'users', true],
            ['drivers', 'user_id', 'users', false],
            ['drivers', 'city_id', 'cities', true],
            ['driver_documents', 'driver_id', 'drivers', false],
            ['driver_documents', 'reviewed_by', 'users', true],
            ['vehicles', 'driver_id', 'drivers', false],
            ['vehicles', 'vehicle_class_id', 'vehicle_classes', true],
            ['social_accounts', 'user_id', 'users', false],
            ['city_vehicle_classes', 'city_id', 'cities', false],
            ['city_vehicle_classes', 'vehicle_class_id', 'vehicle_classes', false],
            ['pricing_configs', 'city_id', 'cities', false],
            ['pricing_configs', 'vehicle_class_id', 'vehicle_classes', false],
            ['pricing_configs', 'created_by_admin_id', 'users', false],
            ['rides', 'city_id', 'cities', false],
            ['rides', 'vehicle_class_id', 'vehicle_classes', false],
            ['rides', 'passenger_id', 'users', false],
            ['rides', 'driver_id', 'users', true],
            ['rides', 'cancelled_by', 'users', true],
            ['user_payment_methods', 'user_id', 'users', false],
            ['notifications', 'user_id', 'users', false],
            ['device_tokens', 'user_id', 'users', false],
            ['audit_logs', 'actor_id', 'users', true],
            ['admin_invitations', 'invited_by', 'users', false],
            ['kyc_verifications', 'driver_id', 'drivers', false],
            ['surge_rules', 'city_id', 'cities', false],
            ['surge_rules', 'vehicle_class_id', 'vehicle_classes', true],
            ['surge_rules', 'created_by_admin_id', 'users', false],
            ['ratings', 'rated_by_user_id', 'users', false],
            ['ratings', 'rated_user_id', 'users', false],
            ['disputes', 'reported_by_user_id', 'users', false],
            ['disputes', 'resolved_by_admin_id', 'users', true],
            ['promo_redemptions', 'promo_code_id', 'promo_codes', false],
            ['promo_redemptions', 'user_id', 'users', false],
        ];

        foreach ($foreignKeys as [$childTable, $fkCol, $parentTable, $nullable]) {
            $newCol = "new_{$fkCol}";
            $nullStr = $nullable ? '' : ' NOT NULL';

            DB::statement("ALTER TABLE {$childTable} ADD COLUMN {$newCol} UUID{$nullStr}");
            DB::statement(
                "UPDATE {$childTable} SET {$newCol} = {$parentTable}.new_id
                 FROM {$parentTable}
                 WHERE {$childTable}.{$fkCol} = {$parentTable}.id",
            );
        }

        // ride_state_transitions.triggered_by_id is not constrained but needs type change
        DB::statement('ALTER TABLE ride_state_transitions ADD COLUMN new_triggered_by_id UUID');
        DB::statement(
            'UPDATE ride_state_transitions SET new_triggered_by_id = users.new_id
             FROM users
             WHERE ride_state_transitions.triggered_by_id = users.id',
        );

        // personal_access_tokens.tokenable_id is a morph (string type + bigint id)
        DB::statement('ALTER TABLE personal_access_tokens ADD COLUMN new_tokenable_id UUID');
        DB::statement(
            "UPDATE personal_access_tokens SET new_tokenable_id = users.new_id
             FROM users
             WHERE personal_access_tokens.tokenable_id = users.id::bigint
             AND personal_access_tokens.tokenable_type = 'App\\Models\\User'",
        );

        // Phase 3: Drop all foreign key constraints
        $constraints = $this->getAllForeignKeyConstraints();
        foreach ($constraints as $constraint) {
            DB::statement("ALTER TABLE {$constraint['table']} DROP CONSTRAINT IF EXISTS {$constraint['name']}");
        }

        // Phase 4: Drop old columns and rename new ones
        foreach ($parentTables as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_pkey");
            DB::statement("ALTER TABLE {$table} DROP COLUMN id");
            DB::statement("ALTER TABLE {$table} RENAME COLUMN new_id TO id");
            DB::statement("ALTER TABLE {$table} ADD PRIMARY KEY (id)");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN id SET DEFAULT gen_random_uuid()");
        }

        foreach ($foreignKeys as [$childTable, $fkCol, $parentTable, $nullable]) {
            $newCol = "new_{$fkCol}";
            DB::statement("ALTER TABLE {$childTable} DROP COLUMN {$fkCol}");
            DB::statement("ALTER TABLE {$childTable} RENAME COLUMN {$newCol} TO {$fkCol}");
        }

        DB::statement('ALTER TABLE ride_state_transitions DROP COLUMN triggered_by_id');
        DB::statement('ALTER TABLE ride_state_transitions RENAME COLUMN new_triggered_by_id TO triggered_by_id');

        DB::statement('ALTER TABLE personal_access_tokens DROP COLUMN tokenable_id');
        DB::statement('ALTER TABLE personal_access_tokens RENAME COLUMN new_tokenable_id TO tokenable_id');

        // Phase 5: Re-add foreign key constraints
        $constraintDefs = [
            ['drivers', 'user_id', 'users', 'id', 'CASCADE'],
            ['drivers', 'city_id', 'cities', 'id', 'SET NULL'],
            ['driver_documents', 'driver_id', 'drivers', 'id', 'CASCADE'],
            ['driver_documents', 'reviewed_by', 'users', 'id', 'SET NULL'],
            ['vehicles', 'driver_id', 'drivers', 'id', 'CASCADE'],
            ['vehicles', 'vehicle_class_id', 'vehicle_classes', 'id', 'SET NULL'],
            ['social_accounts', 'user_id', 'users', 'id', 'CASCADE'],
            ['city_vehicle_classes', 'city_id', 'cities', 'id', 'CASCADE'],
            ['city_vehicle_classes', 'vehicle_class_id', 'vehicle_classes', 'id', 'CASCADE'],
            ['pricing_configs', 'city_id', 'cities', 'id', 'CASCADE'],
            ['pricing_configs', 'vehicle_class_id', 'vehicle_classes', 'id', 'CASCADE'],
            ['pricing_configs', 'created_by_admin_id', 'users', 'id', 'RESTRICT'],
            ['rides', 'city_id', 'cities', 'id', 'RESTRICT'],
            ['rides', 'vehicle_class_id', 'vehicle_classes', 'id', 'RESTRICT'],
            ['rides', 'passenger_id', 'users', 'id', 'RESTRICT'],
            ['rides', 'driver_id', 'users', 'id', 'RESTRICT'],
            ['rides', 'cancelled_by', 'users', 'id', 'RESTRICT'],
            ['user_payment_methods', 'user_id', 'users', 'id', 'CASCADE'],
            ['notifications', 'user_id', 'users', 'id', 'CASCADE'],
            ['device_tokens', 'user_id', 'users', 'id', 'CASCADE'],
            ['audit_logs', 'actor_id', 'users', 'id', 'SET NULL'],
            ['admin_invitations', 'invited_by', 'users', 'id', 'CASCADE'],
            ['kyc_verifications', 'driver_id', 'drivers', 'id', 'CASCADE'],
            ['surge_rules', 'city_id', 'cities', 'id', 'CASCADE'],
            ['surge_rules', 'vehicle_class_id', 'vehicle_classes', 'id', 'SET NULL'],
            ['surge_rules', 'created_by_admin_id', 'users', 'id', 'RESTRICT'],
            ['ratings', 'rated_by_user_id', 'users', 'id', 'RESTRICT'],
            ['ratings', 'rated_user_id', 'users', 'id', 'RESTRICT'],
            ['disputes', 'reported_by_user_id', 'users', 'id', 'RESTRICT'],
            ['disputes', 'resolved_by_admin_id', 'users', 'id', 'RESTRICT'],
            ['promo_redemptions', 'promo_code_id', 'promo_codes', 'id', 'CASCADE'],
            ['promo_redemptions', 'user_id', 'users', 'id', 'CASCADE'],
        ];

        foreach ($constraintDefs as [$table, $col, $refTable, $refCol, $onDelete]) {
            $constraintName = "{$table}_{$col}_foreign";
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$constraintName}
                 FOREIGN KEY ({$col}) REFERENCES {$refTable}({$refCol}) ON DELETE {$onDelete}",
            );
        }

        // Re-add rides FK constraints (ride_id references were not changed)
        DB::statement(
            'ALTER TABLE ride_state_transitions ADD CONSTRAINT ride_state_transitions_ride_id_foreign
             FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE',
        );
        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_ride_id_foreign
             FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE',
        );
        DB::statement(
            'ALTER TABLE ratings ADD CONSTRAINT ratings_ride_id_foreign
             FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE',
        );
        DB::statement(
            'ALTER TABLE disputes ADD CONSTRAINT disputes_ride_id_foreign
             FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE',
        );
        DB::statement(
            'ALTER TABLE promo_redemptions ADD CONSTRAINT promo_redemptions_ride_id_foreign
             FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE',
        );

        // Re-add unique constraints that were on FK columns
        DB::statement('ALTER TABLE drivers ADD CONSTRAINT drivers_user_id_unique UNIQUE (user_id)');
        DB::statement('ALTER TABLE city_vehicle_classes ADD CONSTRAINT city_vehicle_classes_city_id_vehicle_class_id_unique UNIQUE (city_id, vehicle_class_id)');

        // Re-add indexes on FK columns
        $indexes = [
            ['drivers', 'city_id'],
            ['driver_documents', 'driver_id'],
            ['driver_documents', 'reviewed_by'],
            ['vehicles', 'driver_id'],
            ['vehicles', 'vehicle_class_id'],
            ['social_accounts', 'user_id'],
            ['pricing_configs', 'city_id'],
            ['pricing_configs', 'vehicle_class_id'],
            ['user_payment_methods', 'user_id'],
            ['notifications', 'user_id'],
            ['device_tokens', 'user_id'],
            ['audit_logs', 'actor_id'],
            ['admin_invitations', 'invited_by'],
            ['kyc_verifications', 'driver_id'],
            ['surge_rules', 'city_id'],
            ['surge_rules', 'vehicle_class_id'],
            ['personal_access_tokens', 'tokenable_id'],
        ];

        foreach ($indexes as [$table, $col]) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$table}_{$col}_index ON {$table} ({$col})");
        }

        // Re-add composite indexes
        DB::statement('CREATE INDEX IF NOT EXISTS driver_documents_driver_id_type_index ON driver_documents (driver_id, type)');
        DB::statement('CREATE INDEX IF NOT EXISTS kyc_verifications_driver_id_type_index ON kyc_verifications (driver_id, type)');
        DB::statement('CREATE INDEX IF NOT EXISTS notifications_user_id_is_read_index ON notifications (user_id, is_read)');
        DB::statement('CREATE INDEX IF NOT EXISTS notifications_user_id_created_at_index ON notifications (user_id, created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS rides_passenger_id_created_at_index ON rides (passenger_id, created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS rides_driver_id_created_at_index ON rides (driver_id, created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS personal_access_tokens_tokenable_type_tokenable_id_index ON personal_access_tokens (tokenable_type, tokenable_id)');
    }

    public function down(): void
    {
        // UUID to bigint reversal is not practical — re-run all migrations from scratch
        throw new RuntimeException('This migration cannot be reversed. Use php artisan migrate:fresh instead.');
    }

    /**
     * @return array<int, array{table: string, name: string}>
     */
    private function getAllForeignKeyConstraints(): array
    {
        $rows = DB::select("
            SELECT tc.table_name AS \"table\", tc.constraint_name AS name
            FROM information_schema.table_constraints tc
            WHERE tc.constraint_type = 'FOREIGN KEY'
            AND tc.table_schema = 'public'
            ORDER BY tc.table_name
        ");

        return array_map(fn ($row) => ['table' => $row->table, 'name' => $row->name], $rows);
    }
};
