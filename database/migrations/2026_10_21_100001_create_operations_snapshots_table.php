<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Point-in-time counts per city, taken every few minutes, so the
        // admin overview can compare "now" with "this time yesterday".
        Schema::create('operations_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamp('captured_at');
            $table->foreignUuid('city_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('active_rides')->default(0);
            $table->unsignedInteger('drivers_online')->default(0);
            $table->unsignedInteger('drivers_on_trip')->default(0);

            $table->index('captured_at');
        });

        // The overview totals today's fares by completion time.
        Schema::table('rides', function (Blueprint $table) {
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex(['completed_at']);
        });

        Schema::dropIfExists('operations_snapshots');
    }
};
