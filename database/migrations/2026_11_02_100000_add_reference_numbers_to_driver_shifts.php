<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A readable reference for each shift ("SH-000123"), drawn from a
     * sequence like fleet agreements and payouts. Existing shifts are
     * numbered in the order they were created.
     */
    public function up(): void
    {
        Schema::table('driver_shifts', function (Blueprint $table) {
            $table->string('reference_number')->nullable()->unique()->after('id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            UPDATE driver_shifts AS t
            SET reference_number = 'SH-' || LPAD(numbered.n::text, 6, '0')
            FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY created_at, id) AS n FROM driver_shifts) AS numbered
            WHERE t.id = numbered.id
        ");

        $last = (int) DB::table('driver_shifts')->count();

        DB::statement('CREATE SEQUENCE IF NOT EXISTS driver_shifts_reference_number_seq OWNED BY driver_shifts.reference_number');
        DB::select('SELECT setval(?, ?, ?)', ['driver_shifts_reference_number_seq', max($last, 1), $last > 0]);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP SEQUENCE IF EXISTS driver_shifts_reference_number_seq');
        }

        Schema::table('driver_shifts', function (Blueprint $table) {
            $table->dropUnique(['reference_number']);
            $table->dropColumn('reference_number');
        });
    }
};
